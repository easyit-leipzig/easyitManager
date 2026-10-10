<?php
declare(strict_types=1);
require_once __DIR__.'/system/DataFormRecordSetEventRepository.php';
// Historical marker: DataForm Workspace · HF36

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once __DIR__ . '/system/RelationManager.php';
require_once __DIR__ . '/system/DataFormRecordStore.php';
require_once __DIR__ . '/system/DerivedMultiEnumManager.php';
require_once __DIR__ . '/system/DataFormManager.php';
require_once __DIR__ . '/system/DataFormFieldTypes.php';
require_once __DIR__ . '/system/DataFormTransport.php';
require_once __DIR__ . '/system/DataFormActionContext.php';
require_once dirname(__DIR__, 2) . '/system/licensing/Autoload.php';

$user = enterprise_require_auth('../../');
$preProjectId=(int)($_GET['project'] ?? $_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0));
$preAdminPdo=enterprise_pdo(); enterprise_upgrade($preAdminPdo);
$preSt=$preAdminPdo->prepare('SELECT product_type FROM projects WHERE id=?');$preSt->execute([$preProjectId]);
$preProduct=(string)($preSt->fetchColumn()?:'');
if($preProduct==='calendar') enterprise_require_customer_module($user,'calendar');
else enterprise_require_customer_module($user,'dataform');
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0));
enterprise_require_project_scope($user,$projectId,false);
$dataformId = (int)($_GET['dataform'] ?? $_POST['dataform'] ?? 0);

// HF105: Calendar nutzt DataForm als interne Formular-/CRUD-Schicht unter der Calendar-Lizenz.
// Nur eigenständige DataForm-Projekte benötigen den separat signierten DataForm-Runtime-Token.
if($preProduct==='calendar'){
    $easyitDataFormManifest=['runtime'=>['mode'=>'calendar-internal'],'capabilities'=>[
        'dataform.search','dataform.filter','dataform.pagination','dataform.events',
        'dataform.custom_css','dataform.custom_javascript','dataset.read','dataset.create',
        'dataset.update','dataset.delete','relation.read','relation.createChild'
    ]];
}else{
    $easyitDataFormManifest = \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::boot(dirname(__DIR__, 2), $projectId, $dataformId);
}
$recordId = (int)($_GET['record'] ?? $_POST['record'] ?? 0);
$activeRecordId = max(0, (int)($_GET['active_record'] ?? $_POST['active_record'] ?? 0)); // HF61: tabellarischer Datensatzzeiger bleibt auch bei Inline-Speichern aktiv
$modeExplicit=isset($_GET['mode'])||isset($_POST['mode']);
$mode = preg_replace('/[^a-z]/', '', (string)($_GET['mode'] ?? $_POST['mode'] ?? 'list')) ?: 'list';
$runtimeViewOverride = strtolower(trim((string)($_GET['runtime_view'] ?? $_POST['runtime_view'] ?? '')));
if (!in_array($runtimeViewOverride, ['table','form','dialog'], true)) $runtimeViewOverride='';
$embedMode = (int)($_GET['embed'] ?? $_POST['embed'] ?? 0) === 1;
$dialogEmbedMode = $embedMode && (int)($_GET['dialog_embed'] ?? $_POST['dialog_embed'] ?? 0) === 1;
$previewMode = $embedMode && (int)($_GET['preview'] ?? $_POST['preview'] ?? 0) === 1;
$previewDepth=max(0,min(3,(int)($_GET['preview_depth'] ?? $_POST['preview_depth'] ?? 0)));
$childPreviewMode=$previewMode && (int)($_GET['child_preview'] ?? $_POST['child_preview'] ?? 0)===1;
$parentRelationContextId=(int)($_GET['parent_relation'] ?? $_POST['parent_relation'] ?? 0);
$parentRecordContextId=(int)($_GET['parent_record'] ?? $_POST['parent_record'] ?? 0);
$returnDataformId=(int)($_GET['return_dataform'] ?? $_POST['return_dataform'] ?? 0);
$returnRecordId=(int)($_GET['return_record'] ?? $_POST['return_record'] ?? 0);
$masterContext=null;
$childCollections=[];
$previewChildRelations=[];
$previewParentFilter=null;
$recordStorageMode='generic';
$error = '';
$success = '';
$project = $dataform = null;
$fields = $records = [];
$record = null;
$derivedOptionsByField = [];
$derivedMissingByField = [];
$inlineCreateValues = []; // HF58: Werte der editierbaren Neuzeile in der Tabellenansicht
if (($mode ?? '')==='create' || (string)($_GET['mode']??'')==='create') {
    foreach($_GET as $k=>$v) if(str_starts_with((string)$k,'default_') && is_scalar($v)) {
        $inlineCreateValues[substr((string)$k,8)]=(string)$v;
    }
}
$inlineCreateAttempt = false;
$inlineEditAttempt = false; // HF61: bestehende Datensaetze direkt in der Tabellenzeile bearbeiten
$inlineCreatedRecordId = 0; // HF76-FIX16: frisch inline erzeugten DS auf letzter Seite als aktuell halten
$inlineEditValuesByRecord = []; // HF61: fehlerhafte Eingaben zeilenweise erhalten
$sort = (string)($_GET['sort'] ?? 'id'); // HF59: immer definiert, auch nach Validierungsfehlern
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$tableSaveMode='manual'; // HF62: manual | adhoc
$showSaveSuccess=true; // HF62: Speichern-Erfolgsmeldung je DataForm
$saveSuccessDialog=''; // HF65: positive Record-Speichermeldungen als modales JavaScript-Dialogfenster
$formViewRecordIds=[];
$formViewFirstId=$formViewPrevId=$formViewNextId=$formViewLastId=0;
$dialogInitialRecordId=0;
$dataformActionContext=null; // PUBLISH17: kanonisches Uebergabeobjekt fuer after_save

function df_record_value(array $data, string $name): mixed { return $data[$name] ?? ''; }

function df_child_record_count(
    PDO $pdo,
    int $parentDataformId,
    int $parentRecordId
): int {
    $relationTableExists=(int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name='dataform_relations'"
    )->fetchColumn()===1;

    if (!$relationTableExists) {
        return 0;
    }

    $stmt=$pdo->prepare(
        "SELECT target_dataform_id,lookup_field_id
         FROM dataform_relations
         WHERE source_dataform_id=?
           AND relation_type='1:n'
           AND is_enabled=1
           AND lookup_field_id IS NOT NULL"
    );
    $stmt->execute([$parentDataformId]);

    $count=0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $relation) {
        $childId=(int)$relation['target_dataform_id'];
        $lookupId=(int)$relation['lookup_field_id'];

        $fieldStmt=$pdo->prepare(
            'SELECT name FROM dataform_fields
             WHERE id=? AND dataform_id=?
             LIMIT 1'
        );
        $fieldStmt->execute([$lookupId,$childId]);
        $lookupName=$fieldStmt->fetchColumn();
        if ($lookupName===false) {
            continue;
        }

        foreach(
            DataFormRecordStore::all($pdo,$childId)
            as $childRecord
        ) {
            if (
                (string)(
                    $childRecord['data'][(string)$lookupName]??''
                )===(string)$parentRecordId
            ) {
                $count++;
            }
        }
    }

    $lookupStmt=$pdo->prepare(
        "SELECT source_dataform_id,source_field_id,configuration_json
         FROM dataform_relations
         WHERE target_dataform_id=?
           AND relation_type='n:1'
           AND is_enabled=1
           AND source_field_id IS NOT NULL"
    );
    $lookupStmt->execute([$parentDataformId]);
    foreach ($lookupStmt->fetchAll(PDO::FETCH_ASSOC) as $relation) {
        if (RelationManager::isBaseTableLookup($relation)) {
            continue;
        }
        $sourceId=(int)$relation['source_dataform_id'];
        $sourceFieldId=(int)$relation['source_field_id'];
        $fieldStmt=$pdo->prepare(
            'SELECT name FROM dataform_fields
             WHERE id=? AND dataform_id=?
             LIMIT 1'
        );
        $fieldStmt->execute([$sourceFieldId,$sourceId]);
        $fieldName=$fieldStmt->fetchColumn();
        if ($fieldName===false) {
            continue;
        }
        foreach (DataFormRecordStore::all($pdo,$sourceId) as $sourceRecord) {
            if ((string)($sourceRecord['data'][(string)$fieldName]??'')===(string)$parentRecordId) {
                $count++;
            }
        }
    }

    return $count;
}
function df_field_config(mixed $raw,string $fieldType='text'): array {
    $cfg=[];
    if (is_string($raw) && trim($raw)!=='') { $d=json_decode($raw,true); $cfg=is_array($d)?$d:[]; }
    elseif (is_array($raw)) $cfg=$raw;
    foreach (['placeholder','help_text','default_value','default_js_provider','pattern','min_value','max_value','step'] as $key) $cfg[$key]=isset($cfg[$key])&&is_scalar($cfg[$key])?(string)$cfg[$key]:'';
    $defaultMode=isset($cfg['default_mode'])&&is_scalar($cfg['default_mode'])?(string)$cfg['default_mode']:'defined';
    $cfg['default_mode']=in_array($defaultMode,['defined','current_timestamp','javascript','parent_field'],true)?$defaultMode:'defined';
    $cfg['parent_relation_id']=isset($cfg['parent_relation_id'])?max(0,(int)$cfg['parent_relation_id']):0;
    $cfg['parent_field_id']=isset($cfg['parent_field_id'])?max(0,(int)$cfg['parent_field_id']):0;
    $width=isset($cfg['width'])&&is_scalar($cfg['width'])?(string)$cfg['width']:'100';
    $cfg['width']=in_array($width,['25','33','50','66','75','100'],true)?$width:'100';
    $options=$cfg['options']??[]; if(is_string($options))$options=preg_split('/\R+/',$options)?:[]; if(!is_array($options))$options=[];
    $cfg['options']=array_values(array_filter(array_map(static fn($v):string=>is_scalar($v)?trim((string)$v):'',$options),static fn(string $v):bool=>$v!==''));
    foreach(['min_length','max_length'] as $key)$cfg[$key]=(!isset($cfg[$key])||$cfg[$key]===''||$cfg[$key]===null)?null:max(0,(int)$cfg[$key]);
    $cfg['rows']=max(2,min(30,(int)($cfg['rows']??3)));
    foreach(['readonly'=>false,'hidden'=>false,'trim'=>true,'list_visible'=>true,'searchable'=>true,'filterable'=>true,'sortable'=>true] as $key=>$default)$cfg[$key]=array_key_exists($key,$cfg)?(bool)$cfg[$key]:$default;
    $cfg['autocomplete']=isset($cfg['autocomplete'])&&is_scalar($cfg['autocomplete'])?(string)$cfg['autocomplete']:'';
    $cfg['inputmode']=isset($cfg['inputmode'])&&is_scalar($cfg['inputmode'])?(string)$cfg['inputmode']:'';
    $cfg=DerivedMultiEnumManager::normalizeConfig($cfg);
    return DataFormFieldTypeRegistry::normalizeConfiguration($cfg,$fieldType);
}

function df_media_manager(): DataFormFieldStorageManager {
    static $manager=null;
    if (!$manager instanceof DataFormFieldStorageManager) {
        $manager=new DataFormFieldStorageManager(dirname(__DIR__,2));
    }
    return $manager;
}

function df_media_url(int $projectId,int $dataformId,int $recordId,int $fieldId,bool $download=false): string {
    return 'media.php?'.http_build_query([
        'project'=>$projectId,
        'dataform'=>$dataformId,
        'record'=>$recordId,
        'field'=>$fieldId,
        'download'=>$download?1:0,
    ]);
}

function df_render_record_value_html(PDO $pdo,array $field,mixed $value,int $projectId,int $dataformId,int $recordId): string {
    $type=(string)($field['field_type']??'text');
    $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
    $raw=is_scalar($value)?(string)$value:'';
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if ($raw==='') return '<span class="muted">—</span>';
    if (DataFormFieldTypeRegistry::isMedia($type)) {
        $meta=DataFormFieldStorageManager::descriptor($raw);
        if ($meta===null) return $esc(DataFormFieldTypeRegistry::displayText($type,$raw,$cfg));
        $url=df_media_url($projectId,$dataformId,$recordId,(int)($field['id']??0),false);
        $download=df_media_url($projectId,$dataformId,$recordId,(int)($field['id']??0),true);
        $name=$esc((string)($meta['name']??'Datei'));
        $caption=$esc(DataFormFieldTypeRegistry::displayText($type,$raw,$cfg));
        $mime=$esc((string)($meta['mime']??'application/octet-stream'));
        $storage=(string)($meta['storage']??'');
        $storageLabel=$storage==='database'?'Datenbank':'Dateisystem';
        $dimension='';
        if ($type==='image' && isset($meta['width'],$meta['height'])) $dimension=' · '.(int)$meta['width'].' × '.(int)$meta['height'].' px';
        $metaLine=$mime.' · '.$esc($storageLabel).$esc($dimension);
        if ($type==='image' && DataFormFieldStorageManager::inlinePreviewAllowed($type,(string)($meta['mime']??''),$cfg)) {
            return '<div class="df-media-detail"><a href="'.$esc($url).'" target="_blank" rel="noopener"><img class="df-image-preview" src="'.$esc($url).'" alt="'.$name.'"></a><div><strong>'.$name.'</strong><br><span class="muted">'.$caption.'</span><br><small class="muted">'.$metaLine.'</small><br><a href="'.$esc($url).'" target="_blank" rel="noopener">Vorschau öffnen</a> · <a href="'.$esc($download).'">Herunterladen</a></div></div>';
        }
        return '<div class="df-media-file"><strong>'.$name.'</strong><br><span class="muted">'.$caption.'</span><br><small class="muted">'.$metaLine.'</small><br><a class="df-file-link" href="'.$esc($download).'">Herunterladen</a></div>';
    }
    if ($type==='link') {
        $m=DataFormFieldTypeRegistry::decodeObject($raw);
        $url=(string)($m['url']??''); $label=trim((string)($m['label']??'')); $target=(string)($m['target']??'_self');
        if ($url!=='' && filter_var($url,FILTER_VALIDATE_URL)!==false) {
            return '<a href="'.$esc($url).'" target="'.$esc($target).'"'.($target==='_blank'?' rel="noopener noreferrer"':'').'>'.$esc($label!==''?$label:$url).'</a>';
        }
    }
    if ($type==='url' && filter_var($raw,FILTER_VALIDATE_URL)!==false) return '<a href="'.$esc($raw).'" target="_blank" rel="noopener noreferrer">'.$esc($raw).'</a>';
    if ($type==='email' && filter_var($raw,FILTER_VALIDATE_EMAIL)!==false) return '<a href="mailto:'.$esc($raw).'">'.$esc($raw).'</a>';
    if ($type==='richtext') return DataFormFieldTypeRegistry::sanitizeRichTextForDisplay($raw);
    if ($type==='markdown') {
        $safe=$esc($raw);
        $safe=preg_replace('/\*\*(.+?)\*\*/s','<strong>$1</strong>',$safe)??$safe;
        $safe=preg_replace('/`([^`]+)`/','<code>$1</code>',$safe)??$safe;
        return nl2br($safe);
    }
    if ($type==='json') return '<pre class="df-json-output">'.$esc(DataFormFieldTypeRegistry::displayText($type,$raw,$cfg)).'</pre>';
    if ($type==='color' && preg_match('/^#[0-9a-f]{6}$/i',$raw)===1) return '<span class="df-color-output"><span style="background:'.$esc($raw).'"></span>'.$esc($raw).'</span>';
    return nl2br($esc(df_display_record_value($pdo,$field,$value)));
}

function df_derived_options_for_fields(
    PDO $pdo,
    array $fields,
    int $dataformId,
    int $recordId,
    int $parentRecordId,
    array $currentData
): array {
    $options=[];
    foreach ($fields as $field) {
        if ((string)($field['field_type']??'') !== DerivedMultiEnumManager::FIELD_TYPE) {
            continue;
        }
        $cfg=$field['_config']??df_field_config($field['configuration_json']??null,(string)($field['field_type']??'text'));
        $options[(string)$field['name']]=DerivedMultiEnumManager::options(
            $pdo,
            $cfg,
            [
                'dataform_id'=>$dataformId,
                'record_id'=>$recordId,
                'parent_record_id'=>$parentRecordId,
                'current_data'=>$currentData,
            ]
        );
    }
    return $options;
}

function df_effective_parent_record_id(
    array $fields,
    array $parentRelationsByLookupFieldId,
    array $currentData,
    ?array $masterContext
): int {
    if ($masterContext !== null) {
        return (int)($masterContext['parent_record_id']??0);
    }

    foreach ($fields as $field) {
        $fieldId=(int)($field['id']??0);
        if (!isset($parentRelationsByLookupFieldId[$fieldId])) {
            continue;
        }
        $name=(string)($field['name']??'');
        $value=(int)($currentData[$name]??0);
        if ($value>0) {
            return $value;
        }
    }
    return 0;
}

function df_display_record_value(
    PDO $pdo,
    array $field,
    mixed $value
): string {
    $type=(string)($field['field_type']??'text');
    $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
    if (DataFormFieldTypeRegistry::isBoolean($type)) {
        return (string)$value==='1'?'Ja':'Nein';
    }
    if ($type===DerivedMultiEnumManager::FIELD_TYPE) {
        static $cache=[];
        $raw=(string)$value;
        $key=hash(
            'sha256',
            json_encode($cfg['derived_multienum']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            .'|'.$raw
        );
        if (!array_key_exists($key,$cache)) {
            try {
                $cache[$key]=DerivedMultiEnumManager::displayText(
                    $pdo,
                    $cfg,
                    $raw
                );
            } catch (Throwable) {
                $cache[$key]=$raw;
            }
        }
        return (string)$cache[$key];
    }

    if ((string)$value!=='' && (int)($field['id']??0)>0 && (int)($field['dataform_id']??0)>0) {
        static $lookupCache=[];
        $cacheKey=(int)$field['dataform_id'].':'.(int)$field['id'].':'.(string)$value;
        if (array_key_exists($cacheKey,$lookupCache)) {
            return (string)$lookupCache[$cacheKey];
        }

        try {
            $relationStmt=$pdo->prepare(
                "SELECT relation_type,source_dataform_id,target_dataform_id,target_display_field_id,configuration_json
                 FROM dataform_relations
                 WHERE is_enabled=1
                   AND (
                        (relation_type='1:n' AND target_dataform_id=? AND lookup_field_id=?)
                        OR
                        (relation_type='n:1' AND source_dataform_id=? AND source_field_id=?)
                   )
                 ORDER BY CASE relation_type WHEN 'n:1' THEN 0 ELSE 1 END,id
                 LIMIT 1"
            );
            $relationStmt->execute([
                (int)$field['dataform_id'],
                (int)$field['id'],
                (int)$field['dataform_id'],
                (int)$field['id'],
            ]);
            $relation=$relationStmt->fetch(PDO::FETCH_ASSOC);
            if ($relation && (string)($relation['relation_type']??'')==='n:1' && !RelationManager::rendersAsLookup($relation)) {
                $relation=null;
            }

            if ($relation) {
                if (
                    (string)$relation['relation_type']==='n:1'
                    && RelationManager::isBaseTableLookup($relation)
                ) {
                    $caption=RelationManager::baseTableLookupCaption(
                        $pdo,
                        $relation,
                        (string)$value
                    );
                    if ($caption!==null) {
                        $lookupCache[$cacheKey]=$caption;
                        return $caption;
                    }
                } else {
                    if ((string)$relation['relation_type']==='n:1') {
                        $caption=RelationManager::dataformLookupCaption($pdo,$relation,(string)$value);
                        if ($caption!==null) {
                            $lookupCache[$cacheKey]=$caption;
                            return $caption;
                        }
                    } else {
                        $lookupDataformId=(int)$relation['source_dataform_id'];
                        $lookupRecord=DataFormRecordStore::find($pdo,$lookupDataformId,(int)$value);
                        if ($lookupRecord!==null) {
                            $caption='';
                            $displayFieldId=(int)($relation['target_display_field_id']??0);
                            if ($displayFieldId>0) {
                                $displayStmt=$pdo->prepare('SELECT name FROM dataform_fields WHERE id=? AND dataform_id=? LIMIT 1');
                                $displayStmt->execute([$displayFieldId,$lookupDataformId]);
                                $displayName=$displayStmt->fetchColumn();
                                if ($displayName!==false) $caption=trim((string)($lookupRecord['data'][(string)$displayName]??''));
                            }
                            $lookupCache[$cacheKey]=$caption!==''?$caption:'#'.(int)$lookupRecord['id'];
                            return (string)$lookupCache[$cacheKey];
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Keep the stored value visible if the relation is temporarily unavailable.
        }
        $lookupCache[$cacheKey]=(string)$value;
    }

    return DataFormFieldTypeRegistry::displayText($type,$value,$cfg);
}

/**
 * HF55: Return the active relation that turns a stored scalar field into a
 * relation/lookup control in record views. The result is cached per field so
 * table rendering does not issue a relation query for every record row.
 */
function df_record_list_lookup_relation(PDO $pdo, array $field): ?array {
    static $cache=[];
    $dataformId=(int)($field['dataform_id']??0);
    $fieldId=(int)($field['id']??0);
    if ($dataformId<=0 || $fieldId<=0) {
        return null;
    }
    $key=$dataformId.':'.$fieldId;
    if (array_key_exists($key,$cache)) {
        return $cache[$key];
    }
    try {
        $stmt=$pdo->prepare(
            "SELECT id,relation_type,source_dataform_id,target_dataform_id,lookup_field_id,source_field_id,target_display_field_id,configuration_json
"
            ."FROM dataform_relations
"
            ."WHERE is_enabled=1
"
            ."  AND (
"
            ."       (relation_type='1:n' AND target_dataform_id=? AND lookup_field_id=?)
"
            ."       OR
"
            ."       (relation_type='n:1' AND source_dataform_id=? AND source_field_id=?)
"
            ."  )
"
            ."ORDER BY CASE relation_type WHEN 'n:1' THEN 0 ELSE 1 END,id
"
            ."LIMIT 1"
        );
        $stmt->execute([$dataformId,$fieldId,$dataformId,$fieldId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (string)($row['relation_type']??'')==='n:1' && !RelationManager::rendersAsLookup($row)) {
            $row=false;
        }
        $cache[$key]=$row?:null;
    } catch (Throwable) {
        $cache[$key]=null;
    }
    return $cache[$key];
}

/**
 * HF55: Render values in the tabular record list using the visual output form
 * of the field instead of flattening every value to plain text.
 *
 * The controls are deliberately read-only/disabled and have no name attribute,
 * so the surrounding bulk-action form can never submit record field values.
 */
function df_record_list_control(PDO $pdo, array $field, mixed $value): string {
    $type=(string)($field['field_type']??'text');
    $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
    $raw=is_scalar($value)?(string)$value:'';
    $display=df_display_record_value($pdo,$field,$value);
    $label=(string)($field['label']??$field['name']??'Feld');
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $aria=$esc($label);

    // Relationsfelder bleiben unabhängig vom physischen Datentyp als Lookup sichtbar.
    if (df_record_list_lookup_relation($pdo,$field)!==null) {
        $option=$display!==''?$display:'—';
        return '<select class="df-list-output-control df-list-output-select" disabled aria-label="'.$aria.'"><option selected>'.$esc($option).'</option></select>';
    }

    if (DataFormFieldTypeRegistry::isBoolean($type)) {
        $checked=in_array(DataFormFieldTypeRegistry::lower(trim($raw)),['1','true','yes','ja','on'],true)?' checked':'';
        return '<span class="df-list-output-checkbox"><input type="checkbox" disabled aria-label="'.$aria.'"'.$checked.'><span>'.($checked!==''?'Ja':'Nein').'</span></span>';
    }

    if ($type==='select') {
        $option=$display!==''?$display:'—';
        return '<select class="df-list-output-control df-list-output-select" disabled aria-label="'.$aria.'"><option selected>'.$esc($option).'</option></select>';
    }

    if (in_array($type,['textarea','richtext','markdown','json','multiselect','tags','multi_lookup',DerivedMultiEnumManager::FIELD_TYPE],true)) {
        $text=$type==='richtext'?strip_tags($display):$display;
        $rows=$type==='json'?4:max(2,min(4,(int)($cfg['rows']??2)));
        return '<textarea class="df-list-output-control df-list-output-textarea" rows="'.$rows.'" readonly aria-label="'.$aria.'">'.$esc($text).'</textarea>';
    }

    if (DataFormFieldTypeRegistry::isMedia($type)) {
        return '<input class="df-list-output-control" type="text" value="'.$esc($display!==''?$display:'—').'" readonly aria-label="'.$aria.'">';
    }

    if ($type==='color' && preg_match('/^#[0-9a-f]{6}$/i',$raw)===1) {
        return '<span class="df-list-color"><input class="df-list-output-control" type="color" value="'.$esc($raw).'" disabled aria-label="'.$aria.'"><code>'.$esc($raw).'</code></span>';
    }

    $htmlType=match($type){
        'integer'=>'number',
        'number'=>'number',
        'decimal'=>'number',
        'currency'=>'number',
        'percentage'=>'number',
        'date'=>'date',
        'time'=>'time',
        'datetime'=>'datetime-local',
        'email'=>'email',
        'url'=>'url',
        default=>'text',
    };
    $controlValue=in_array($htmlType,['number','date','time','datetime-local'],true)?$raw:$display;
    if ($type==='datetime' && $controlValue!=='') $controlValue=str_replace(' ','T',$controlValue);
    if ($type==='time' && strlen($controlValue)>=5) $controlValue=substr($controlValue,0,5);
    return '<input class="df-list-output-control" type="'.$htmlType.'" value="'.$esc($controlValue).'" readonly aria-label="'.$aria.'">';
}

/**
 * HF58: Editierbares Eingabefeld für die feste Neuzeile der Tabellenansicht.
 * Die Controls gehören über das HTML-form-Attribut zu einem separaten
 * save_record-Formular und bleiben dadurch vom Bulk-Delete-Formular getrennt.
 */
// HF76 relation compatibility: legacy numeric FK rendering used elseif(isset($parentRelationsByLookupFieldId[$fieldId])).
function df_record_inline_create_control(
    PDO $pdo,
    array $field,
    array $parentRelationsByLookupFieldId,
    array $parentRecordOptionsByLookupFieldId,
    array $derivedOptionsByField,
    string $formId,
    array $postedValues=[],
    string $controlClass='df-inline-create-control',
    ?array $masterContext=null
): string {
    $type=(string)($field['field_type']??'text');
    $name=(string)($field['name']??'');
    $label=(string)($field['label']??$name);
    $fieldId=(int)($field['id']??0);
    $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
    $required=(int)($field['is_required']??0)===1;
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $nameAttr='values['.$name.']';
    $posted=array_key_exists($name,$postedValues);
    $postedValue=$posted?$postedValues[$name]:df_server_default_value($field,$cfg);
    $scalarValue=is_scalar($postedValue)?(string)$postedValue:'';
    $requiredAttr=$required?' required':'';
    $formAttr=' form="'.$esc($formId).'"';
    $aria=' aria-label="'.$esc($label).'"';
    $safeClass=preg_replace('/[^a-zA-Z0-9_\- ]/','',$controlClass) ?: 'df-inline-create-control';
    $common=' class="'.$esc($safeClass).'"'.$formAttr.$aria;

    if (DataFormFieldTypeRegistry::isBoolean($type)) {
        $checked=$posted ? isset($postedValues[$name]) : in_array(strtolower(trim($scalarValue)),['1','true','yes','ja','on'],true);
        return '<input type="checkbox" name="'.$esc($nameAttr).'" value="1"'.$common.($checked?' checked':'').(!empty($cfg['readonly'])?' disabled':'').'>';
    }

    $relation=$parentRelationsByLookupFieldId[$fieldId]??null;
    if ($relation!==null) {
        // PUBLISH18: In einem echten Elternkontext ist das Kind-FK kein freies
        // Lookup mehr. Besonders die *-Neuzeile muss unmittelbar die ID des
        // aktuellen Eltern-Datensatzes zeigen (z. B. #9201) und darf nicht mit
        // "Eltern-Datensatz wählen" beginnen.
        $isMasterBound=$masterContext!==null
            && (int)($masterContext['lookup_field_id']??0)===$fieldId;
        if ($isMasterBound) {
            $parentId=(string)(int)($masterContext['parent_record_id']??0);
            $boundReadonly=!array_key_exists('bound_field_readonly',$masterContext)
                || !empty($masterContext['bound_field_readonly']);
            if ($boundReadonly || !$posted) {
                $scalarValue=$parentId;
            }
            $html='';
            if ($boundReadonly) {
                $html.='<input type="hidden" name="'.$esc($nameAttr).'" value="'.$esc($parentId).'"'.$formAttr.'>';
            }
            $masterClass=trim($safeClass.' df-master-preselected');
            $masterCommon=' class="'.$esc($masterClass).'"'.$formAttr.$aria
                .' data-bound-parent-id="'.$esc($parentId).'"';
            $html.='<select name="'.$esc($nameAttr).'"'.$masterCommon.$requiredAttr.($boundReadonly?' disabled':'').'>';
            if (!$boundReadonly) {
                $html.='<option value="">Eltern-Datensatz wählen</option>';
            }
            $foundParent=false;
            foreach (($parentRecordOptionsByLookupFieldId[$fieldId]??[]) as $option) {
                $id=(string)($option['id']??'');
                $caption=(string)($option['caption']??$id);
                if ($id===$parentId) {
                    $caption='#'.$parentId;
                    $foundParent=true;
                }
                $html.='<option value="'.$esc($id).'"'.($scalarValue===$id?' selected':'').'>'.$esc($caption).'</option>';
            }
            if (!$foundParent && $parentId!=='0') {
                $html.='<option value="'.$esc($parentId).'"'.($scalarValue===$parentId?' selected':'').'>#'.$esc($parentId).'</option>';
            }
            return $html.'</select>';
        }

        $html='';
        if (!empty($cfg['readonly'])) $html.='<input type="hidden" name="'.$esc($nameAttr).'" value="'.$esc($scalarValue).'"'.$formAttr.'>';
        $html.='<select name="'.$esc($nameAttr).'"'.$common.$requiredAttr.(!empty($cfg['readonly'])?' disabled':'').'>';
        $html.='<option value="">'.(((string)($relation['relation_type']??'1:n')==='n:1')?'Lookup-Datensatz wählen':'Eltern-Datensatz wählen').'</option>';
        foreach (($parentRecordOptionsByLookupFieldId[$fieldId]??[]) as $option) {
            $id=(string)($option['id']??''); $caption=(string)($option['caption']??$id);
            $html.='<option value="'.$esc($id).'"'.($scalarValue===$id?' selected':'').'>'.$esc($caption).'</option>';
        }
        return $html.'</select>';
    }

    if (in_array($type,['select','multiselect','multi_lookup'],true)) {
        $multiple=in_array($type,['multiselect','multi_lookup'],true);
        $selected=$multiple ? DataFormFieldTypeRegistry::decodeArray($scalarValue) : [$scalarValue];
        if ($posted && is_array($postedValue)) $selected=array_values(array_map('strval',$postedValue));
        $html='<select name="'.$esc($nameAttr).($multiple?'[]':'').'"'.($multiple?' multiple size="3"':'').$common.$requiredAttr.(!empty($cfg['readonly'])?' disabled':'').'>';
        if (!$multiple) $html.='<option value="">Bitte wählen</option>';
        foreach ((array)($cfg['options']??[]) as $option) {
            $option=(string)$option;
            $html.='<option value="'.$esc($option).'"'.(in_array($option,$selected,true)?' selected':'').'>'.$esc($option).'</option>';
        }
        return $html.'</select>';
    }

    if ($type===DerivedMultiEnumManager::FIELD_TYPE) {
        $legacyDerivedTemplateContract='name="values[<?= e($name) ?>][]" multiple size="8"'; // static regression contract
        $selected=DerivedMultiEnumManager::canonicalizeSelection($posted?$postedValue:$scalarValue);
        $derivedCommon=str_replace('class="','class="df-derived-multiselect ', $common);
        $html='<select name="'.$esc($nameAttr).'[]" multiple size="8"'.$derivedCommon.$requiredAttr.(!empty($cfg['readonly'])?' disabled':'').'>';
        foreach (($derivedOptionsByField[$name]??[]) as $option) {
            $value=(string)($option['value']??''); $caption=(string)($option['label']??$value);
            $html.='<option value="'.$esc($value).'"'.(in_array($value,$selected,true)?' selected':'').'>'.$esc($caption).'</option>';
        }
        return $html.'</select>';
    }

    if ($type==='link') {
        $link=DataFormFieldTypeRegistry::decodeObject($scalarValue);
        if ($posted && is_array($postedValue)) $link=$postedValue;
        $target=(string)($link['target']??DataFormFieldTypeRegistry::settings('link',$cfg)['target']??'_self');
        return '<span class="df-inline-link">'
            .'<input type="url" name="'.$esc($nameAttr).'[url]" value="'.$esc((string)($link['url']??'')).'" placeholder="https://…"'.$common.$requiredAttr.'>'
            .'<input type="text" name="'.$esc($nameAttr).'[label]" value="'.$esc((string)($link['label']??'')).'" placeholder="Linktext"'.$common.'>'
            .'<select name="'.$esc($nameAttr).'[target]"'.$common.'><option value="_self"'.($target==='_self'?' selected':'').'>gleiches Fenster</option><option value="_blank"'.($target==='_blank'?' selected':'').'>neues Fenster</option></select>'
            .'</span>';
    }
    if ($type==='coordinates') {
        $coords=DataFormFieldTypeRegistry::decodeObject($scalarValue);
        if ($posted && is_array($postedValue)) $coords=$postedValue;
        return '<span class="df-inline-coordinates">'
            .'<input type="number" step="any" min="-90" max="90" name="'.$esc($nameAttr).'[lat]" value="'.$esc((string)($coords['lat']??'')).'" placeholder="Breite"'.$common.$requiredAttr.'>'
            .'<input type="number" step="any" min="-180" max="180" name="'.$esc($nameAttr).'[lng]" value="'.$esc((string)($coords['lng']??'')).'" placeholder="Länge"'.$common.$requiredAttr.'>'
            .'</span>';
    }
    if (DataFormFieldTypeRegistry::isMedia($type)) {
        $settings=DataFormFieldTypeRegistry::settings($type,$cfg);
        $descriptor=DataFormFieldStorageManager::descriptor($scalarValue);
        $current=$descriptor!==null?'<small class="df-media-current">'.$esc((string)($descriptor['name']??'Datei')).'</small>':'';
        return '<span class="df-inline-media"><input type="file" name="'.$esc($nameAttr).'" accept="'.$esc((string)($settings['accept']??'')).'"'.$common.($required && $descriptor===null?' required':'').'>'.$current.'</span>';
    }
    if ($type==='computed') return '<input type="text" value="'.$esc($scalarValue).'"'.$common.' readonly>';
    if ($type==='hidden') return '<input type="hidden" name="'.$esc($nameAttr).'" value="'.$esc($scalarValue).'"'.$formAttr.'><span class="muted">versteckt</span>';
    if ($type==='password') return '<input type="password" name="'.$esc($nameAttr).'" value="" autocomplete="new-password" placeholder="'.($scalarValue!==''?'unverändert lassen':'Passwort').'"'.$common.($required && $scalarValue===''?' required':'').'>';
    if ($type==='tags') {
        $tagText=implode(', ',array_map('strval',DataFormFieldTypeRegistry::decodeArray($scalarValue)));
        if ($posted && is_scalar($postedValue)) $tagText=(string)$postedValue;
        return '<input type="text" name="'.$esc($nameAttr).'" value="'.$esc($tagText).'" placeholder="tag1, tag2"'.$common.$requiredAttr.'>';
    }
    if (DataFormFieldTypeRegistry::isTextarea($type)) return '<textarea name="'.$esc($nameAttr).'" rows="2"'.$common.$requiredAttr.(!empty($cfg['readonly'])?' readonly':'').' placeholder="'.$esc((string)($cfg['placeholder']??'')).'">'.$esc($scalarValue).'</textarea>';

    $htmlType=DataFormFieldTypeRegistry::htmlInputType($type);
    $controlValue=$scalarValue;
    if ($type==='datetime' && $controlValue!=='') $controlValue=str_replace(' ','T',$controlValue);
    if ($type==='time' && strlen($controlValue)>=5) $controlValue=substr($controlValue,0,5);
    $attrs=df_generated_attrs($cfg,$type);
    return '<input type="'.$htmlType.'" name="'.$esc($nameAttr).'" value="'.$esc($controlValue).'"'.$common.$requiredAttr.($attrs!==''?' '.$attrs:'').' placeholder="'.$esc((string)($cfg['placeholder']??'')).'">';
}

function df_server_default_value(array $field,array $cfg): string {
    $mode=(string)($cfg['default_mode']??'defined');
    $type=(string)($field['field_type']??'text');
    if($mode==='current_timestamp'){
        return match($type){
            'date'=>date('Y-m-d'),
            'time'=>date('H:i'),
            'datetime'=>date('Y-m-d\TH:i'),
            default=>date('Y-m-d H:i:s'),
        };
    }
    if(in_array($mode,['javascript','parent_field'],true)) return '';
    return (string)($cfg['default_value']??'');
}

function df_generated_attrs(array $cfg,string $type): string {
    $attrs=[];
    $isNumeric=DataFormFieldTypeRegistry::isNumeric($type);
    $isTemporal=in_array($type,['date','time','datetime'],true);
    $isChoice=in_array($type,['checkbox','boolean','select','multiselect','multi_lookup',DerivedMultiEnumManager::FIELD_TYPE],true);
    if(($cfg['autocomplete']??'')!=='')$attrs['autocomplete']=$cfg['autocomplete'];
    if(($cfg['inputmode']??'')!=='')$attrs['inputmode']=$cfg['inputmode'];
    if(($cfg['min_length']??null)!==null&&!$isNumeric&&!$isTemporal&&!$isChoice)$attrs['minlength']=(string)$cfg['min_length'];
    if(($cfg['max_length']??null)!==null&&!$isNumeric&&!$isTemporal&&!$isChoice)$attrs['maxlength']=(string)$cfg['max_length'];
    if(($cfg['pattern']??'')!==''&&!DataFormFieldTypeRegistry::isTextarea($type)&&!$isNumeric&&!$isTemporal&&!$isChoice)$attrs['pattern']=$cfg['pattern'];
    if(($cfg['min_value']??'')!==''&&($isNumeric||$isTemporal))$attrs['min']=$cfg['min_value'];
    if(($cfg['max_value']??'')!==''&&($isNumeric||$isTemporal))$attrs['max']=$cfg['max_value'];
    $typeSettings=DataFormFieldTypeRegistry::settings($type,$cfg);
    if($isNumeric)$attrs['step']=(string)($cfg['step']??$typeSettings['step']??($type==='integer'?'1':'any'));
    if(!empty($cfg['readonly'])&&!$isChoice)$attrs['readonly']=true;
    $out=[]; foreach($attrs as $name=>$value)$out[]=$value===true?$name:$name.'="'.htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';
    return implode(' ',$out);
}
function df_pattern_match(string $pattern,string $value): bool { if($pattern==='')return true; $regex='/^(?:'.str_replace('/','\\/',$pattern).')$/u'; return @preg_match($regex,$value)===1; }
function df_validate_record(
    array $fields,
    array $input,
    array $existingData=[],
    array $derivedOptionsByField=[]
): array {
    $data=[];
    foreach($fields as $field){
        $name=(string)$field['name'];
        $type=(string)$field['field_type'];
        $label=(string)($field['label']??$name);
        $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
        $existing=is_scalar($existingData[$name]??null)?(string)$existingData[$name]:'';
        if($type==='computed'){
            $data[$name]=$existing;
            continue;
        }
        if(($cfg['readonly']??false)||($cfg['hidden']??false)){
            $value=array_key_exists($name,$existingData)?$existing:df_server_default_value($field,$cfg);
        } elseif($type===DerivedMultiEnumManager::FIELD_TYPE){
            $selected=DerivedMultiEnumManager::canonicalizeSelection($input[$name]??[]);
            $value=DerivedMultiEnumManager::validateSelection($selected,(array)($derivedOptionsByField[$name]??[]),$cfg,$label);
        } else {
            $raw=$input[$name]??'';
            $value=DataFormFieldTypeRegistry::normalizeValue($type,$raw,$cfg,$label,$existing);
        }
        $required=(int)($field['is_required']??0)===1;
        if($required && !DataFormFieldTypeRegistry::isBoolean($type) && $value==='') throw new RuntimeException('Das Pflichtfeld „'.$label.'“ ist leer.');
        if($type===DerivedMultiEnumManager::FIELD_TYPE){$data[$name]=$value;continue;}
        if($value!=='' && !DataFormFieldTypeRegistry::isJsonLike($type) && !DataFormFieldTypeRegistry::isMedia($type) && $type!=='password'){
            $plain=strip_tags($value); $length=mb_strlen($plain);
            if(($cfg['min_length']??null)!==null && $length<(int)$cfg['min_length']) throw new RuntimeException('Das Feld „'.$label.'“ muss mindestens '.(int)$cfg['min_length'].' Zeichen enthalten.');
            if(($cfg['max_length']??null)!==null && $length>(int)$cfg['max_length']) throw new RuntimeException('Das Feld „'.$label.'“ darf höchstens '.(int)$cfg['max_length'].' Zeichen enthalten.');
            if(($cfg['pattern']??'')!=='' && !df_pattern_match((string)$cfg['pattern'],$plain)) throw new RuntimeException('Das Feld „'.$label.'“ entspricht nicht dem vorgegebenen Muster.');
        }
        if($value!=='' && DataFormFieldTypeRegistry::isNumeric($type)){
            $num=(float)$value;
            if(($cfg['min_value']??'')!=='' && is_numeric($cfg['min_value']) && $num<(float)$cfg['min_value']) throw new RuntimeException('Das Feld „'.$label.'“ unterschreitet den Minimalwert.');
            if(($cfg['max_value']??'')!=='' && is_numeric($cfg['max_value']) && $num>(float)$cfg['max_value']) throw new RuntimeException('Das Feld „'.$label.'“ überschreitet den Maximalwert.');
        }
        if($value!=='' && in_array($type,['date','time','datetime'],true)){
            $compare=$type==='datetime'?str_replace(' ','T',$value):$value;
            if(($cfg['min_value']??'')!=='' && $compare<(string)$cfg['min_value']) throw new RuntimeException('Das Feld „'.$label.'“ liegt vor dem erlaubten Mindestwert.');
            if(($cfg['max_value']??'')!=='' && $compare>(string)$cfg['max_value']) throw new RuntimeException('Das Feld „'.$label.'“ liegt nach dem erlaubten Höchstwert.');
        }
        $data[$name]=$value;
    }
    $computeContext=array_merge($existingData,$data);
    foreach($fields as $field){
        if((string)($field['field_type']??'')!=='computed') continue;
        $name=(string)$field['name'];
        $cfg=$field['_config']??df_field_config($field['configuration_json']??null,'computed');
        $data[$name]=DataFormFieldTypeRegistry::computeValue($cfg,$computeContext);
        $computeContext[$name]=$data[$name];
    }
    return $data;
}

function df_query(array $extra = []): string {
    $base = ['project' => (int)($_GET['project'] ?? $_POST['project'] ?? 0), 'dataform' => (int)($_GET['dataform'] ?? $_POST['dataform'] ?? 0), 'mode' => 'list'];
    foreach (['q','sort','dir','page','per_page','active_record','embed','preview','parent_relation','parent_record','preview_depth','child_preview','runtime_view','dialog_embed'] as $key) if (isset($_GET[$key]) && $_GET[$key] !== '') $base[$key] = $_GET[$key];
    if (isset($_GET['filter']) && is_array($_GET['filter'])) $base['filter'] = $_GET['filter'];
    return '?' . http_build_query(array_merge($base, $extra));
}

try {
    $adminPdo = enterprise_pdo();
    enterprise_upgrade($adminPdo);
    $stmt = $adminPdo->prepare('SELECT * FROM projects WHERE id = ? AND product_type = ? LIMIT 1');
    $stmt->execute([$projectId, $preProduct==='calendar'?'calendar':'dataform']);
    $project = $stmt->fetch();
    if (!$project) throw new RuntimeException('Das ausgewählte DataForm-Projekt wurde nicht gefunden.');
    $_SESSION['active_project_id'] = $projectId;

    $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_records (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        data_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_dataform_records_form (dataform_id),
        CONSTRAINT fk_dataform_records_form FOREIGN KEY (dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_list_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        visible_columns_json LONGTEXT NOT NULL,
        per_page INT UNSIGNED NOT NULL DEFAULT 20,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_list_settings (dataform_id, user_id),
        CONSTRAINT fk_list_settings_form FOREIGN KEY (dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_saved_filters (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        query_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_saved_filter_name (dataform_id, user_id, name),
        INDEX idx_saved_filters_form_user (dataform_id, user_id),
        CONSTRAINT fk_saved_filters_form FOREIGN KEY (dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    RelationManager::ensureSchema($pdo);
    RelationManager::repairLegacyOneToMany($pdo);
    DataFormManager::ensureRuntimeSettingsSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM dataforms WHERE id = ? LIMIT 1');
    $stmt->execute([$dataformId]);
    $dataform = $stmt->fetch();
    if (!$dataform) throw new RuntimeException('Das ausgewählte DataForm wurde nicht gefunden.');
    $tableSaveMode=in_array((string)($dataform['table_save_mode']??'manual'),['manual','adhoc'],true)
        ? (string)$dataform['table_save_mode']
        : 'manual';
    $showSaveSuccess=(int)($dataform['show_save_success']??1)===1;
    $configuredViewMode=in_array((string)($dataform['view_mode']??'table'),['form','table','dialog'],true)?(string)$dataform['view_mode']:'table';
    $viewMode=$runtimeViewOverride!==''?$runtimeViewOverride:$configuredViewMode;
    $defaultPerPage=max(1,min(200,(int)($dataform['default_per_page']??20)));
    $showSearch=(int)($dataform['show_search']??1)===1;
    $showFilter=(int)($dataform['show_filter']??1)===1;
    $showPagination=(int)($dataform['show_pagination']??1)===1;
    $allowCreate=(int)($dataform['allow_create']??1)===1;
    $allowEdit=(int)($dataform['allow_edit']??1)===1;
    $allowDelete=(int)($dataform['allow_delete']??1)===1;
    // LICENSE-RUNTIME HF78: the remote license is an upper bound for all DataForm functionality.
    $licensedCapabilities=array_fill_keys(array_map('strval',(array)($easyitDataFormManifest['capabilities']??[])),true);
    $showSearch=$showSearch && isset($licensedCapabilities['dataform.search']);
    $showFilter=$showFilter && isset($licensedCapabilities['dataform.filter']);
    $showPagination=$showPagination && isset($licensedCapabilities['dataform.pagination']);
    $allowCreate=$allowCreate && isset($licensedCapabilities['dataset.create']);
    $allowEdit=$allowEdit && isset($licensedCapabilities['dataset.update']);
    $allowDelete=$allowDelete && isset($licensedCapabilities['dataset.delete']);
    $allowCreateChild=isset($licensedCapabilities['relation.createChild']);
    if($parentRelationContextId>0) $allowCreate=$allowCreate && $allowCreateChild;
    $dialogSize=in_array((string)($dataform['dialog_size']??'large'),['small','medium','large','fullscreen'],true)?(string)$dataform['dialog_size']:'large';
    $dataformCssClass=trim((string)($dataform['css_class']??''));
    $additionalCss=(string)($dataform['additional_css']??'');
    $dataformEvents=json_decode((string)($dataform['event_handlers_json']??''),true);if(!is_array($dataformEvents))$dataformEvents=[];
    $recordsetKey=(string)($_GET['recordset_key']??DataFormRecordSetEventRepository::DEFAULT_RECORDSET_KEY);
    $recordSetEvents=DataFormRecordSetEventRepository::load($pdo,$dataformId,$recordsetKey);
    if(!isset($licensedCapabilities['dataform.custom_css'])){
        $dataformCssClass='';
        $additionalCss='';
    }
    if(!isset($licensedCapabilities['dataform.events'])){
        $dataformEvents=[];
        $recordSetEvents=[];
    }

    $stmt = $pdo->prepare('SELECT * FROM dataform_fields WHERE dataform_id = ? ORDER BY position, id');
    $stmt->execute([$dataformId]);
    $fields = $stmt->fetchAll();
    foreach($fields as &$field){$cfg=df_field_config($field['configuration_json']??null,(string)($field['field_type']??'text'));if(!isset($licensedCapabilities['dataform.custom_javascript'])&&($cfg['default_mode']??'')==='javascript'){$cfg['default_mode']='defined';$cfg['default_js_provider']='';}$field['_config']=$cfg;} unset($field);

    $parentRelationsById=[];
    $parentRelationsByLookupFieldId=[];
    $parentRecordOptionsByLookupFieldId=[];

    $relationTableExists=(int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name='dataform_relations'"
    )->fetchColumn()===1;
    if(!isset($licensedCapabilities['relation.read'])) $relationTableExists=false;

    if($relationTableExists){
        $relationStmt=$pdo->prepare(
            "SELECT r.id,r.name,r.relation_type,r.is_required,
                    r.source_dataform_id,r.target_display_field_id,
                    r.lookup_field_id,r.configuration_json,p.name AS parent_name
             FROM dataform_relations r
             JOIN dataforms p ON p.id=r.source_dataform_id
             WHERE r.target_dataform_id=?
               AND r.relation_type='1:n'
               AND r.is_enabled=1
               AND r.lookup_field_id IS NOT NULL"
        );
        $relationStmt->execute([$dataformId]);

        foreach($relationStmt->fetchAll() as $relation){
            $relationId=(int)$relation['id'];
            $lookupFieldId=(int)$relation['lookup_field_id'];

            $parentRelationsById[$relationId]=$relation;
            $parentRelationsByLookupFieldId[$lookupFieldId]=$relation;

            $displayFieldName=null;
            if((int)($relation['target_display_field_id']??0)>0){
                $displayStmt=$pdo->prepare(
                    'SELECT name FROM dataform_fields WHERE id=? AND dataform_id=?'
                );
                $displayStmt->execute([
                    (int)$relation['target_display_field_id'],
                    (int)$relation['source_dataform_id']
                ]);
                $displayFieldName=$displayStmt->fetchColumn()?:null;
            }

            $options=[];
            foreach(
                DataFormRecordStore::all(
                    $pdo,
                    (int)$relation['source_dataform_id']
                )
                as $parentRecord
            ){
                $parentData=(array)($parentRecord['data']??[]);
                $caption=$displayFieldName!==null
                    ? trim((string)($parentData[(string)$displayFieldName]??''))
                    : '';

                if($caption===''){
                    $caption='#'.(int)$parentRecord['id'];
                }

                $options[]=[
                    'id'=>(int)$parentRecord['id'],
                    'caption'=>$caption,
                ];
            }

            $parentRecordOptionsByLookupFieldId[$lookupFieldId]=$options;
        }
    }


        $lookupRelationStmt=$pdo->prepare(
            "SELECT r.id,r.name,r.relation_type,r.is_required,
                    r.target_dataform_id,
                    r.target_dataform_id AS source_dataform_id,
                    r.target_display_field_id,
                    r.source_field_id AS lookup_field_id,
                    r.configuration_json,
                    p.name AS parent_name
             FROM dataform_relations r
             LEFT JOIN dataforms p ON p.id=r.target_dataform_id
             WHERE r.source_dataform_id=?
               AND r.relation_type='n:1'
               AND r.is_enabled=1
               AND r.source_field_id IS NOT NULL"
        );
        $lookupRelationStmt->execute([$dataformId]);

        foreach($lookupRelationStmt->fetchAll() as $relation){
            $lookupFieldId=(int)$relation['lookup_field_id'];
            if (!RelationManager::rendersAsLookup($relation)) {
                continue;
            }

            if (RelationManager::isBaseTableLookup($relation)) {
                $desc=RelationManager::baseTableLookupDescriptor($relation);
                $relation['lookup_source_kind']='base_table';
                $relation['lookup_table']=$desc['table']??'';
                $relation['lookup_key_column']=$desc['key_column']??'id';
                $relation['parent_name']='Basistabelle → '.($desc['table']??'');
                $parentRelationsByLookupFieldId[$lookupFieldId]=$relation;
                $parentRecordOptionsByLookupFieldId[$lookupFieldId]
                    =RelationManager::baseTableLookupOptions($pdo,$relation);
                continue;
            }

            $relation['lookup_source_kind']='dataform';
            $parentRelationsByLookupFieldId[$lookupFieldId]=$relation;
            $parentRecordOptionsByLookupFieldId[$lookupFieldId]
                =RelationManager::dataformLookupOptions($pdo,$relation);
        }

        // HF149: Runtime-Fallback fuer direkt im Feld-Designer konfigurierte
        // Datensatz-Auswahlen. Die n:1-Relation bleibt die primaere Quelle;
        // falls sie durch ein altes Projekt/Produkt-Setup fehlt, kann die
        // Runtime die Lookup-Auswahl aus der persistenten Feldkonfiguration
        // trotzdem unmittelbar herstellen.
        foreach ($fields as $runtimeLookupField) {
            $runtimeLookupFieldId=(int)($runtimeLookupField['id']??0);
            if ($runtimeLookupFieldId<1 || (string)($runtimeLookupField['field_type']??'')!=='lookup') continue;
            if (isset($parentRelationsByLookupFieldId[$runtimeLookupFieldId])) continue;
            $runtimeLookupCfg=is_array($runtimeLookupField['_config']??null)?$runtimeLookupField['_config']:[];
            $runtimeTargetId=(int)($runtimeLookupCfg['lookup_target_dataform_id']??0);
            if ($runtimeTargetId<1) continue;
            $runtimeDisplayId=(int)($runtimeLookupCfg['lookup_target_display_field_id']??0);
            $runtimeKeyId=(int)($runtimeLookupCfg['lookup_target_key_field_id']??0);
            $runtimeRelation=[
                'id'=>0,
                'name'=>'field_config_lookup_'.$runtimeLookupFieldId,
                'relation_type'=>'n:1',
                'is_required'=>(int)($runtimeLookupField['is_required']??0),
                'source_dataform_id'=>$dataformId,
                'source_field_id'=>$runtimeLookupFieldId,
                'lookup_field_id'=>$runtimeLookupFieldId,
                'target_dataform_id'=>$runtimeTargetId,
                'target_display_field_id'=>$runtimeDisplayId?:null,
                'configuration_json'=>json_encode([
                    'semantics'=>RelationManager::LOOKUP_SEMANTICS,
                    'source_fk_field_id'=>$runtimeLookupFieldId,
                    'target_key'=>$runtimeKeyId>0?'field':'id',
                    'target_key_field_id'=>$runtimeKeyId?:null,
                    'target_display_field_id'=>$runtimeDisplayId?:null,
                    'render_as_lookup'=>true,
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'lookup_source_kind'=>'dataform',
            ];
            $parentRelationsByLookupFieldId[$runtimeLookupFieldId]=$runtimeRelation;
            $parentRecordOptionsByLookupFieldId[$runtimeLookupFieldId]=RelationManager::dataformLookupOptions($pdo,$runtimeRelation);
        }

        foreach ($fields as &$relationField) {
            $lookupRelation=$parentRelationsByLookupFieldId[(int)$relationField['id']]??null;
            if ($lookupRelation && (int)($lookupRelation['is_required']??0)===1) {
                $relationField['is_required']=1;
            }
        }
        unset($relationField);

    $recordStorageMode=DataFormRecordStore::isPhysical(
        $pdo,
        $dataformId
    ) ? 'physical' : 'generic';

    // PUBLISH16: Sobald ein Kind-DataForm mit einem konkreten Elternkontext
    // geöffnet wird, gilt diese Kopplung für die gesamte Kindansicht – nicht
    // nur für mode=create. Damit ist das gekoppelte FK-/Lookup-Feld auch beim
    // Anzeigen/Bearbeiten sichtbar auf den aktuellen Eltern-Datensatz
    // vorausgewählt und kann nicht versehentlich umgehängt werden.
    if (
        $parentRelationContextId>0
        && $parentRecordContextId>0
    ) {
        $contextRelation=$parentRelationsById[
            $parentRelationContextId
        ]??null;

        if (!$contextRelation) {
            throw new RuntimeException(
                'Die angeforderte Elternbeziehung gehört nicht zu diesem Kind-DataForm.'
            );
        }

        $parentDataformId=(int)$contextRelation['source_dataform_id'];
        $parentRecord=DataFormRecordStore::find(
            $pdo,
            $parentDataformId,
            $parentRecordContextId
        );
        if ($parentRecord===null) {
            throw new RuntimeException(
                'Der gewählte Eltern-Datensatz existiert nicht.'
            );
        }

        $lookupFieldId=(int)$contextRelation['lookup_field_id'];
        $lookupField=null;
        foreach ($fields as $candidateField) {
            if ((int)$candidateField['id']===$lookupFieldId) {
                $lookupField=$candidateField;
                break;
            }
        }
        if (!$lookupField) {
            throw new RuntimeException(
                'Das Fremdschlüsselfeld der Kindbeziehung wurde nicht gefunden.'
            );
        }

        $parentCaption='#'.$parentRecordContextId;
        foreach(
            $parentRecordOptionsByLookupFieldId[$lookupFieldId]??[]
            as $parentOption
        ) {
            if ((int)$parentOption['id']===$parentRecordContextId) {
                $parentCaption=(string)$parentOption['caption'];
                break;
            }
        }

        $contextRelationConfig=RelationManager::relationConfiguration($contextRelation);
        $masterContext=[
            'relation_id'=>$parentRelationContextId,
            'parent_dataform_id'=>$parentDataformId,
            'parent_record_id'=>$parentRecordContextId,
            'parent_caption'=>$parentCaption,
            'lookup_field_id'=>$lookupFieldId,
            'lookup_field_name'=>(string)$lookupField['name'],
            'relation_name'=>(string)$contextRelation['name'],
            // PUBLISH18: bestehende 1:n-Beziehungen ohne Schalter bleiben
            // aus Sicherheitsgründen standardmäßig read-only.
            'bound_field_readonly'=>!array_key_exists('bound_field_readonly',$contextRelationConfig)
                || !empty($contextRelationConfig['bound_field_readonly']),
        ];

        if ($returnDataformId<1) {
            $returnDataformId=$parentDataformId;
        }
        if ($returnRecordId<1) {
            $returnRecordId=$parentRecordContextId;
        }
    }

    // HF60: In der tabellarischen Datenblattansicht eines physisch gebundenen
    // DataForms muessen alle Tabellenfelder sichtbar sein. Eine alte oder
    // importierte list_visible-Konfiguration darf reale Spalten nicht aus der
    // Tabelle und insbesondere nicht aus der Inline-Neuzeile verschwinden
    // lassen. Bei generischen DataForms bleibt die konfigurierbare Listenlogik
    // unveraendert.
    $isPhysicalTableView=$recordStorageMode==='physical';
    $listFields=$isPhysicalTableView
        ? array_values($fields)
        : array_values(array_filter(
            $fields,
            static fn(array $f):bool=>
                empty($f['_config']['hidden'])
                && !empty($f['_config']['list_visible'])
        ));
    $filterFields=array_values(array_filter($fields,static fn(array $f):bool=>empty($f['_config']['hidden'])&&!empty($f['_config']['filterable'])));
    $detailFields=array_values(array_filter($fields,static fn(array $f):bool=>empty($f['_config']['hidden'])));
    $searchableNames=array_map(static fn(array $f):string=>(string)$f['name'],array_filter($fields,static fn(array $f):bool=>empty($f['_config']['hidden'])&&!empty($f['_config']['searchable'])));
    $filterableNames=array_map(static fn(array $f):string=>(string)$f['name'],$filterFields);
    $sortableNames=array_map(static fn(array $f):string=>(string)$f['name'],array_filter($fields,static fn(array $f):bool=>empty($f['_config']['hidden'])&&!empty($f['_config']['sortable'])));

    $userId = (int)($user['id'] ?? 0);
    $settingsStmt = $pdo->prepare('SELECT visible_columns_json, per_page FROM dataform_list_settings WHERE dataform_id = ? AND user_id = ?');
    $settingsStmt->execute([$dataformId, $userId]);
    $settings = $settingsStmt->fetch() ?: null;
    // HF60: Fuer physische Tabellen ist der vollstaendige Spaltensatz die
    // verbindliche Datenblattansicht. Damit sind auch Felder hinter der
    // bisherigen 4-Spalten-Grenze (z. B. from_person / to_person) sofort
    // sichtbar und in der *-Neuzeile editierbar.
    $defaultColumns = array_map(
        static fn(array $f): string => (string)$f['name'],
        $listFields
    );
    $validNames = array_column($listFields, 'name');
    if ($isPhysicalTableView) {
        $visibleColumns=$validNames;
    } else {
        $visibleColumns = $settings
            ? (json_decode((string)$settings['visible_columns_json'], true) ?: $defaultColumns)
            : $defaultColumns;
        $visibleColumns = array_values(array_intersect($visibleColumns, $validNames));
        if (!$visibleColumns && $fields) {
            $visibleColumns = [(string)$fields[0]['name']];
        }
    }
    $perPage = max(5, min(200, (int)($_GET['per_page'] ?? ($settings['per_page'] ?? $defaultPerPage))));
    $savedFilters = [];
    if ($showFilter) {
        $savedFiltersStmt = $pdo->prepare('SELECT id,name,query_json,updated_at FROM dataform_saved_filters WHERE dataform_id=? AND user_id=? ORDER BY name');
        $savedFiltersStmt->execute([$dataformId, $userId]);
        $savedFilters = $savedFiltersStmt->fetchAll();
    }

    if ($showFilter && isset($_GET['saved_filter']) && (int)$_GET['saved_filter'] > 0) {
        $stmt = $pdo->prepare('SELECT query_json FROM dataform_saved_filters WHERE id=? AND dataform_id=? AND user_id=?');
        $stmt->execute([(int)$_GET['saved_filter'], $dataformId, $userId]);
        $savedPayload = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        if (is_array($savedPayload)) {
            if ($showSearch) {
                $_GET['q'] = (string)($savedPayload['q'] ?? '');
            }
            $_GET['filter'] = is_array($savedPayload['filter'] ?? null) ? $savedPayload['filter'] : [];
            $_GET['sort'] = (string)($savedPayload['sort'] ?? 'id');
            $_GET['dir'] = (string)($savedPayload['dir'] ?? 'desc');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $previewMode) {
        $error = 'Realvorschau: Änderungen werden in der Vorschau nicht gespeichert.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        // LICENSE-RUNTIME: protected CRUD actions require a server-side ActionDecision.
        \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::authorizePost($_POST);
        $inlineCreateAttempt=$action==='save_record' && (string)($_POST['inline_create']??'')==='1';
        $inlineEditAttempt=$action==='save_record' && (string)($_POST['inline_edit']??'')==='1';
        try {
        if ($action === 'save_record') {
            if($recordId>0&&!$allowEdit) throw new RuntimeException('Das Bearbeiten ist für dieses DataForm deaktiviert.');
            if($recordId<1&&!$allowCreate) throw new RuntimeException('Das Anlegen ist für dieses DataForm deaktiviert.');
            if (!$fields) {
                throw new RuntimeException(
                    'Legen Sie zuerst mindestens ein Feld an.'
                );
            }

            $existingData=[];
            if ($recordId>0) {
                $existingRecord=DataFormRecordStore::find(
                    $pdo,
                    $dataformId,
                    $recordId
                );
                if ($existingRecord===null) {
                    throw new RuntimeException(
                        'Der Datensatz wurde nicht gefunden.'
                    );
                }
                $existingData=(array)$existingRecord['data'];
            }

            $inputValues=is_array($_POST['values']??null)
                ? (array)$_POST['values']
                : [];

            // PUBLISH18: Read-only-Parentbindung vor jeder weiteren
            // Validierung in den Request-Snapshot schreiben. Damit kann auch
            // ein manipulierter Select-Wert weder die Validierung noch den
            // gespeicherten Fremdschlüssel vom aktuellen Parent lösen.
            if ($masterContext!==null) {
                $masterBoundReadonly=!array_key_exists('bound_field_readonly',$masterContext)
                    || !empty($masterContext['bound_field_readonly']);
                if ($masterBoundReadonly) {
                    $inputValues[(string)$masterContext['lookup_field_name']]
                        =(string)$masterContext['parent_record_id'];
                }
            }
            $newMediaOnFailure=[];
            $oldMediaAfterSave=[];
            $recordSaveCommitted=false;
            $mediaManager=df_media_manager();
            $uploadedFiles=is_array($_FILES['values']??null)?(array)$_FILES['values']:[];
            $removeMedia=is_array($_POST['remove_media']??null)?(array)$_POST['remove_media']:[];
            foreach ($fields as $mediaField) {
                $mediaType=(string)($mediaField['field_type']??'');
                if (!DataFormFieldTypeRegistry::isMedia($mediaType)) continue;
                $mediaName=(string)$mediaField['name'];
                $mediaCfg=$mediaField['_config']??df_field_config($mediaField['configuration_json']??null,$mediaType);
                $existingMedia=is_scalar($existingData[$mediaName]??null)?(string)$existingData[$mediaName]:'';
                if (!empty($removeMedia[$mediaName])) {
                    $inputValues[$mediaName]='';
                    if ($existingMedia!=='') $oldMediaAfterSave[]=$existingMedia;
                    continue;
                }
                $upload=DataFormFieldStorageManager::nestedUpload($uploadedFiles,$mediaName);
                if ($upload!==null && (int)$upload['error']!==UPLOAD_ERR_NO_FILE) {
                    $stored=$mediaManager->storeUpload($upload,$mediaType,$mediaCfg,[
                        'project_id'=>$projectId,
                        'dataform_id'=>$dataformId,
                        'field_name'=>$mediaName,
                    ]);
                    $inputValues[$mediaName]=$stored;
                    $newMediaOnFailure[]=$stored;
                    if ($existingMedia!=='' && $existingMedia!==$stored) $oldMediaAfterSave[]=$existingMedia;
                } elseif ($existingMedia!=='' && !array_key_exists($mediaName,$inputValues)) {
                    $inputValues[$mediaName]=$existingMedia;
                }
            }
            if ($inlineCreateAttempt) {
                $inlineCreateValues=$inputValues;
            }
            if ($inlineEditAttempt && $recordId>0) {
                $inlineEditValuesByRecord[$recordId]=$inputValues;
                $activeRecordId=$recordId;
            }
            $currentDataForDerived=$existingData;
            foreach ($inputValues as $inputName=>$inputValue) {
                if (is_array($inputValue)) {
                    $first=reset($inputValue);
                    $currentDataForDerived[(string)$inputName]=is_scalar($first)
                        ? (string)$first
                        : '';
                } elseif (is_scalar($inputValue)) {
                    $currentDataForDerived[(string)$inputName]=(string)$inputValue;
                }
            }

            $effectiveParentRecordId=df_effective_parent_record_id(
                $fields,
                $parentRelationsByLookupFieldId,
                $currentDataForDerived,
                $masterContext
            );
            $derivedOptionsForSave=df_derived_options_for_fields(
                $pdo,
                $fields,
                $dataformId,
                $recordId,
                $effectiveParentRecordId,
                $currentDataForDerived
            );

            $data=df_validate_record(
                $fields,
                $inputValues,
                $existingData,
                $derivedOptionsForSave
            );

            foreach ($parentRelationsByLookupFieldId as $lookupFieldId=>$lookupRelation) {
                $lookupFieldName='';
                foreach ($fields as $candidateField) {
                    if ((int)$candidateField['id']===(int)$lookupFieldId) {
                        $lookupFieldName=(string)$candidateField['name'];
                        break;
                    }
                }
                if ($lookupFieldName==='') {
                    continue;
                }
                $selectedValue=trim((string)($data[$lookupFieldName]??''));
                if ($selectedValue==='') {
                    continue;
                }
                $lookupExists=false;
                if (RelationManager::isBaseTableLookup($lookupRelation)) {
                    $lookupExists=RelationManager::baseTableLookupValueExists(
                        $pdo,
                        $lookupRelation,
                        $selectedValue
                    );
                } else {
                    $lookupExists=RelationManager::dataformLookupValueExists(
                        $pdo,
                        $lookupRelation,
                        $selectedValue
                    );
                }
                if (!$lookupExists) {
                    throw new RuntimeException(
                        'Die Auswahl im Feld „'.$lookupFieldName.'“ verweist auf keinen vorhandenen Datensatz.'
                    );
                }
            }

            // PUBLISH16: Ein Kinddatensatz, der im Kontext eines Elternsatzes
            // geöffnet wurde, bleibt zwingend an genau diesen Elternsatz
            // gekoppelt. Das gilt sowohl bei Neuanlage als auch bei Bearbeitung.
            if ($masterContext!==null) {
                $masterBoundReadonly=!array_key_exists('bound_field_readonly',$masterContext)
                    || !empty($masterContext['bound_field_readonly']);
                if ($masterBoundReadonly) {
                    $data[
                        (string)$masterContext['lookup_field_name']
                    ]=(string)$masterContext['parent_record_id'];
                }
            }

            $saveOperation=$recordId>0?'update':'create';
            $saveOriginalValues=$existingData;
            if ($recordId>0) {
                DataFormRecordStore::update(
                    $pdo,
                    $dataformId,
                    $recordId,
                    $data
                );
                enterprise_event_dispatch(
                    'dataform.record.updated',
                    [
                        'record_id'=>$recordId,
                        'dataform_id'=>$dataformId,
                        'storage'=>$recordStorageMode,
                    ],
                    [
                        'project_id'=>$projectId,
                        'user_id'=>$userId,
                    ]
                );
                $saveSuccessDialog=$showSaveSuccess?'Datensatz gespeichert.':'';
            } else {
                $recordId=DataFormRecordStore::create(
                    $pdo,
                    $dataformId,
                    $data
                );
                enterprise_event_dispatch(
                    'dataform.record.created',
                    [
                        'record_id'=>$recordId,
                        'dataform_id'=>$dataformId,
                        'storage'=>$recordStorageMode,
                    ],
                    [
                        'project_id'=>$projectId,
                        'user_id'=>$userId,
                    ]
                );
                $saveSuccessDialog=$showSaveSuccess
                    ? ($masterContext!==null
                        ? 'Kinddatensatz angelegt und zugeordnet.'
                        : 'Datensatz angelegt.')
                    : '';
            }

            // PUBLISH17: Nach dem erfolgreichen INSERT/UPDATE wird ein kanonischer
            // DataFormActionContext aus dem tatsaechlich gespeicherten Zustand erzeugt.
            $dataformActionContext=DataFormActionContext::afterSave(
                (array)$project,
                (array)$dataform,
                $fields,
                $recordId,
                $data,
                $saveOriginalValues,
                $saveOperation,
                [
                    'view_mode'=>$viewMode,
                    'storage_mode'=>$recordStorageMode,
                    'page'=>max(1,(int)($_GET['page']??1)),
                    'records_per_page'=>max(1,(int)$perPage),
                    'search'=>(string)($_GET['q']??''),
                    'preview'=>$previewMode,
                    'dialog_open'=>$viewMode==='dialog',
                ],
                $masterContext
            );

            // Erst nach erfolgreichem Record-Commit dürfen ersetzte Altmedien
            // entfernt werden. Bei Validierungs-/DB-Fehlern räumt der catch-
            // Block ausschließlich neu angelegte Medien wieder auf.
            $recordSaveCommitted=true;
            \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::finalize(true, 'OK');
            foreach (array_values(array_unique($oldMediaAfterSave)) as $oldMediaValue) {
                if (is_string($oldMediaValue) && $oldMediaValue!=='') {
                    $mediaManager->deleteManagedValue($oldMediaValue);
                }
            }

            if ($inlineCreateAttempt) {
                // HF76-FIX16: Nach Inline-Neuanlage bleibt der frisch erzeugte
                // Datensatz auf der letzten Seite sichtbar und wird dort zum
                // aktuellen DS. Die leere Neuzeile bleibt direkt darunter.
                $inlineCreatedRecordId=$recordId;
                $activeRecordId=$recordId;
                $recordId=0;
                $mode='list';
                $inlineCreateValues=[];
            } elseif ($inlineEditAttempt) {
                // HF61: Bestehende Datensaetze werden direkt in der Tabelle gespeichert.
                // Die Listenansicht bleibt offen und dieselbe Zeile bleibt aktiv.
                $activeRecordId=$recordId;
                unset($inlineEditValuesByRecord[$recordId]);
                $recordId=0;
                $mode='list';
            } else {
                $mode='detail';
            }
        } elseif ($action === 'delete_record') {
            if(!$allowDelete) throw new RuntimeException('Das Löschen ist für dieses DataForm deaktiviert.');
            $derivedReferences=DerivedMultiEnumManager::referencesToSourceRecord(
                $pdo,
                $dataformId,
                $recordId
            );
            if ($derivedReferences) {
                $firstReference=$derivedReferences[0];
                throw new RuntimeException(
                    'Dieser Datensatz wird noch von '
                    .count($derivedReferences)
                    .' abgeleiteten Mehrfachauswahl-Referenz(en) verwendet. '
                    .'Erste Fundstelle: Feld „'.(string)$firstReference['field_label'].'“, '
                    .'Datensatz #'.(int)$firstReference['record_id'].'. Entfernen Sie zuerst diese Auswahl.'
                );
            }

            $dependentChildren=df_child_record_count(
                $pdo,
                $dataformId,
                $recordId
            );
            if ($dependentChildren>0) {
                throw new RuntimeException(
                    'Dieser Eltern-Datensatz besitzt '
                    .$dependentChildren
                    .' Kinddatensatz/-sätze. Löschen Sie zuerst die Kinddatensätze.'
                );
            }

            $deleteRecordSnapshot=DataFormRecordStore::find($pdo,$dataformId,$recordId);
            $deleteMediaValues=[];
            if ($deleteRecordSnapshot!==null) {
                foreach ($fields as $deleteField) {
                    if (!DataFormFieldTypeRegistry::isMedia((string)($deleteField['field_type']??''))) continue;
                    $deleteValue=$deleteRecordSnapshot['data'][(string)$deleteField['name']]??'';
                    if (is_scalar($deleteValue) && (string)$deleteValue!=='') $deleteMediaValues[]=(string)$deleteValue;
                }
            }
            DataFormRecordStore::delete(
                $pdo,
                $dataformId,
                $recordId
            );
            foreach (array_values(array_unique($deleteMediaValues)) as $deleteMediaValue) df_media_manager()->deleteManagedValue($deleteMediaValue);
            enterprise_event_dispatch(
                'dataform.record.deleted',
                [
                    'record_id'=>$recordId,
                    'dataform_id'=>$dataformId,
                    'storage'=>$recordStorageMode,
                ],
                [
                    'project_id'=>$projectId,
                    'user_id'=>$userId,
                ]
            );
            $recordId=0;
            $mode='list';
            $success='Der Datensatz wurde gelöscht.';
            \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::finalize(true, 'OK');
        } elseif ($action === 'save_columns') {
            $selected = array_values(array_intersect(array_map('strval', $_POST['columns'] ?? []), $validNames));
            if (!$selected && $fields) throw new RuntimeException('Wählen Sie mindestens eine Listenspalte aus.');
            $savedPerPage = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
            $stmt = $pdo->prepare('INSERT INTO dataform_list_settings (dataform_id,user_id,visible_columns_json,per_page) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE visible_columns_json=VALUES(visible_columns_json), per_page=VALUES(per_page)');
            $stmt->execute([$dataformId, $userId, json_encode($selected, JSON_UNESCAPED_UNICODE), $savedPerPage]);
            $visibleColumns = $selected; $perPage = $savedPerPage; $success = 'Die Listenansicht wurde gespeichert.'; $mode = 'list';
        } elseif ($action === 'save_filter') {
            if(!$showFilter) throw new RuntimeException('Filter sind für dieses DataForm deaktiviert.');
            $filterName = trim((string)($_POST['filter_name'] ?? ''));
            if ($filterName === '') throw new RuntimeException('Geben Sie einen Namen für den Filter ein.');
            if (mb_strlen($filterName) > 120) throw new RuntimeException('Der Filtername darf höchstens 120 Zeichen lang sein.');
            $filterPayload = [
                'q' => trim((string)($_POST['current_q'] ?? '')),
                'filter' => is_array($_POST['current_filter'] ?? null) ? $_POST['current_filter'] : [],
                'sort' => (string)($_POST['current_sort'] ?? 'id'),
                'dir' => (string)($_POST['current_dir'] ?? 'desc'),
            ];
            $stmt = $pdo->prepare('INSERT INTO dataform_saved_filters (dataform_id,user_id,name,query_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE query_json=VALUES(query_json), updated_at=CURRENT_TIMESTAMP');
            $stmt->execute([$dataformId, $userId, $filterName, json_encode($filterPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $success = 'Der Filter wurde gespeichert.'; $mode = 'list';
        } elseif ($action === 'delete_filter') {
            if(!$showFilter) throw new RuntimeException('Filter sind für dieses DataForm deaktiviert.');
            $filterId = (int)($_POST['filter_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM dataform_saved_filters WHERE id=? AND dataform_id=? AND user_id=?');
            $stmt->execute([$filterId, $dataformId, $userId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Der gespeicherte Filter wurde nicht gefunden.');
            $success = 'Der gespeicherte Filter wurde gelöscht.'; $mode = 'list';
        } elseif ($action === 'bulk_delete') {
            if(!$allowDelete) throw new RuntimeException('Das Löschen ist für dieses DataForm deaktiviert.');
            $selectedIds=array_values(array_unique(array_filter(
                array_map(
                    'intval',
                    $_POST['record_ids']??[]
                ),
                static fn(int $id): bool => $id>0
            )));
            if (!$selectedIds) {
                throw new RuntimeException(
                    'Wählen Sie mindestens einen Datensatz aus.'
                );
            }

            foreach ($selectedIds as $selectedRecordId) {
                $derivedReferences=DerivedMultiEnumManager::referencesToSourceRecord(
                    $pdo,
                    $dataformId,
                    $selectedRecordId
                );
                if ($derivedReferences) {
                    throw new RuntimeException(
                        'Datensatz #'.$selectedRecordId
                        .' wird noch in einer abgeleiteten Mehrfachauswahl verwendet und kann nicht gesammelt gelöscht werden.'
                    );
                }

                $dependentChildren=df_child_record_count(
                    $pdo,
                    $dataformId,
                    $selectedRecordId
                );
                if ($dependentChildren>0) {
                    throw new RuntimeException(
                        'Datensatz #'.$selectedRecordId
                        .' besitzt Kinddatensätze und kann nicht gesammelt gelöscht werden.'
                    );
                }
            }

            $bulkMediaValues=[];
            foreach ($selectedIds as $selectedRecordId) {
                $snapshot=DataFormRecordStore::find($pdo,$dataformId,$selectedRecordId);
                if ($snapshot===null) continue;
                foreach ($fields as $deleteField) {
                    if (!DataFormFieldTypeRegistry::isMedia((string)($deleteField['field_type']??''))) continue;
                    $deleteValue=$snapshot['data'][(string)$deleteField['name']]??'';
                    if (is_scalar($deleteValue) && (string)$deleteValue!=='') $bulkMediaValues[]=(string)$deleteValue;
                }
            }
            $deletedCount=DataFormRecordStore::bulkDelete(
                $pdo,
                $dataformId,
                $selectedIds
            );
            foreach (array_values(array_unique($bulkMediaValues)) as $deleteMediaValue) df_media_manager()->deleteManagedValue($deleteMediaValue);
            enterprise_event_dispatch(
                'dataform.records.deleted',
                [
                    'record_ids'=>$selectedIds,
                    'dataform_id'=>$dataformId,
                    'count'=>$deletedCount,
                    'storage'=>$recordStorageMode,
                ],
                [
                    'project_id'=>$projectId,
                    'user_id'=>$userId,
                ]
            );
            $success=$deletedCount
                .' Datensätze wurden gelöscht.';
            \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::finalize(true, 'OK');
            $mode='list';
        }
        } catch (RuntimeException $postError) {
            \EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::finalize(false, 'LOCAL_OPERATION_FAILED');
            if ($action==='save_record' && empty($recordSaveCommitted ?? false)) {
                foreach (array_values(array_unique($newMediaOnFailure ?? [])) as $newMediaValue) {
                    if (is_string($newMediaValue) && $newMediaValue!=='') df_media_manager()->deleteManagedValue($newMediaValue);
                }
            }
            // HF59: Benutzer-/Validierungsfehler dürfen die Tabellenansicht nicht abbrechen.
            // Die Daten werden danach normal neu geladen; bei Inline-Neuanlage bleiben
            // die eingegebenen Werte in der *-Zeile erhalten.
            $error=$postError->getMessage();
            if ($inlineCreateAttempt) {
                $recordId=0;
                $mode='list';
            } elseif ($inlineEditAttempt && $recordId>0) {
                // HF61: Validierungsfehler bleiben in derselben Tabellenzeile sichtbar.
                $activeRecordId=$recordId;
                $recordId=0;
                $mode='list';
            } elseif ($action==='save_record') {
                $mode=$recordId>0 ? 'edit' : 'create';
            }
        }
    }

    if ($recordId>0) {
        $record=DataFormRecordStore::find(
            $pdo,
            $dataformId,
            $recordId
        );
        if ($record===null) {
            throw new RuntimeException(
                'Der Datensatz wurde nicht gefunden.'
            );
        }
    }

    $allRecords=DataFormRecordStore::all(
        $pdo,
        $dataformId
    );

    // PUBLISH16: Jeder echte Elternkontext filtert das Kind-DataForm auf den
    // gekoppelten Eltern-Datensatz – in normaler Runtime ebenso wie in der
    // Realvorschau. Damit zeigt ein aus dem Elternformular geöffnetes
    // Kindformular niemals Datensätze eines anderen Elternsatzes.
    if (
        $parentRelationContextId>0
        && $parentRecordContextId>0
    ) {
        $previewRelation=$parentRelationsById[$parentRelationContextId]??null;
        if ($previewRelation!==null) {
            $lookupFieldId=(int)($previewRelation['lookup_field_id']??0);
            $lookupFieldName='';
            foreach ($fields as $previewField) {
                if ((int)$previewField['id']===$lookupFieldId) {
                    $lookupFieldName=(string)$previewField['name'];
                    break;
                }
            }
            if ($lookupFieldName!=='') {
                $allRecords=array_values(array_filter(
                    $allRecords,
                    static fn(array $candidate): bool =>
                        (string)($candidate['data'][$lookupFieldName]??'')
                        ===(string)$parentRecordContextId
                ));
                $previewParentFilter=[
                    'relation_id'=>$parentRelationContextId,
                    'parent_record_id'=>$parentRecordContextId,
                    'lookup_field_name'=>$lookupFieldName,
                ];
            }
        }
    }

    // PUBLISH11: Die Realvorschau kennt die Kind-DataForms des aktuellen
    // DataForms. Jedes Kind wird weiter unten als eigene echte Runtime in
    // einem verschachtelten iframe gerendert. Die Tiefe ist begrenzt, damit
    // fehlerhafte zyklische Relationenkonfigurationen keine Endlosschleife
    // erzeugen können.
    if ($previewMode && $previewDepth<3 && $relationTableExists) {
        $previewChildStmt=$pdo->prepare(
            "SELECT r.id,r.name,r.target_dataform_id,r.lookup_field_id,
                    d.name AS child_dataform_name
             FROM dataform_relations r
             JOIN dataforms d ON d.id=r.target_dataform_id
             WHERE r.source_dataform_id=?
               AND r.relation_type='1:n'
               AND r.is_enabled=1
               AND r.lookup_field_id IS NOT NULL
             ORDER BY r.name,r.id"
        );
        $previewChildStmt->execute([$dataformId]);
        foreach ($previewChildStmt->fetchAll(PDO::FETCH_ASSOC) as $previewRelation) {
            $childId=(int)$previewRelation['target_dataform_id'];
            $lookupId=(int)$previewRelation['lookup_field_id'];
            $lookupCheck=$pdo->prepare(
                'SELECT name,label FROM dataform_fields WHERE id=? AND dataform_id=? LIMIT 1'
            );
            $lookupCheck->execute([$lookupId,$childId]);
            $lookup=$lookupCheck->fetch(PDO::FETCH_ASSOC);
            if (!$lookup) {
                continue;
            }
            $previewChildRelations[]=[
                'relation_id'=>(int)$previewRelation['id'],
                'relation_name'=>(string)$previewRelation['name'],
                'child_dataform_id'=>$childId,
                'child_dataform_name'=>(string)$previewRelation['child_dataform_name'],
                'lookup_field_id'=>$lookupId,
                'lookup_field_name'=>(string)$lookup['name'],
                'lookup_field_label'=>(string)$lookup['label'],
            ];
        }
    }
    // PUBLISH13: Die Standardansicht ist eine echte Runtime-Eigenschaft.
    // "Formular" darf nicht durch historisch erzeugte mode=list-Links wieder
    // in die Tabellenansicht fallen. Nur create/edit/detail sind explizite
    // Datensatzaktionen; ein list-Aufruf wird in der Formularansicht auf den
    // aktuellen bzw. ersten Datensatz aufgelöst.
    if($viewMode==='form' && $mode==='list'){
        $candidateId=$activeRecordId>0?$activeRecordId:0;
        if($candidateId>0){
            $candidate=DataFormRecordStore::find($pdo,$dataformId,$candidateId);
            if($candidate!==null){
                $recordId=$candidateId;
                $record=$candidate;
            }
        }
        if($recordId<1 && $allRecords){
            $recordId=(int)$allRecords[0]['id'];
            $record=DataFormRecordStore::find($pdo,$dataformId,$recordId);
        }
        if($recordId>0 && $record!==null){
            $activeRecordId=$recordId;
            $mode=$allowEdit?'edit':'detail';
        } elseif($allowCreate) {
            $mode='create';
        }
    }

    if ($mode==='list') {
        $inlineCurrentData=[];
        foreach ($inlineCreateValues as $inlineName=>$inlineValue) {
            if (is_array($inlineValue)) {
                $inlineCurrentData[(string)$inlineName]=implode(',',array_map('strval',$inlineValue));
            } elseif (is_scalar($inlineValue)) {
                $inlineCurrentData[(string)$inlineName]=(string)$inlineValue;
            }
        }
        $inlineParentId=df_effective_parent_record_id(
            $fields,
            $parentRelationsByLookupFieldId,
            $inlineCurrentData,
            null
        );
        $derivedOptionsByField=df_derived_options_for_fields(
            $pdo,
            $fields,
            $dataformId,
            0,
            $inlineParentId,
            $inlineCurrentData
        );
    }

    if (in_array($mode,['create','edit'],true)) {
        $currentRenderData=(array)($record['data']??[]);
        $effectiveParentRecordId=df_effective_parent_record_id(
            $fields,
            $parentRelationsByLookupFieldId,
            $currentRenderData,
            $masterContext
        );
        $derivedOptionsByField=df_derived_options_for_fields(
            $pdo,
            $fields,
            $dataformId,
            $recordId,
            $effectiveParentRecordId,
            $currentRenderData
        );

        foreach ($fields as $derivedField) {
            if ((string)$derivedField['field_type']!==DerivedMultiEnumManager::FIELD_TYPE) {
                continue;
            }
            $name=(string)$derivedField['name'];
            $stored=(string)($currentRenderData[$name]??'');
            $available=[];
            foreach ((array)($derivedOptionsByField[$name]??[]) as $option) {
                $available[(string)$option['value']]=true;
            }
            $missing=[];
            foreach (DerivedMultiEnumManager::canonicalizeSelection($stored) as $selectedValue) {
                if (!isset($available[$selectedValue])) {
                    $missing[]=$selectedValue;
                }
            }
            $derivedMissingByField[$name]=$missing;
        }
    }

    if (
        $recordId>0
        && $record!==null
        && in_array($mode,['detail','edit'],true)
    ) {
        $childRelationStmt=$pdo->prepare(
            "SELECT
                r.id,
                r.name,
                r.target_dataform_id,
                r.lookup_field_id,
                d.name AS child_dataform_name
             FROM dataform_relations r
             JOIN dataforms d
               ON d.id=r.target_dataform_id
             WHERE r.source_dataform_id=?
               AND r.relation_type='1:n'
               AND r.is_enabled=1
               AND r.lookup_field_id IS NOT NULL
             ORDER BY r.name,r.id"
        );
        $childRelationStmt->execute([$dataformId]);

        foreach(
            $childRelationStmt->fetchAll(PDO::FETCH_ASSOC)
            as $childRelation
        ) {
            $childDataformId=(int)$childRelation[
                'target_dataform_id'
            ];
            $lookupFieldId=(int)$childRelation[
                'lookup_field_id'
            ];

            $lookupStmt=$pdo->prepare(
                'SELECT id,name,label
                 FROM dataform_fields
                 WHERE id=? AND dataform_id=?
                 LIMIT 1'
            );
            $lookupStmt->execute([
                $lookupFieldId,
                $childDataformId,
            ]);
            $lookupField=$lookupStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lookupField) {
                continue;
            }

            $childFieldStmt=$pdo->prepare(
                'SELECT *
                 FROM dataform_fields
                 WHERE dataform_id=?
                 ORDER BY position,id'
            );
            $childFieldStmt->execute([$childDataformId]);

            $childFields=[];
            foreach(
                $childFieldStmt->fetchAll(PDO::FETCH_ASSOC)
                as $childField
            ) {
                $childField['_config']=df_field_config(
                    $childField['configuration_json']??null,
                    (string)($childField['field_type']??'text')
                );
                $childFields[]=$childField;
            }

            $childListFields=array_values(array_filter(
                $childFields,
                static fn(array $field): bool =>
                    (int)$field['id']!==$lookupFieldId
                    && empty($field['_config']['hidden'])
                    && !empty($field['_config']['list_visible'])
            ));
            $childListFields=array_slice(
                $childListFields,
                0,
                4
            );

            $childRecords=array_values(array_filter(
                DataFormRecordStore::all(
                    $pdo,
                    $childDataformId
                ),
                static function(array $childRecord) use (
                    $lookupField,
                    $recordId
                ): bool {
                    return (string)(
                        $childRecord['data'][
                            (string)$lookupField['name']
                        ]??''
                    )===(string)$recordId;
                }
            ));

            $childCollections[]=[
                'relation_id'=>(int)$childRelation['id'],
                'relation_name'=>(string)$childRelation['name'],
                'child_dataform_id'=>$childDataformId,
                'child_dataform_name'=>
                    (string)$childRelation['child_dataform_name'],
                'lookup_field_id'=>$lookupFieldId,
                'lookup_field_name'=>(string)$lookupField['name'],
                'lookup_field_label'=>(string)$lookupField['label'],
                'fields'=>$childListFields,
                'records'=>$childRecords,
                'storage_mode'=>
                    DataFormRecordStore::isPhysical(
                        $pdo,
                        $childDataformId
                    ) ? 'physical' : 'generic',
            ];
        }
    }

    $q = $showSearch ? trim((string)($_GET['q'] ?? '')) : '';
    $filters = $showFilter && isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
    $records = array_values(array_filter($allRecords, static function(array $r) use ($q,$filters,$searchableNames,$filterableNames): bool {
        if($q!==''){$parts=[];foreach($searchableNames as $name)$parts[]=(string)($r['data'][$name]??'');$haystack=mb_strtolower(implode(' ',$parts).' '.$r['id']);if(!str_contains($haystack,mb_strtolower($q)))return false;}
        foreach($filters as $name=>$needle){if(!in_array((string)$name,$filterableNames,true))continue;$needle=trim((string)$needle);if($needle==='')continue;$value=mb_strtolower((string)($r['data'][$name]??''));if(!str_contains($value,mb_strtolower($needle)))return false;}
        return true;
    }));

    $allowedSorts = array_merge(['id','created_at','updated_at'], $sortableNames);
    if (!in_array($sort, $allowedSorts, true)) $sort = 'id';
    usort($records, static function(array $a, array $b) use ($sort, $dir): int {
        $av = in_array($sort, ['id','created_at','updated_at'], true) ? $a[$sort] : ($a['data'][$sort] ?? '');
        $bv = in_array($sort, ['id','created_at','updated_at'], true) ? $b[$sort] : ($b['data'][$sort] ?? '');
        if ($sort === 'id') $cmp = (int)$av <=> (int)$bv;
        elseif (is_numeric($av) && is_numeric($bv)) $cmp = (float)$av <=> (float)$bv;
        else $cmp = strnatcasecmp((string)$av, (string)$bv);
        return $dir === 'asc' ? $cmp : -$cmp;
    });

    // HF76-FIX16: Ein gerade inline angelegter Datensatz wird fuer genau
    // diese Antwort ans Ende der gefilterten Ergebnismenge gepinnt. So bleibt
    // die Eingabelogik "neuer DS am Tabellenende" auch bei Sortierung DESC
    // konsistent. Bei der naechsten normalen Anforderung gilt wieder die
    // gewaehlte Sortierung ohne Sonderbehandlung.
    if ($inlineCreatedRecordId > 0) {
        $createdIndex = null;
        foreach ($records as $idx => $candidate) {
            if ((int)($candidate['id'] ?? 0) === $inlineCreatedRecordId) {
                $createdIndex = $idx;
                break;
            }
        }
        if ($createdIndex !== null) {
            $createdRecord = $records[$createdIndex];
            array_splice($records, $createdIndex, 1);
            $records[] = $createdRecord;
        }
    }
    $filteredCount = count($records);
    // HF69: Datensatz-Sprungziele fuer die kompakte, um die aktuelle Seite
    // zentrierte Seitennavigation. Grundlage ist immer die aktuell gefilterte
    // und sortierte Ergebnismenge.
    $firstFilteredRecordId = $filteredCount > 0 ? (int)$records[0]['id'] : 0;
    $lastFilteredRecordId = $filteredCount > 0 ? (int)$records[$filteredCount - 1]['id'] : 0;
    $filteredRecords = $records;
    if ((string)($_GET['export'] ?? '') === 'csv') {
        $exportIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), static fn(int $id): bool => $id > 0)));
        if ($exportIds) $filteredRecords = array_values(array_filter($filteredRecords, static fn(array $row): bool => in_array((int)$row['id'], $exportIds, true)));
        $filename = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$dataform['name']) . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['ID'], array_map(static fn(array $f): string => (string)$f['label'], $detailFields), ['Erstellt','Geändert']), ';');
        $csvStorage=new DataFormFieldStorageManager(dirname(__DIR__,2));
        foreach ($filteredRecords as $row) {
            $line = [(int)$row['id']];
            foreach ($detailFields as $field) {
                $rawValue=(string)($row['data'][(string)$field['name']] ?? '');
                $line[] = DataFormTransport::csvValue($field,$rawValue,$csvStorage);
            }
            $line[] = (string)$row['created_at']; $line[] = (string)$row['updated_at'];
            fputcsv($out, $line, ';');
        }
        fclose($out); exit;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageCount = max(1, (int)ceil($filteredCount / $perPage));
    if ($inlineCreatedRecordId > 0) {
        $page = $pageCount;
    }
    $page = min($page, $pageCount);
    $records = array_slice($records, ($page - 1) * $perPage, $perPage);

    // HF67: Die Inline-Neuzeile gehoert zum Ende der gesamten aktuell
    // angezeigten Ergebnismenge und darf deshalb nur auf der letzten Seite
    // erscheinen. Bei einer leeren Ergebnismenge ist Seite 1 zugleich die
    // letzte Seite, sodass weiterhin direkt ein erster Datensatz erfasst
    // werden kann.
    // compatibility: $showInlineCreateRow = $page === $pageCount;
    $showInlineCreateRow = $allowCreate && $viewMode==='table' && $page === $pageCount;

    // HF56: In der Tabellenansicht gibt es genau einen aktiven Datensatzzeiger.
    // Ohne explizite Auswahl wird der erste sichtbare Datensatz aktiv. Ist eine
    // zuvor gewählte ID durch Filter/Seitenwechsel nicht sichtbar, fällt der
    // Zeiger ebenfalls auf den ersten sichtbaren Datensatz zurück.
    if ($records) {
        $visibleRecordIds=array_map(static fn(array $row): int => (int)$row['id'],$records);
        if ($activeRecordId<1 || !in_array($activeRecordId,$visibleRecordIds,true)) {
            $activeRecordId=(int)$records[0]['id'];
        }
    } else {
        $activeRecordId=0;
    }

    // PUBLISH13: Navigator für die echte Formularansicht verwendet die
    // vollständige gefilterte/sortierte Ergebnismenge, nicht nur die aktuelle
    // Tabellenseite.
    $formViewRecordIds=array_map(
        static fn(array $row): int => (int)$row['id'],
        $filteredRecords
    );
    if($formViewRecordIds){
        $formViewFirstId=(int)$formViewRecordIds[0];
        $formViewLastId=(int)$formViewRecordIds[count($formViewRecordIds)-1];
        $formCurrentId=$recordId>0?$recordId:$activeRecordId;
        $formIndex=array_search($formCurrentId,$formViewRecordIds,true);
        if($formIndex===false)$formIndex=0;
        $formViewPrevId=$formIndex>0?(int)$formViewRecordIds[$formIndex-1]:0;
        $formViewNextId=$formIndex<count($formViewRecordIds)-1?(int)$formViewRecordIds[$formIndex+1]:0;
    }
    $dialogInitialRecordId=$activeRecordId>0?$activeRecordId:($formViewFirstId>0?$formViewFirstId:0);
} catch (Throwable $e) { $error = $e->getMessage(); }

$fieldByName = [];
foreach ($fields as $field) $fieldByName[(string)$field['name']] = $field;
$sortUrl = static function(string $key) use ($sort, $dir): string {
    $newDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    return df_query(['sort'=>$key,'dir'=>$newDir,'page'=>1]);
};

ob_start();
?>
<style id="dataform-runtime-base-css">
.df-runtime-dialog{border:0;border-radius:14px;padding:0;max-height:94vh;overflow:hidden}.df-runtime-dialog::backdrop{background:rgba(0,0,0,.45)}.df-runtime-dialog.size-small{width:min(520px,92vw)}.df-runtime-dialog.size-medium{width:min(760px,94vw)}.df-runtime-dialog.size-large{width:min(1120px,96vw)}.df-runtime-dialog.size-fullscreen{width:96vw;height:94vh;max-width:none}.df-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid #d6e0ec;background:#f7f9fc}.df-dialog-close{border:0;background:transparent;font-size:1.6rem;line-height:1;cursor:pointer}.df-dialog-body{clear:both;min-height:320px}.df-dialog-frame{display:block;width:100%;height:min(78vh,900px);border:0;background:#fff}.df-runtime-view-state{margin:.5rem 0 1rem;padding:.55rem .75rem;border:1px solid #d6e0ec;border-radius:.55rem;background:#f8fbff}
/* PUBLISH15: echte kompakte Formularnavigation. Der aktuelle DS-Marker ist ein
   eigener 34px-Container; dadurch kann die globale IMG-Regel ihn nicht mehr
   auf die natürliche 128px-PNG-Größe aufziehen. */
.df-form-record-nav{display:grid;grid-template-columns:auto auto minmax(170px,1fr) auto auto auto;align-items:center;gap:.35rem;margin:0 0 1rem;padding:.55rem .7rem;border:1px solid #d6e0ec;border-radius:.65rem;background:#fff;min-height:54px}
.df-form-record-nav>a.easyit-image-button{--easyit-image-button-size:34px;--easyit-record-pointer-size:34px;margin:0!important}
.df-form-current-record{display:flex;align-items:center;justify-content:center;gap:.55rem;min-width:0;padding:0 .65rem;color:#10233f}
.df-form-current-marker{display:inline-flex;align-items:center;justify-content:center;flex:0 0 34px;width:34px;height:34px;min-width:34px;min-height:34px;max-width:34px;max-height:34px;overflow:hidden}
html body .df-form-current-marker>img.easyit-button-image{width:34px!important;height:34px!important;min-width:34px!important;min-height:34px!important;max-width:34px!important;max-height:34px!important;object-fit:contain!important}
.df-form-current-meta{display:flex;flex-direction:column;min-width:0;line-height:1.15}.df-form-current-meta span{font-size:.75rem;color:#66768a;font-weight:600}.df-form-current-meta strong{font-size:.95rem;white-space:nowrap}
.df-runtime-form-card{padding:1rem 1.1rem}.df-runtime-form-card>h2{margin:.1rem 0 1rem}.df-generated-form{display:flex;flex-wrap:wrap;align-items:flex-start;gap:1rem 1.25rem}.df-generated-field{display:grid;gap:.38rem;flex:0 0 min(100%,var(--field-width,100%));width:min(100%,var(--field-width,100%));max-width:100%;min-width:min(240px,100%);margin:0}.df-generated-field>span{font-weight:750;color:#10233f}.df-generated-field input:not([type=checkbox]):not([type=radio]),.df-generated-field select,.df-generated-field textarea{width:100%;max-width:100%;box-sizing:border-box}.df-generated-field input[type=datetime-local],.df-generated-field input[type=date],.df-generated-field input[type=time],.df-generated-field input[type=number]{max-width:420px}.df-runtime-form-actions{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;margin:1rem 0 0;padding-top:.85rem;border-top:1px solid #e1e8f0}.df-runtime-form-actions .button{margin:0!important}.df-master-preselected{width:100%;max-width:520px;background:#f3f7fb;border-color:#8eabc8;font-weight:700;color:#163a5f}.df-master-preselected:disabled{opacity:1;color:#163a5f;cursor:not-allowed}
.df-record-pagination-row td{padding:.35rem .4rem!important;background:#fff;border-bottom:1px solid #d6e0ec}.df-record-pagination-row .pagination{margin:0;align-items:center}.df-record-pagination-row .df-pagination-status{color:#66768a;white-space:nowrap}
@media(max-width:720px){.df-form-record-nav{grid-template-columns:auto auto minmax(110px,1fr) auto auto}.df-form-record-nav .df-form-new-record{grid-column:1/-1;justify-self:end}.df-form-current-record{padding:0 .25rem}.df-form-current-meta span{display:none}.df-generated-field{flex-basis:100%;width:100%}}
</style>
<?php if($additionalCss!==''): ?><style id="dataform-addcss"><?= $additionalCss ?></style><?php endif; ?>
<div class="dataform-runtime <?= e($dataformCssClass) ?>" data-view-mode="<?=e($viewMode)?>" data-dialog-size="<?=e($dialogSize)?>" data-allow-create="<?=$allowCreate?'1':'0'?>" data-allow-edit="<?=$allowEdit?'1':'0'?>" data-allow-delete="<?=$allowDelete?'1':'0'?>">
<?php render_breadcrumbs([
 ['label'=>'Enterprise','href'=>'../../app/dashboard.php'],
 ['label'=>'Projekte','href'=>'../../app/projects/index.php'],
 ['label'=>(string)($project['name'] ?? 'DataForm'),'href'=>$project ? '../../app/projects/view.php?id='.(int)$project['id'] : ''],
 ['label'=>'Workspace','href'=>$project ? 'index.php?project='.(int)$project['id'].'&section=dataforms' : ''],
 ['label'=>(string)($dataform['name'] ?? 'Datensätze'),'href'=>''],
]); ?>
<div class="df-workspace records-workspace">
<header class="df-workspace-header"><div><span class="badge">DataForm Workspace</span><?php /* Compatibility marker: DataForm Workspace · HF76 */ ?><?php /* DataForm Workspace · HF75 compatibility */ ?><?php /* Compatibility marker: DataForm Workspace · HF74 */ ?><?php /* Compatibility marker: DataForm Workspace · HF69 */ ?><?php /* Compatibility marker: DataForm Workspace · HF68 */ ?><?php /* Compatibility marker: DataForm Workspace · HF67 */ ?><?php /* Compatibility marker: DataForm Workspace · HF66 */ ?><?php /* Compatibility marker: DataForm Workspace · HF65 */ ?><?php /* Compatibility marker: DataForm Workspace · HF64 */ ?><?php /* Compatibility marker: DataForm Workspace · HF63 */ ?><?php /* Compatibility markers: DataForm Workspace · HF62; DataForm Workspace · HF61; DataForm Workspace · HF60; DataForm Workspace · HF59; DataForm Workspace · HF36 */ ?><h1><?= e((string)($dataform['name'] ?? 'Datensätze')) ?></h1></div><div class="df-workspace-actions"><a class="button secondary" <?= easyit_button_attributes('formular','dataform') ?> href="index.php?project=<?= $projectId ?>&amp;section=designer&amp;dataform=<?= $dataformId ?>">Formular-Designer</a><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php?project=<?= $projectId ?>&amp;section=dataforms">DataForms</a></div></header>
<div class="df-editor-content records-content">
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice success"><?= e($success) ?></div><?php endif; ?>
<?php if ($saveSuccessDialog !== ''): ?>
<dialog id="df-save-success-dialog" class="df-save-dialog" aria-labelledby="df-save-dialog-title" aria-describedby="df-save-dialog-message">
  <div class="df-save-dialog-body">
    <div class="df-save-dialog-icon" aria-hidden="true">✓</div>
    <div class="df-save-dialog-content">
      <h2 id="df-save-dialog-title">Speichern erfolgreich</h2>
      <p id="df-save-dialog-message"><?= e($saveSuccessDialog) ?></p>
    </div>
  </div>
  <form method="dialog" class="df-save-dialog-actions">
    <button type="submit" class="button" value="ok" autofocus>OK</button>
  </form>
</dialog>
<script>
(function(){
  function showSaveDialog(){
    var dialog=document.getElementById('df-save-success-dialog');
    if(!dialog) return;
    if(typeof dialog.showModal==='function') {
      if(!dialog.open) dialog.showModal();
      return;
    }
    /* Fallback fuer sehr alte Browser: Dialog bleibt als blockierender Hinweis sichtbar. */
    dialog.setAttribute('open','open');
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',showSaveDialog,{once:true});
  else showSaveDialog();
})();
</script>
<?php endif; ?>
<?php if ($dataform): ?>
<?php if(!$previewMode): ?>
<nav class="df-subnav"><a class="<?= $mode==='list'?'active':'' ?>" href="<?= e(df_query(['runtime_view'=>'table','mode'=>'list','record'=>null,'active_record'=>null,'page'=>1])) ?>">Tabelle</a><?php if($allowCreate): ?><?php if($viewMode==='dialog'): ?><a href="#" data-dialog-create="1">Neu</a><?php else: ?><a class="df-new-record-action" data-new-record-action="1" href="<?= e($viewMode==='table'?df_query(['mode'=>'list','page'=>$pageCount??1,'record'=>null]):df_query(['runtime_view'=>'form','mode'=>'create','record'=>null])) ?><?= $viewMode==='table'?'#record-new-row':'' ?>">Neu</a><?php endif; ?><?php endif; ?><?php if($record): ?><a class="<?= $mode==='detail'?'active':'' ?>" href="<?= e(df_query(['runtime_view'=>'form','record'=>$recordId,'active_record'=>$recordId,'mode'=>'detail','page'=>null])) ?>">Detail</a><a class="<?= $mode==='edit'?'active':'' ?>" href="<?= e(df_query(['runtime_view'=>'form','record'=>$recordId,'active_record'=>$recordId,'mode'=>'edit','page'=>null])) ?>">Formular</a><?php endif; ?><a href="import.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>">CSV-Import</a></nav>
<?php endif; ?>

<div class="df-runtime-view-state" role="status">
  <strong>Aktive Ansicht:</strong>
  <?= $viewMode==='form'?'Formular':($viewMode==='dialog'?'Dialog':'Tabelle') ?>
  <?php if($previewMode): ?><span class="muted">(Realvorschau der aktuell gewählten DataForm-Ansicht)</span><?php elseif($runtimeViewOverride===''): ?><span class="muted">(Standardansicht des DataForms)</span><?php else: ?><span class="muted">(temporär überschrieben)</span><?php endif; ?>
</div>

<?php if($viewMode==='form' && in_array($mode,['edit','detail'],true) && $recordId>0): ?>
<nav class="df-form-record-nav" aria-label="Datensatznavigation Formularansicht">
  <?php if($formViewFirstId>0): ?><a class="easyit-image-button" <?= easyit_button_attributes('erster_ds') ?> href="<?= e(df_query(['runtime_view'=>'form','mode'=>$allowEdit?'edit':'detail','record'=>$formViewFirstId,'active_record'=>$formViewFirstId,'page'=>null])) ?>"><?= easyit_button_image_html('erster_ds','../../') ?><span class="sr-only">Erster Datensatz</span></a><?php endif; ?>
  <?php if($formViewPrevId>0): ?><a class="easyit-image-button" <?= easyit_button_attributes('vorheriger_ds') ?> href="<?= e(df_query(['runtime_view'=>'form','mode'=>$allowEdit?'edit':'detail','record'=>$formViewPrevId,'active_record'=>$formViewPrevId,'page'=>null])) ?>"><?= easyit_button_image_html('vorheriger_ds','../../') ?><span class="sr-only">Vorheriger Datensatz</span></a><?php endif; ?>
  <span class="df-form-current-record" aria-current="true"><span class="df-form-current-marker" title="<?= e(easyit_button_title('aktueller_ds')) ?>"><?= easyit_button_image_html('aktueller_ds','../../') ?></span><span class="df-form-current-meta"><span>Aktueller Datensatz</span><strong>#<?= (int)$recordId ?></strong></span></span>
  <?php if($formViewNextId>0): ?><a class="easyit-image-button" <?= easyit_button_attributes('naechster_ds') ?> href="<?= e(df_query(['runtime_view'=>'form','mode'=>$allowEdit?'edit':'detail','record'=>$formViewNextId,'active_record'=>$formViewNextId,'page'=>null])) ?>"><?= easyit_button_image_html('naechster_ds','../../') ?><span class="sr-only">Nächster Datensatz</span></a><?php endif; ?>
  <?php if($formViewLastId>0): ?><a class="easyit-image-button" <?= easyit_button_attributes('letzter_ds') ?> href="<?= e(df_query(['runtime_view'=>'form','mode'=>$allowEdit?'edit':'detail','record'=>$formViewLastId,'active_record'=>$formViewLastId,'page'=>null])) ?>"><?= easyit_button_image_html('letzter_ds','../../') ?><span class="sr-only">Letzter Datensatz</span></a><?php endif; ?>
  <?php if($allowCreate): ?><a class="easyit-image-button df-form-new-record" <?= easyit_button_attributes('neuer_ds') ?> data-button-fixed="1" href="<?= e(df_query(['runtime_view'=>'form','mode'=>'create','record'=>null,'active_record'=>null,'page'=>null])) ?>"><?= easyit_button_image_html('neuer_ds','../../') ?><span class="sr-only">Neuer Datensatz</span></a><?php endif; ?>
</nav>
<?php endif; ?>

<?php if ($mode === 'list'): ?>
<div class="df-toolbar"><div><h2>Datensätze</h2><p><?= (int)($filteredCount ?? 0) ?> von <?= count($allRecords ?? []) ?> Datensätzen angezeigt. · Speichern: <strong><?= $tableSaveMode==='adhoc'?'Ad hoc':'manuell' ?></strong><?= $showSaveSuccess?' · Erfolgsmeldung an':' · Erfolgsmeldung aus' ?></p></div><?php if($allowCreate): ?><?php if($viewMode==='dialog'): ?><button type="button" class="button" <?= easyit_button_attributes('neu','record') ?> data-button-fixed="1" data-crud="new" data-dialog-create="1"><?= easyit_button_image_html('neu','../../') ?>Neuer Datensatz</button><?php else: ?><a class="button df-new-record-action" <?= easyit_button_attributes('neu','record') ?> data-button-fixed="1" data-crud="new" data-new-record-action="1" href="<?= e(df_query(['mode'=>'list','page'=>$pageCount??1,'record'=>null])) ?>#record-new-row"><?= easyit_button_image_html('neu','../../') ?>Neuer Datensatz</a><?php endif; ?><?php endif; ?></div>
<?php if($showSearch || $showFilter): ?>
<form class="record-search-panel" method="get">
<input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="mode" value="list">
<?php if($showSearch): ?><div class="record-search-main"><label>Volltextsuche<input type="search" name="q" value="<?= e($q) ?>" placeholder="Alle durchsuchbaren Inhalte durchsuchen"></label><button class="button" <?= easyit_button_attributes('suchen') ?> type="submit">Suchen</button></div><?php endif; ?>
<?php if($showFilter): ?><details <?= !empty(array_filter($filters ?? [])) ? 'open' : '' ?>><summary>Feldfilter</summary><div class="record-filter-grid"><?php foreach($filterFields as $field): ?><label><?= e((string)$field['label']) ?><input type="text" name="filter[<?= e((string)$field['name']) ?>]" value="<?= e((string)($filters[$field['name']] ?? '')) ?>" placeholder="enthält …"></label><?php endforeach; ?></div><p><button class="button" <?= easyit_button_attributes('filter','filter') ?> type="submit">Filter anwenden</button></p></details><?php endif; ?>
<div class="record-search-actions"><a class="button secondary" <?= easyit_button_attributes('filter_loeschen','filter') ?> href="?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;mode=list">Suche/Filter zurücksetzen</a></div>
</form>
<?php endif; ?>
<?php if($showFilter): ?>
<section class="saved-filter-bar">
<div><strong>Gespeicherte Filter</strong><div class="saved-filter-list"><?php if($savedFilters): foreach($savedFilters as $sf): ?><span class="saved-filter-item"><a href="<?= e(df_query(['saved_filter'=>(int)$sf['id'],'page'=>1])) ?>"><?= e((string)$sf['name']) ?></a><form method="post" class="inline-form" onsubmit="return confirm('Gespeicherten Filter löschen?');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="delete_filter"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="filter_id" value="<?= (int)$sf['id'] ?>"><button type="submit" class="link-danger" title="Filter löschen">×</button></form></span><?php endforeach; else: ?><span class="muted">Noch keine Filter gespeichert.</span><?php endif; ?></div></div>
<form method="post" class="save-filter-form"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="save_filter"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="current_q" value="<?= e($q) ?>"><input type="hidden" name="current_sort" value="<?= e($sort) ?>"><input type="hidden" name="current_dir" value="<?= e($dir) ?>"><?php foreach(($filters??[]) as $fn=>$fv): ?><input type="hidden" name="current_filter[<?= e((string)$fn) ?>]" value="<?= e((string)$fv) ?>"><?php endforeach; ?><input type="text" name="filter_name" maxlength="120" placeholder="Name des aktuellen Filters"><button class="button secondary" type="submit">Filter speichern</button></form>
</section>
<?php endif; ?>
<div class="export-actions"><a class="button secondary" href="<?= e(df_query(['export'=>'csv','page'=>null])) ?>">Aktuelle Treffer als CSV</a></div>
<details class="column-settings"><summary><?= $isPhysicalTableView ? 'Listenspalten – alle Tabellenfelder sichtbar' : 'Listenspalten konfigurieren' ?></summary><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="save_columns"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><?php if($isPhysicalTableView): ?><p class="muted">Physisch gebundenes DataForm: In der tabellarischen Datenansicht werden immer alle Tabellenfelder angezeigt. Die Felder koennen hier daher nicht ausgeblendet werden.</p><div class="column-options"><?php foreach($listFields as $field): ?><input type="hidden" name="columns[]" value="<?= e((string)$field['name']) ?>"><label class="checkbox-line"><input type="checkbox" checked disabled><span><?= e((string)$field['label']) ?> <code><?= e((string)$field['name']) ?></code></span></label><?php endforeach; ?></div><?php else: ?><div class="column-options"><?php foreach($listFields as $field): ?><label class="checkbox-line"><input type="checkbox" name="columns[]" value="<?= e((string)$field['name']) ?>" <?= in_array((string)$field['name'],$visibleColumns,true)?'checked':'' ?>><span><?= e((string)$field['label']) ?></span></label><?php endforeach; ?></div><?php endif; ?><label>Datensätze pro Seite<select name="per_page"><?php foreach([1,5,10,20,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></label><p><button class="button" type="submit">Listenansicht speichern</button></p></form></details>
<?php $inlineCreateFormId='df-inline-create-'.$dataformId; ?>
<?php if($showInlineCreateRow??true): ?>
<form id="<?= e($inlineCreateFormId) ?>" method="post" class="df-inline-create-form" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
<input type="hidden" name="action" value="save_record">
<input type="hidden" name="inline_create" value="1">
<input type="hidden" name="project" value="<?= $projectId ?>">
<input type="hidden" name="dataform" value="<?= $dataformId ?>">
<input type="hidden" name="record" value="0">
</form>
<?php endif; ?>
<?php foreach($records as $editFormRecord):
    $editFormRecordId=(int)$editFormRecord['id'];
    $inlineEditFormId='df-inline-edit-'.$dataformId.'-'.$editFormRecordId;
    $inlineDeleteFormId='df-inline-delete-'.$dataformId.'-'.$editFormRecordId;
?>
<form id="<?= e($inlineEditFormId) ?>" method="post" class="df-inline-edit-form" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
<input type="hidden" name="action" value="save_record">
<input type="hidden" name="inline_edit" value="1">
<input type="hidden" name="project" value="<?= $projectId ?>">
<input type="hidden" name="dataform" value="<?= $dataformId ?>">
<input type="hidden" name="record" value="<?= $editFormRecordId ?>">
<input type="hidden" name="active_record" value="<?= $editFormRecordId ?>">
</form>
<form id="<?= e($inlineDeleteFormId) ?>" method="post" class="df-inline-delete-form" onsubmit="return confirm('Datensatz #<?= $editFormRecordId ?> wirklich löschen?');">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
<input type="hidden" name="action" value="delete_record">
<input type="hidden" name="project" value="<?= $projectId ?>">
<input type="hidden" name="dataform" value="<?= $dataformId ?>">
<input type="hidden" name="record" value="<?= $editFormRecordId ?>">
</form>
<?php endforeach; ?>
<form method="post" class="bulk-record-form" onsubmit="return confirm('Ausgewählte Datensätze wirklich löschen?');">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
<input type="hidden" name="action" value="bulk_delete">
<input type="hidden" name="project" value="<?= $projectId ?>">
<input type="hidden" name="dataform" value="<?= $dataformId ?>">
<?php
// PUBLISH18: Die Paginierung gehört direkt unter die gespeicherten
// Datensätze und ausdrücklich vor die *-Neuzeile.
$recordTableColumnCount=count($visibleColumns)+5;
// HF76-FIX14 compatibility: if(($filteredCount??0)>0)
$renderRecordPagination=($filteredCount??0)>0 && $showPagination;
if($renderRecordPagination){
    $paginationStart=max(1,$page-2);
    $paginationEnd=min($pageCount,$page+2);
    $firstRecordUrl=$firstFilteredRecordId>0
        ? df_query(['page'=>1,'active_record'=>$firstFilteredRecordId]).'#record-row-'.$firstFilteredRecordId
        : df_query(['page'=>1,'active_record'=>null]);
    $lastRecordUrl=$lastFilteredRecordId>0
        ? df_query(['page'=>$pageCount,'active_record'=>$lastFilteredRecordId]).'#record-row-'.$lastFilteredRecordId
        : df_query(['page'=>$pageCount,'active_record'=>null]);
}
?>
<?php if($records): ?>
<div class="bulk-toolbar"><button class="button" <?= easyit_button_attributes('loeschen','bulk') ?> data-button-fixed="1" data-crud="delete" type="submit"><?= easyit_button_image_html('loeschen','../../') ?>Ausgewählte löschen</button><span class="muted">Mehrfachauswahl für Sammelaktionen</span></div>
<?php endif; ?>
<div class="df-field-table-wrap"><table class="df-field-table records-table">
<thead><tr>
<th class="df-record-pointer-head" aria-label="Datensatzzeiger" title="Datensatzzeiger"></th>
<th><input type="checkbox" id="select-all-records" aria-label="Alle Datensätze auswählen" <?= $records?'':'disabled' ?>></th>
<th><a href="<?= e($sortUrl('id')) ?>">ID<?= $sort==='id'?($dir==='asc'?' ↑':' ↓'):'' ?></a></th>
<?php foreach($visibleColumns as $column): $field=$fieldByName[$column]??null; if(!$field)continue; ?><th><a href="<?= e($sortUrl($column)) ?>"><?= e((string)$field['label']) ?><?= $sort===$column?($dir==='asc'?' ↑':' ↓'):'' ?></a></th><?php endforeach; ?>
<th><a href="<?= e($sortUrl('updated_at')) ?>">Geändert<?= $sort==='updated_at'?($dir==='asc'?' ↑':' ↓'):'' ?></a></th>
<th>Aktionen</th>
</tr></thead>
<tbody>
<?php foreach($records as $r):
    $rowId=(int)$r['id'];
    $isActive=$rowId===$activeRecordId;
    $inlineEditFormId='df-inline-edit-'.$dataformId.'-'.$rowId;
    $inlineDeleteFormId='df-inline-delete-'.$dataformId.'-'.$rowId;
    $rowValues=array_key_exists($rowId,$inlineEditValuesByRecord)
        ? (array)$inlineEditValuesByRecord[$rowId]
        : (array)$r['data'];
    $rowCurrentData=[];
    foreach($rowValues as $rowValueName=>$rowValueRaw){
        if(is_array($rowValueRaw)){
            $rowCurrentData[(string)$rowValueName]=implode(',',array_map('strval',$rowValueRaw));
        } elseif(is_scalar($rowValueRaw)){
            $rowCurrentData[(string)$rowValueName]=(string)$rowValueRaw;
        }
    }
    $rowParentId=df_effective_parent_record_id($fields,$parentRelationsByLookupFieldId,$rowCurrentData,null);
    $rowDerivedOptions=df_derived_options_for_fields($pdo,$fields,$dataformId,$rowId,$rowParentId,$rowCurrentData);
?>
<tr id="record-row-<?= $rowId ?>" class="<?= $isActive?'selected-row df-record-active-row':'' ?>" data-record-id="<?= $rowId ?>">
<td class="df-record-pointer-cell"><button type="button" class="df-record-pointer <?= $isActive?'active':'' ?>" <?= easyit_button_attributes($isActive?'aktueller_ds':'normaler_ds') ?> data-button-fixed="1" data-record-pointer-id="<?= $rowId ?>" aria-pressed="<?= $isActive?'true':'false' ?>"><?= easyit_button_image_html($isActive?'aktueller_ds':'normaler_ds','../../') ?></button></td>
<td><input type="checkbox" name="record_ids[]" value="<?= $rowId ?>" aria-label="Datensatz <?= $rowId ?> auswählen"></td>
<td><?= $rowId ?></td>
<?php foreach($visibleColumns as $column): $field=$fieldByName[$column]??null; if(!$field)continue; ?><?php if($viewMode==='dialog'): ?><td class="df-record-dialog-value-cell"><?= df_render_record_value_html($pdo,$field,df_record_value((array)$r['data'],(string)$field['name']),$projectId,$dataformId,$rowId) ?></td><?php else: ?><td class="df-record-inline-edit-cell"><?= df_record_inline_create_control($pdo,$field,$parentRelationsByLookupFieldId,$parentRecordOptionsByLookupFieldId,$rowDerivedOptions,$inlineEditFormId,$rowValues,'df-inline-create-control df-inline-edit-control',$masterContext) ?></td><?php endif; ?><?php endforeach; ?>
<td><?= e((string)$r['updated_at']) ?></td>
<td class="df-record-inline-edit-actions"><?php if($viewMode==='dialog'): ?><button type="button" class="button" <?= easyit_button_attributes('anzeigen','record') ?> data-button-fixed="1" data-crud="show" data-dialog-record="<?= $rowId ?>"><?= easyit_button_image_html('anzeigen','../../') ?>Dialog öffnen</button><?php if($allowDelete): ?> <button class="button danger" <?= easyit_button_attributes('loeschen','record') ?> data-button-fixed="1" data-crud="delete" type="submit" form="<?= e($inlineDeleteFormId) ?>"><?= easyit_button_image_html('loeschen','../../') ?>Löschen</button><?php endif; ?><?php else: ?><button class="button" <?= easyit_button_attributes('speichern','record') ?> data-button-fixed="1" data-crud="save" type="submit" form="<?= e($inlineEditFormId) ?>"><?= easyit_button_image_html('speichern','../../') ?>Speichern</button> <?php if($tableSaveMode==='adhoc'): ?><span class="badge" title="Aenderungen werden zusaetzlich beim Verlassen bzw. Aendern eines Feldes automatisch gespeichert. Der Speichern-Button bleibt jederzeit verfuegbar.">Ad hoc</span> <?php endif; ?><a class="button" <?= easyit_button_attributes('anzeigen','record') ?> data-button-fixed="1" data-crud="show" href="?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;record=<?= $rowId ?>&amp;mode=detail"><?= easyit_button_image_html('anzeigen','../../') ?>Anzeigen</a> <?php if($allowDelete): ?><button class="button danger df-record-delete-button" <?= easyit_button_attributes('loeschen','record') ?> data-button-fixed="1" data-crud="delete" type="submit" form="<?= e($inlineDeleteFormId) ?>"><?= easyit_button_image_html('loeschen','../../') ?>Löschen</button><?php endif; ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if($renderRecordPagination): ?>
<tr class="df-record-pagination-row">
<td colspan="<?= (int)$recordTableColumnCount ?>">
<nav class="pagination df-pagination-compact" aria-label="Datensatz- und Seitennavigation">
<a class="df-pagination-record-jump easyit-image-button" <?= easyit_button_attributes('erster_ds') ?> data-button-fixed="1" data-record-target="<?= (int)$firstFilteredRecordId ?>" href="<?= e($firstRecordUrl) ?>"><?= easyit_button_image_html('erster_ds','../../') ?><span class="sr-only">1. DS</span></a>
<?php if($paginationStart>1): ?><span class="df-pagination-ellipsis" aria-hidden="true">…</span><?php endif; ?>
<?php for($p=$paginationStart;$p<=$paginationEnd;$p++): ?>
<a class="df-pagination-page <?= $p===$page?'active':'' ?>" <?= $p===$page?'aria-current="page"':'' ?> aria-label="Seite <?= $p ?>" href="<?= e(df_query(['page'=>$p,'active_record'=>null])) ?>"><?= $p ?></a>
<?php endfor; ?>
<?php if($paginationEnd<$pageCount): ?><span class="df-pagination-ellipsis" aria-hidden="true">…</span><?php endif; ?>
<a class="df-pagination-record-jump easyit-image-button" <?= easyit_button_attributes('letzter_ds') ?> data-button-fixed="1" data-record-target="<?= (int)$lastFilteredRecordId ?>" href="<?= e($lastRecordUrl) ?>"><?= easyit_button_image_html('letzter_ds','../../') ?><span class="sr-only">Letzter DS</span></a>
<span class="df-pagination-status" aria-live="polite">Seite <?= (int)$page ?> von <?= (int)$pageCount ?></span>
</nav>
</td>
</tr>
<?php endif; ?>
<?php if($showInlineCreateRow??true): ?>
<tr class="df-record-new-row" id="record-new-row">
<td class="df-record-pointer-cell"><button type="button" class="df-record-pointer new" <?= easyit_button_attributes('neuer_ds') ?> data-button-fixed="1" data-record-pointer-id="new" aria-pressed="false"><?= easyit_button_image_html('neuer_ds','../../') ?></button></td>
<td></td>
<td class="df-record-new-id" aria-label="Neue Datensatz-ID"></td>
<?php foreach($visibleColumns as $column): $field=$fieldByName[$column]??null; if(!$field)continue; ?><td class="df-record-inline-create-cell"><?= df_record_inline_create_control($pdo,$field,$parentRelationsByLookupFieldId,$parentRecordOptionsByLookupFieldId,$derivedOptionsByField,$inlineCreateFormId,$inlineCreateValues,'df-inline-create-control',$masterContext) ?></td><?php endforeach; ?>
<td class="df-record-new-updated"><span class="muted" aria-label="Noch nicht gespeichert" title="Noch nicht gespeichert">—</span></td>
<td class="df-record-inline-create-actions"><button class="button" <?= easyit_button_attributes('neu','record') ?> data-button-fixed="1" data-crud="create" type="submit" form="<?= e($inlineCreateFormId) ?>"><?= easyit_button_image_html('neu','../../') ?>Datensatz anlegen</button></td>
</tr>
<?php endif; ?>
</tbody></table></div></form>
<?php if($tableSaveMode==='adhoc'): ?>
<script>
(function(){
  const controls=document.querySelectorAll('.df-inline-edit-control');
  controls.forEach(function(control){
    control.addEventListener('change',function(){
      if (control.disabled || control.readOnly) return;
      const formId=control.getAttribute('form');
      if (!formId) return;
      const form=document.getElementById(formId);
      if (!form || form.dataset.dfSubmitting==='1') return;
      if (typeof form.reportValidity==='function' && !form.reportValidity()) return;
      form.dataset.dfSubmitting='1';
      if (typeof form.requestSubmit==='function') form.requestSubmit();
      else form.submit();
    });
  });
})();
</script>
<?php endif; ?>
<?php elseif (in_array($mode,['create','edit'],true)): $values=$record['data']??[]; if($mode==='create'&&$inlineCreateValues)$values=array_replace($values,$inlineCreateValues); ?>
<section class="card df-runtime-form-card"><h2><?= $mode==='edit'?'Datensatz bearbeiten':'Neuen Datensatz erfassen' ?></h2><?php if(!$fields): ?><div class="notice warning">Legen Sie zuerst Felder an.</div><?php else: ?>
<?php if($masterContext!==null): ?>
<div class="notice info">
<strong>Kinddatensatz zu <?= e((string)$masterContext['parent_caption']) ?></strong>
<p>Die Zuordnung wird automatisch über <code><?= e((string)$masterContext['lookup_field_name']) ?> = <?= (int)$masterContext['parent_record_id'] ?></code> gespeichert.</p>
</div>
<?php endif; ?>
<form id="df-record-edit-form" method="post" enctype="multipart/form-data" data-record-mode="<?= e($mode) ?>"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="save_record"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="record" value="<?= $recordId ?>">
<?php if($masterContext!==null): ?>
<input type="hidden" name="parent_relation" value="<?= (int)$masterContext['relation_id'] ?>">
<input type="hidden" name="parent_record" value="<?= (int)$masterContext['parent_record_id'] ?>">
<input type="hidden" name="return_dataform" value="<?= (int)$returnDataformId ?>">
<input type="hidden" name="return_record" value="<?= (int)$returnRecordId ?>">
<?php endif; ?>
<div class="df-generated-form">
<?php foreach($fields as $field):
    $name=(string)$field['name'];
    $type=(string)$field['field_type'];
    $cfg=$field['_config']??df_field_config($field['configuration_json']??null,$type);
    $required=(int)$field['is_required']===1;
    $rawValue=array_key_exists($name,$values)?$values[$name]:df_server_default_value($field,$cfg);
    $scalarValue=is_scalar($rawValue)?(string)$rawValue:'';
    if(!empty($cfg['hidden'])):
?>
<input class="df-default-target" type="hidden" name="values[<?= e($name) ?>]" value="<?= e($scalarValue) ?>" form="df-record-edit-form" data-default-mode="<?= e((string)($cfg['default_mode']??'defined')) ?>" data-default-provider="<?= e((string)($cfg['default_js_provider']??'')) ?>" data-default-parent-relation="<?= (int)($cfg['parent_relation_id']??0) ?>" data-default-parent-field="<?= (int)($cfg['parent_field_id']??0) ?>" data-field-name="<?= e($name) ?>" data-field-type="<?= e($type) ?>">
<?php continue; endif; ?>
<label class="df-generated-field <?= !empty($cfg['readonly'])?'df-readonly':'' ?>" style="--field-width:<?= (int)($cfg['width']??100) ?>%" data-default-mode="<?= e((string)($cfg['default_mode']??'defined')) ?>" data-default-provider="<?= e((string)($cfg['default_js_provider']??'')) ?>" data-default-parent-relation="<?= (int)($cfg['parent_relation_id']??0) ?>" data-default-parent-field="<?= (int)($cfg['parent_field_id']??0) ?>" data-field-name="<?= e($name) ?>" data-field-type="<?= e($type) ?>">
<span><?= e((string)$field['label']) ?><?= $required?' *':'' ?></span>
<?php if($masterContext!==null && (int)$field['id']===(int)$masterContext['lookup_field_id']): ?>
<?php $boundReadonly=!array_key_exists('bound_field_readonly',$masterContext)||!empty($masterContext['bound_field_readonly']); ?>
<?php $boundSelectedValue=$boundReadonly?(string)(int)$masterContext['parent_record_id']:($scalarValue!==''?$scalarValue:(string)(int)$masterContext['parent_record_id']); ?>
<?php if($boundReadonly): ?><input type="hidden" name="values[<?= e($name) ?>]" value="<?= (int)$masterContext['parent_record_id'] ?>" form="df-record-edit-form"><?php endif; ?>
<select <?= $boundReadonly?'':'name="values['.e($name).']" form="df-record-edit-form"' ?> class="df-master-preselected" aria-label="<?= e((string)$field['label']) ?> – gekoppelter Eltern-Datensatz" <?= $boundReadonly?'disabled':'' ?>>
<?php if(!$boundReadonly): ?><option value="">Eltern-Datensatz wählen</option><?php endif; ?>
<?php foreach(($parentRecordOptionsByLookupFieldId[(int)$field['id']]??[]) as $parentOption): ?>
<option value="<?= (int)$parentOption['id'] ?>" <?= (string)(int)$parentOption['id']===$boundSelectedValue?'selected':'' ?>><?= (int)$parentOption['id']===(int)$masterContext['parent_record_id']?e('#'.(int)$parentOption['id']):e((string)$parentOption['caption']) ?></option>
<?php endforeach; ?>
</select>
<div class="df-master-lock"><strong>Vorausgewählt: #<?= (int)$masterContext['parent_record_id'] ?></strong><span class="muted">Die Eigenschaft <?= e($name) ?> übernimmt die Eltern-ID <?= (int)$masterContext['parent_record_id'] ?>. <?= $boundReadonly?'Das Feld ist schreibgeschützt.':'Das Feld darf gemäß Beziehungseinstellung geändert werden.' ?></span></div>
<?php /* PUBLISH16 regression contract: (int)$parentOption['id']===(int)$masterContext['parent_record_id']?'selected':'' */ ?>
<?php else:
    $controlValues=$values;
    if(!array_key_exists($name,$controlValues)) $controlValues[$name]=$rawValue;
    echo df_record_inline_create_control(
        $pdo,
        $field,
        $parentRelationsByLookupFieldId,
        $parentRecordOptionsByLookupFieldId,
        $derivedOptionsByField,
        'df-record-edit-form',
        $controlValues,
        'df-record-control',
        $masterContext
    );
    if(DataFormFieldTypeRegistry::isMedia($type) && ($mediaDescriptor=DataFormFieldStorageManager::descriptor($scalarValue))!==null && $recordId>0):
        $mediaPreviewUrl=df_media_url($projectId,$dataformId,$recordId,(int)$field['id'],false);
        $mediaDownloadUrl=df_media_url($projectId,$dataformId,$recordId,(int)$field['id'],true);
?>
<div class="df-media-existing">
<strong>Vorhanden:</strong> <?= e((string)($mediaDescriptor['name']??'Datei')) ?>
<?php if($type==='image' && DataFormFieldStorageManager::inlinePreviewAllowed($type,(string)($mediaDescriptor['mime']??''),$cfg)): ?>
<a href="<?= e($mediaPreviewUrl) ?>" target="_blank" rel="noopener">Vorschau</a>
<?php endif; ?>
<a href="<?= e($mediaDownloadUrl) ?>">Download</a>
<label class="df-media-remove"><input type="checkbox" name="remove_media[<?= e($name) ?>]" value="1" form="df-record-edit-form"> vorhandene Datei entfernen</label>
</div>
<?php endif; endif; ?>
<?php if($type===DerivedMultiEnumManager::FIELD_TYPE):
    $derivedMeta=(array)($cfg['derived_multienum']??[]);
?>
<div class="df-derived-meta">Quelle: <code><?= e((string)($derivedMeta['source_table']??'')) ?>.<?= e((string)($derivedMeta['value_column']??'')) ?></code> · Anzeige: <code><?= e((string)($derivedMeta['label_column']??'')) ?></code> · Speicherung: CSV mit <code>,</code></div><small class="muted">Ein Quellwert, der nicht mehr verfügbar ist, bleibt als „nicht mehr vorhanden“ sichtbar.</small>
<?php endif; ?>
<?php if((string)($cfg['help_text']??'')!==''): ?><small class="df-field-help"><?= e((string)$cfg['help_text']) ?></small><?php endif; ?>
</label>
<?php endforeach; ?>
</div><div class="actions df-runtime-form-actions"><button class="button" <?= easyit_button_attributes('speichern','record') ?> data-button-fixed="1" type="submit"><?= easyit_button_image_html('speichern','../../') ?><span class="sr-only">Datensatz speichern</span></button>
<?php if($masterContext!==null): ?>
<a class="button secondary" <?= easyit_button_attributes('zurueck') ?> data-button-fixed="1" href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$returnDataformId ?>&amp;record=<?= (int)$returnRecordId ?>&amp;mode=detail"><?= easyit_button_image_html('zurueck','../../') ?><span class="sr-only">Zurück zum Eltern-Datensatz</span></a>
<?php else: ?>
<a class="button secondary" <?= easyit_button_attributes('abbrechen') ?> data-button-fixed="1" href="?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;mode=list"><?= easyit_button_image_html('abbrechen','../../') ?><span class="sr-only">Abbrechen</span></a>
<?php endif; ?>
</div></form><?php endif; ?></section>

<?php if($viewMode==='form' && $mode==='edit' && !$previewMode): ?>
<?php if(!$previewMode): foreach($childCollections as $children): ?>
<section class="card df-child-collection">
<div class="df-toolbar">
<div>
<h3><?= e((string)$children['child_dataform_name']) ?></h3>
<p>
<?= count((array)$children['records']) ?> Kinddatensatz<?= count((array)$children['records'])===1?'':'-sätze' ?>
über <code>id = <?= (int)$recordId ?></code>
→ <code><?= e((string)$children['lookup_field_name']) ?></code>
</p>
</div>
<?php if($allowCreateChild): ?><a
    class="button"
    href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;mode=create&amp;parent_relation=<?= (int)$children['relation_id'] ?>&amp;parent_record=<?= (int)$recordId ?>&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>"
>+ Kinddatensatz</a><?php endif; ?>
</div>

<?php if(!empty($children['records'])): ?>
<div class="df-field-table-wrap">
<table class="df-field-table">
<thead>
<tr>
<th>ID</th>
<?php foreach((array)$children['fields'] as $childField): ?>
<th><?= e((string)$childField['label']) ?></th>
<?php endforeach; ?>
<th>Aktionen</th>
</tr>
</thead>
<tbody>
<?php foreach((array)$children['records'] as $childRecord): ?>
<tr>
<td><?= (int)$childRecord['id'] ?></td>
<?php foreach((array)$children['fields'] as $childField): ?>
<?php $childValue=(string)($childRecord['data'][(string)$childField['name']]??''); ?>
<td><?= e(mb_strimwidth(df_display_record_value($pdo,$childField,$childValue),0,80,'…')) ?></td>
<?php endforeach; ?>
<td>
<a href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;record=<?= (int)$childRecord['id'] ?>&amp;mode=detail&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>">Öffnen</a>
·
<a href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;record=<?= (int)$childRecord['id'] ?>&amp;mode=edit&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>">Bearbeiten</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state">
<p>Noch keine Kinddatensätze vorhanden.</p>
</div>
<?php endif; ?>
</section>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php elseif($mode==='detail' && $record): ?>
<div class="df-toolbar">
<div>
<h2>Datensatz #<?= $recordId ?></h2>
<p>
<?= $recordStorageMode==='physical'?'Direkt in der gebundenen Projekttabelle gespeichert.':'Im generischen DataForm-Datenspeicher gespeichert.' ?>
</p>
</div>
<div class="actions">
<?php if($returnDataformId>0 && $returnRecordId>0): ?>
<a class="button secondary" href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$returnDataformId ?>&amp;record=<?= (int)$returnRecordId ?>&amp;mode=detail">← Eltern-Datensatz</a>
<?php endif; ?>
<a class="button" <?= easyit_button_attributes('bearbeiten','record') ?> data-crud="edit" href="?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;record=<?= $recordId ?>&amp;mode=edit">Bearbeiten</a>
<form method="post" class="inline-form" onsubmit="return confirm('Datensatz wirklich löschen?');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="delete_record"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="record" value="<?= $recordId ?>"><button class="button" <?= easyit_button_attributes('loeschen','record') ?> data-button-fixed="1" data-crud="delete" type="submit"><?= easyit_button_image_html('loeschen','../../') ?>Löschen</button></form>
</div>
</div>
<section class="card"><dl class="record-detail"><?php foreach($detailFields as $field): $v=df_record_value($record['data'],(string)$field['name']); ?><div><dt><?= e((string)$field['label']) ?></dt><dd><?= df_render_record_value_html($pdo,$field,$v,$projectId,$dataformId,$recordId) ?></dd></div><?php endforeach; ?>
<?php if((string)$record['created_at']!==''): ?><div><dt>Erstellt</dt><dd><?= e((string)$record['created_at']) ?></dd></div><?php endif; ?>
<?php if((string)$record['updated_at']!==''): ?><div><dt>Geändert</dt><dd><?= e((string)$record['updated_at']) ?></dd></div><?php endif; ?>
</dl></section>

<?php if(!$previewMode): foreach($childCollections as $children): ?>
<section class="card df-child-collection">
<div class="df-toolbar">
<div>
<h3><?= e((string)$children['child_dataform_name']) ?></h3>
<p>
<?= count((array)$children['records']) ?> Kinddatensatz<?= count((array)$children['records'])===1?'':'-sätze' ?>
über <code>id = <?= (int)$recordId ?></code>
→ <code><?= e((string)$children['lookup_field_name']) ?></code>
</p>
</div>
<?php if($allowCreateChild): ?><a
    class="button"
    href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;mode=create&amp;parent_relation=<?= (int)$children['relation_id'] ?>&amp;parent_record=<?= (int)$recordId ?>&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>"
>+ Kinddatensatz</a><?php endif; ?>
</div>

<?php if(!empty($children['records'])): ?>
<div class="df-field-table-wrap">
<table class="df-field-table">
<thead>
<tr>
<th>ID</th>
<?php foreach((array)$children['fields'] as $childField): ?>
<th><?= e((string)$childField['label']) ?></th>
<?php endforeach; ?>
<th>Aktionen</th>
</tr>
</thead>
<tbody>
<?php foreach((array)$children['records'] as $childRecord): ?>
<tr>
<td><?= (int)$childRecord['id'] ?></td>
<?php foreach((array)$children['fields'] as $childField): ?>
<?php $childValue=(string)($childRecord['data'][(string)$childField['name']]??''); ?>
<td><?= e(mb_strimwidth(df_display_record_value($pdo,$childField,$childValue),0,80,'…')) ?></td>
<?php endforeach; ?>
<td>
<a href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;record=<?= (int)$childRecord['id'] ?>&amp;mode=detail&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>">Öffnen</a>
·
<a href="?project=<?= $projectId ?>&amp;dataform=<?= (int)$children['child_dataform_id'] ?>&amp;record=<?= (int)$childRecord['id'] ?>&amp;mode=edit&amp;return_dataform=<?= (int)$dataformId ?>&amp;return_record=<?= (int)$recordId ?>">Bearbeiten</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state">
<p>Noch keine Kinddatensätze vorhanden.</p>
</div>
<?php endif; ?>
</section>
<?php endforeach; endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php if($viewMode==='dialog' && $mode==='list'): ?>
<dialog id="df-runtime-record-dialog" class="df-runtime-dialog size-<?= e($dialogSize) ?>" aria-labelledby="df-runtime-dialog-title">
  <div class="df-dialog-head">
    <strong id="df-runtime-dialog-title">Datensatz</strong>
    <button type="button" class="df-dialog-close" data-dialog-close aria-label="Dialog schließen">×</button>
  </div>
  <div class="df-dialog-body">
    <iframe class="df-dialog-frame" data-dialog-frame title="DataForm-Dialog"></iframe>
  </div>
</dialog>
<script id="df-runtime-dialog-controller">
(function(){
  const dialog=document.getElementById('df-runtime-record-dialog');
  const frame=dialog?.querySelector('[data-dialog-frame]');
  const title=dialog?.querySelector('#df-runtime-dialog-title');
  if(!dialog||!frame) return;
  const projectId=<?= (int)$projectId ?>;
  const dataformId=<?= (int)$dataformId ?>;
  const allowEdit=<?= $allowEdit?'true':'false' ?>;
  const preview=<?= $previewMode?'true':'false' ?>;
  const initialRecord=<?= (int)$dialogInitialRecordId ?>;

  function buildUrl(recordId,create){
    const u=new URL('records.php',window.location.href);
    u.search='';
    u.searchParams.set('project',String(projectId));
    u.searchParams.set('dataform',String(dataformId));
    u.searchParams.set('runtime_view','form');
    u.searchParams.set('embed','1');
    if(preview){
      u.searchParams.set('preview','1');
      const current=new URL(window.location.href).searchParams;
      ['parent_relation','parent_record','preview_depth','child_preview'].forEach(function(name){
        const value=current.get(name); if(value) u.searchParams.set(name,value);
      });
    }else{
      u.searchParams.set('dialog_embed','1');
      const current=new URL(window.location.href).searchParams;
      ['parent_relation','parent_record'].forEach(function(name){
        const value=current.get(name); if(value) u.searchParams.set(name,value);
      });
    }
    if(create){
      u.searchParams.set('mode','create');
    }else{
      u.searchParams.set('record',String(recordId));
      u.searchParams.set('active_record',String(recordId));
      u.searchParams.set('mode',allowEdit?'edit':'detail');
    }
    return u.pathname+u.search;
  }
  function openRecord(recordId){
    recordId=Number(recordId||0); if(recordId<1) return;
    if(title) title.textContent='Datensatz #'+recordId;
    frame.src=buildUrl(recordId,false);
    if(typeof dialog.showModal==='function'){ if(!dialog.open) dialog.showModal(); }
    else dialog.setAttribute('open','open');
  }
  function openCreate(){
    if(title) title.textContent='Neuer Datensatz';
    frame.src=buildUrl(0,true);
    if(typeof dialog.showModal==='function'){ if(!dialog.open) dialog.showModal(); }
    else dialog.setAttribute('open','open');
  }
  function closeDialog(){
    if(typeof dialog.close==='function'&&dialog.open) dialog.close();
    else dialog.removeAttribute('open');
    frame.removeAttribute('src');
  }
  dialog.querySelector('[data-dialog-close]')?.addEventListener('click',closeDialog);
  dialog.addEventListener('click',function(ev){if(ev.target===dialog)closeDialog();});
  document.addEventListener('click',function(ev){
    const recordButton=ev.target.closest('[data-dialog-record]');
    if(recordButton){ev.preventDefault();openRecord(recordButton.getAttribute('data-dialog-record'));return;}
    const createButton=ev.target.closest('[data-dialog-create]');
    if(createButton){ev.preventDefault();openCreate();}
  });
  document.addEventListener('easyit-dataform-current-record',function(ev){
    const id=Number(ev.detail&&ev.detail.recordId||0); if(id>0) openRecord(id);
  });
  window.addEventListener('message',function(ev){
    if(ev.origin!==window.location.origin||!ev.data) return;
    if(ev.data.type==='easyit-dataform-dialog-saved'||ev.data.type==='easyit-dataform-dialog-deleted'){
      closeDialog();
      window.location.reload();
    }
  });
  document.addEventListener('DOMContentLoaded',function(){if(initialRecord>0)openRecord(initialRecord);});
})();
</script>
<?php endif; ?>

<?php if($previewMode && !empty($previewChildRelations)): ?>
<?php $previewParentRecordId=$recordId>0?$recordId:$activeRecordId; ?>
<section class="card df-preview-child-forms-panel" data-preview-child-forms-panel data-current-parent-record="<?= (int)$previewParentRecordId ?>" data-preview-depth="<?= (int)$previewDepth ?>">
  <div class="df-toolbar df-preview-child-forms-heading">
    <div>
      <h2>Kindformulare</h2>
      <p class="muted">Die echten Kind-DataForms zum aktuell ausgewählten Eltern-Datensatz. Ein Datensatzwechsel aktualisiert alle Kindformulare ohne Seitenreload.</p>
    </div>
    <span class="badge" data-preview-child-parent-label><?= $previewParentRecordId>0?'Eltern-DS #'.(int)$previewParentRecordId:'Eltern-Datensatz auswählen' ?></span>
  </div>
  <div class="df-preview-child-form-list">
  <?php foreach($previewChildRelations as $previewChild): ?>
    <section class="df-preview-child-form-card" data-preview-child-relation="<?= (int)$previewChild['relation_id'] ?>">
      <header>
        <div><h3><?= e((string)$previewChild['child_dataform_name']) ?></h3><p><?= e((string)$previewChild['relation_name']) ?> · FK <code><?= e((string)$previewChild['lookup_field_name']) ?></code></p></div>
        <span class="badge">1:n</span>
      </header>
      <div class="df-preview-child-frame-empty" data-preview-child-empty <?= $previewParentRecordId>0?'hidden':'' ?>>Eltern-Datensatz auswählen, um dieses Kindformular anzuzeigen.</div>
      <iframe
        class="df-preview-child-frame"
        data-preview-child-frame
        data-relation-id="<?= (int)$previewChild['relation_id'] ?>"
        data-child-dataform-id="<?= (int)$previewChild['child_dataform_id'] ?>"
        data-child-dataform-name="<?= e((string)$previewChild['child_dataform_name']) ?>"
        title="Kindformular: <?= e((string)$previewChild['child_dataform_name']) ?>"
        loading="lazy"
        <?= $previewParentRecordId>0?'':'hidden' ?>
      ></iframe>
    </section>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<script>document.getElementById("select-all-records")?.addEventListener("change",function(){document.querySelectorAll("input[name=\"record_ids[]\"]").forEach(cb=>cb.checked=this.checked);});</script>
</div><footer class="df-statusbar"><span>Projekt: <strong><?= e((string)($project['name']??'')) ?></strong></span><span>DataForm: <strong><?= e((string)($dataform['name']??'')) ?></strong></span><span>Treffer: <strong><?= (int)($filteredCount ?? count($records)) ?></strong></span><span>Version: <strong>RC1.8-HF58</strong></span></footer></div>
<script src="../../products/dataform/assets/default-providers.js"></script>
<script id="hf21-default-provider-runner">
(function(){
    var registry=window.easyITDefaultProviders||{};

    function setControl(control,value){
        if(value===undefined||value===null) return;
        if(control.type==='checkbox'){
            control.checked=value===true||value===1||String(value)==='1'||String(value).toLowerCase()==='true';
        }else{
            control.value=String(value);
        }
        control.dispatchEvent(new Event('change',{bubbles:true}));
    }

    document.querySelectorAll('[data-default-mode="javascript"]').forEach(function(scope){
        var form=scope.closest('form');
        if(!form||form.getAttribute('data-record-mode')!=='create') return;

        var providerName=scope.getAttribute('data-default-provider')||'';
        if(!providerName) return;
        var provider=registry[providerName];
        if(typeof provider!=='function'){
            console.warn('easyIT DataForm: unbekannter Standardwert-Provider',providerName);
            return;
        }

        var control;
        if(scope.matches('input,textarea,select')){
            control=scope;
        }else{
            control=scope.querySelector('input:not([type="hidden"]):not([disabled]),textarea:not([disabled]),select:not([disabled])')
                ||scope.querySelector('input[type="hidden"]');
        }
        if(!control) return;
        if(control.type==='checkbox' ? control.checked : control.value!=='') return;

        try{
            var value=provider({
                field:{
                    name:scope.getAttribute('data-field-name')||'',
                    type:scope.getAttribute('data-field-type')||''
                },
                now:new Date(),
                form:form
            });

            setControl(control,value);

            if(!scope.matches('input,textarea,select')){
                scope.querySelectorAll('input[type="hidden"],input[disabled],select[disabled]').forEach(function(sync){
                    if(sync===control) return;
                    if(sync.name===control.name || sync.type==='hidden'){
                        setControl(sync,control.type==='checkbox'?(control.checked?'1':'0'):control.value);
                    }
                });
            }
        }catch(error){
            console.error('easyIT DataForm Standardwert-Provider fehlgeschlagen:',providerName,error);
        }
    });

    function parentTarget(scope){
        if(scope.matches('input,textarea,select')){
            return scope;
        }
        return scope.querySelector(
            'input:not([type="hidden"]):not([disabled]),textarea:not([disabled]),select:not([disabled])'
        ) || scope.querySelector('input[type="hidden"]');
    }

    function applyParentValue(scope,parentRecordId){
        var relationId=scope.getAttribute('data-default-parent-relation')||'';
        var parentFieldId=scope.getAttribute('data-default-parent-field')||'';

        if(!relationId||!parentFieldId||!parentRecordId){
            return;
        }

        var target=parentTarget(scope);
        if(!target){
            return;
        }

        var params=new URLSearchParams({
            project:String(<?= (int)$projectId ?>),
            child_dataform:String(<?= (int)$dataformId ?>),
            relation:String(relationId),
            parent_record:String(parentRecordId),
            parent_field:String(parentFieldId)
        });

        fetch('parent-value.php?'+params.toString(),{
            credentials:'same-origin',
            headers:{'Accept':'application/json'}
        })
        .then(function(response){
            if(!response.ok){
                throw new Error('HTTP '+response.status);
            }
            return response.json();
        })
        .then(function(payload){
            if(!payload.ok){
                throw new Error(payload.error||'Elternwert konnte nicht geladen werden.');
            }
            setControl(target,payload.value===null?'':payload.value);
        })
        .catch(function(error){
            console.error('easyIT DataForm Elternwert:',error);
        });
    }

    document.querySelectorAll('select[data-parent-relation-id]').forEach(function(lookup){
        var form=lookup.closest('form');
        if(!form||form.getAttribute('data-record-mode')!=='create'){
            return;
        }

        function syncInheritedFields(){
            var relationId=lookup.getAttribute('data-parent-relation-id')||'';
            var parentRecordId=lookup.value||'';

            document.querySelectorAll(
                '.df-generated-field[data-default-mode="parent_field"][data-default-parent-relation="'+relationId+'"],'
                +'input.df-default-target[data-default-mode="parent_field"][data-default-parent-relation="'+relationId+'"]'
            ).forEach(function(scope){
                applyParentValue(scope,parentRecordId);
            });
        }

        lookup.addEventListener('change',syncInheritedFields);

        if(lookup.value){
            syncInheritedFields();
        }
    });
})();
</script>
<script>
// HF76-FIX13: Datensatzzeiger wechseln den aktuellen Datensatz ausschließlich
// clientseitig. Die Auswahl wird zusätzlich ohne Navigation in URL und POST-
// Formularzustand gespiegelt, damit nach einer späteren Aktion derselbe DS
// wieder als aktuell behandelt werden kann.
document.addEventListener('DOMContentLoaded', function () {
    var table=document.querySelector('.records-table');
    if(!table) return;

    function decoratePointer(pointer,type,pressed){
        if(!pointer) return;
        pointer.classList.toggle('active',pressed && type==='aktueller_ds');
        pointer.setAttribute('aria-pressed',pressed?'true':'false');
        pointer.setAttribute('data-button',type);
        if(window.EasyITButtons && typeof window.EasyITButtons.decorate==='function'){
            window.EasyITButtons.decorate(pointer);
        }
    }

    function setCurrentRow(row,focusNewControl){
        if(!row) return;
        document.querySelectorAll('tr[data-record-id]').forEach(function(candidate){
            var active=candidate===row;
            candidate.classList.toggle('selected-row',active);
            candidate.classList.toggle('df-record-active-row',active);
            decoratePointer(candidate.querySelector('.df-record-pointer'),active?'aktueller_ds':'normaler_ds',active);
        });

        var newRow=document.getElementById('record-new-row');
        if(newRow){
            var newActive=newRow===row;
            newRow.classList.toggle('selected-row',newActive);
            newRow.classList.toggle('df-record-active-row',newActive);
            decoratePointer(newRow.querySelector('.df-record-pointer'),'neuer_ds',newActive);
            if(newActive && focusNewControl){
                var firstControl=newRow.querySelector('.df-inline-create-control');
                if(firstControl) firstControl.focus({preventScroll:true});
            }
        }

        var recordId=row.getAttribute('data-record-id') || (row.id==='record-new-row'?'new':'');
        table.setAttribute('data-current-record',recordId);
        window.EasyITDataFormCurrentRecord=recordId;
        document.dispatchEvent(new CustomEvent('easyit-dataform-current-record',{detail:{recordId:recordId}}));

        // HF76-FIX13: Aktuellen DS in allen POST-Formularen spiegeln. Das
        // fachliche Ziel eines Formulars bleibt weiterhin in dessen eigenem
        // record-Feld; active_record beschreibt ausschließlich den UI-Zustand.
        document.querySelectorAll('form[method="post"],form[method="POST"]').forEach(function(form){
            var field=form.querySelector('input[name="active_record"]');
            if(!field){
                field=document.createElement('input');
                field.type='hidden';
                field.name='active_record';
                form.appendChild(field);
            }
            field.value=/^\d+$/.test(recordId)?recordId:'0';
        });

        // URL nur im Browserzustand aktualisieren; history.replaceState lädt
        // weder Seite noch Datensätze neu. Für die ungespeicherte Neuzeile wird
        // active_record entfernt, weil noch keine persistente ID existiert.
        if(window.history && typeof window.history.replaceState==='function'){
            try{
                var url=new URL(window.location.href);
                if(/^\d+$/.test(recordId)) url.searchParams.set('active_record',recordId);
                else url.searchParams.delete('active_record');
                window.history.replaceState(window.history.state,'',url.pathname+url.search+url.hash);
            }catch(ignore){}
        }
    }

    table.addEventListener('click',function(event){
        var pointer=event.target.closest('.df-record-pointer');
        if(!pointer || !table.contains(pointer)) return;
        event.preventDefault();
        event.stopPropagation();
        var row=pointer.closest('tr[data-record-id],#record-new-row');
        setCurrentRow(row,row && row.id==='record-new-row');
    });

    document.querySelectorAll('tr[data-record-id],#record-new-row').forEach(function(row){
        row.addEventListener('focusin',function(event){
            // The pointer click itself is handled above; all other controls can
            // still make their row current without a navigation or reload.
            if(event.target.closest && event.target.closest('.df-record-pointer')) return;
            setCurrentRow(row,false);
        });
    });

    // HF76-FIX15: Ist die Inline-Neuzeile auf der aktuellen (letzten) Seite
    // bereits vorhanden, darf "Neu" keinen Reload verursachen. Stattdessen
    // wird ausschließlich die Neuzeile zum aktuellen DS und das erste Feld
    // erhält den Fokus. Von anderen Seiten bleibt die normale Navigation zur
    // letzten Seite erhalten, weil dort die Neuzeile noch nicht im DOM liegt.
    document.querySelectorAll('.df-new-record-action[data-new-record-action="1"]').forEach(function(trigger){
        trigger.addEventListener('click',function(event){
            var newRow=document.getElementById('record-new-row');
            if(!newRow) return;
            event.preventDefault();
            event.stopPropagation();
            setCurrentRow(newRow,true);
            if(typeof newRow.scrollIntoView==='function'){
                newRow.scrollIntoView({block:'nearest',behavior:'smooth'});
            }
        });
    });

    // HF76-FIX14: 1.-/Letzter-DS-Sprung bleibt ebenfalls reloadfrei, wenn
    // der Zieldatensatz bereits auf der aktuell sichtbaren Seite vorhanden ist.
    document.querySelectorAll('.df-pagination-record-jump[data-record-target]').forEach(function(jump){
        jump.addEventListener('click',function(event){
            var target=jump.getAttribute('data-record-target')||'';
            if(!/^\d+$/.test(target) || target==='0') return;
            var row=document.getElementById('record-row-'+target);
            if(!row) return; // andere Seite: normale Navigation ist erforderlich
            event.preventDefault();
            event.stopPropagation();
            setCurrentRow(row,false);
            if(typeof row.scrollIntoView==='function'){
                row.scrollIntoView({block:'nearest',behavior:'smooth'});
            }
        });
    });

    var initial=table.querySelector('tr.df-record-active-row[data-record-id]');
    if(initial){
        // Dieselbe Routine auch für den initialen Serverzustand verwenden,
        // damit URL und Formulare unmittelbar synchron sind.
        setCurrentRow(initial,false);
    }
});
</script>
<?php
$dfEventJson=json_encode($dataformEvents,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP);
$dfRecordEventJson=json_encode($recordSetEvents??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP);
$dfContextJson=json_encode($dataformActionContext??new stdClass(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP);
$dfEventJsFile=__DIR__.'/assets/dataform-events.js';
$dfEventJsV=is_file($dfEventJsFile)?(string)filemtime($dfEventJsFile):'1';
?>
<script type="application/json" id="df-event-handlers"><?= $dfEventJson?:'{}' ?></script>
<script type="application/json" id="df-recordset-event-handlers"><?= $dfRecordEventJson?:'{}' ?></script>
<script type="application/json" id="df-action-context"><?= $dfContextJson?:'{}' ?></script>
<script>window.DF_CONTEXT_META=<?= json_encode(['project'=>['id'=>$projectId,'name'=>(string)($project['name']??'')],'dataform'=>['id'=>$dataformId,'name'=>(string)($dataform['name']??''),'slug'=>(string)($dataform['slug']??''),'view_mode'=>$viewMode,'fulltext_search'=>$showSearch,'filter'=>$showFilter]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG) ?>;</script>
<script src="assets/dataform-events.js?v=<?= e($dfEventJsV) ?>"></script>
<?php if($previewMode && !empty($previewChildRelations)): ?>
<script id="df-preview-child-forms-runtime">
(function(){
    const panel=document.querySelector('[data-preview-child-forms-panel]');
    if(!panel) return;

    function childUrl(frame,parentRecordId){
        const u=new URL('records.php',window.location.href);
        u.search='';
        u.searchParams.set('project',String(<?= (int)$projectId ?>));
        u.searchParams.set('dataform',frame.getAttribute('data-child-dataform-id')||'0');
        // PUBLISH13: Kind-DataForms verwenden ihre eigene Standardansicht.
        u.searchParams.delete('mode');
        u.searchParams.set('embed','1');
        u.searchParams.set('preview','1');
        u.searchParams.set('child_preview','1');
        u.searchParams.set('preview_depth',String(<?= (int)$previewDepth + 1 ?>));
        u.searchParams.set('parent_relation',frame.getAttribute('data-relation-id')||'0');
        u.searchParams.set('parent_record',String(parentRecordId));
        return u.pathname+u.search;
    }

    function loadChildren(parentRecordId){
        parentRecordId=String(parentRecordId||'');
        const valid=/^\d+$/.test(parentRecordId)&&parentRecordId!=='0';
        panel.setAttribute('data-current-parent-record',valid?parentRecordId:'');
        const label=panel.querySelector('[data-preview-child-parent-label]');
        if(label) label.textContent=valid?'Eltern-DS #'+parentRecordId:'Eltern-Datensatz auswählen';
        panel.querySelectorAll('[data-preview-child-frame]').forEach(function(frame){
            const empty=frame.parentElement?.querySelector('[data-preview-child-empty]');
            if(!valid){
                frame.hidden=true;
                frame.removeAttribute('src');
                if(empty) empty.hidden=false;
                return;
            }
            if(empty) empty.hidden=true;
            frame.hidden=false;
            const next=childUrl(frame,parentRecordId);
            if(frame.getAttribute('src')!==next) frame.setAttribute('src',next);
        });
    }

    document.addEventListener('easyit-dataform-current-record',function(ev){
        loadChildren(ev.detail&&ev.detail.recordId?ev.detail.recordId:'');
    });
    document.addEventListener('DOMContentLoaded',function(){
        loadChildren(panel.getAttribute('data-current-parent-record')||'');
    });

    // Verschachtelte Kind-Previews melden ihre reale Höhe an dieses Eltern-
    // Preview. So bleiben auch Kindformulare mit vielen Datensätzen vollständig
    // sichtbar; der äußere Preview-iframe wächst anschließend per ResizeObserver.
    window.addEventListener('message',function(ev){
        if(ev.origin!==window.location.origin||!ev.data||ev.data.type!=='easyit-dataform-preview-height') return;
        panel.querySelectorAll('[data-preview-child-frame]').forEach(function(frame){
            if(frame.contentWindow!==ev.source) return;
            const h=Math.max(360,Math.min(2400,Number(ev.data.height)||520));
            frame.style.height=h+'px';
        });
    });
})();
</script>
<?php endif; ?>
<?php
$content=ob_get_clean();

if ($embedMode) {
    $enterpriseCssFile=dirname(__DIR__,2).'/assets/css/enterprise.css';
    $workspaceCssFile=__DIR__.'/assets/workspace.css';
    $crudCssFile=dirname(__DIR__,2).'/assets/css/easyit-crud-3d-buttons.css';
    $registryJsFile=dirname(__DIR__,2).'/assets/js/easyit-button-registry.js';
    $workspaceV=is_file($workspaceCssFile)?(string)filemtime($workspaceCssFile):'1';
    $crudV=is_file($crudCssFile)?(string)filemtime($crudCssFile):'1';
    $registryV=is_file($registryJsFile)?(string)filemtime($registryJsFile):'1';
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e((string)($dataform['name']??'DataForm')) ?> – Realvorschau</title>
<link rel="stylesheet" href="../../assets/css/enterprise.css">
<link rel="stylesheet" href="assets/workspace.css?v=<?= e($workspaceV) ?>">
<link rel="stylesheet" href="../../assets/css/easyit-crud-3d-buttons.css?v=<?= e($crudV) ?>">
<style id="df-iframe-preview-css">
html,body{margin:0!important;padding:0!important;background:#fff!important;min-height:100%}
body{overflow-x:hidden}.dataform-runtime{padding:.75rem!important;max-width:none!important;margin:0!important}
.breadcrumbs,.df-workspace-header,.df-subnav,.easyit-dataform-branding,.topbar,.help-sidebar,footer{display:none!important}
.df-workspace,.df-editor-content,.records-content{margin:0!important;max-width:none!important;width:100%!important;padding:0!important}
.record-search-panel,.column-settings{margin-top:.6rem}.df-preview-readonly-note{position:sticky;top:0;z-index:1000;margin:0 0 .65rem;padding:.45rem .7rem;border:1px solid #bfd3ef;border-radius:.5rem;background:#eef6ff;color:#163d69;font-size:.84rem}
.df-runtime-preview-blocked{opacity:.68;cursor:not-allowed!important}
</style>
</head><body class="<?= $previewMode?'dataform-iframe-preview':'dataform-dialog-embed' ?>" data-preview-mode="<?= $previewMode?'1':'0' ?>" data-dialog-embed="<?= $dialogEmbedMode?'1':'0' ?>" data-child-preview="<?= $childPreviewMode?'1':'0' ?>" data-dataform-id="<?= (int)$dataformId ?>">
<?php if($previewMode && !$childPreviewMode): ?><div class="df-preview-readonly-note" role="status">Realvorschau – Navigation und Eingaben sind testbar; Änderungen werden nicht gespeichert.</div><?php endif; ?>
<?= $content ?>
<?= easyit_button_registry_data_tag() ?>
<script src="../../assets/js/easyit-button-registry.js?v=<?= e($registryV) ?>" defer></script>
<script>
(function(){
  const preview=<?= $previewMode?'true':'false' ?>;
  const dialogEmbed=<?= $dialogEmbedMode?'true':'false' ?>;
  function keepPreviewUrl(raw){
    try{
      const u=new URL(raw,window.location.href);
      if(u.origin!==window.location.origin)return raw;
      if(u.pathname.endsWith('/records.php')){
        u.searchParams.set('embed','1');
        if(preview) u.searchParams.set('preview','1'); else u.searchParams.delete('preview');
        if(dialogEmbed) u.searchParams.set('dialog_embed','1');
        ['parent_relation','parent_record','preview_depth','child_preview','runtime_view'].forEach(function(name){
          if(!u.searchParams.has(name)){
            const current=new URL(window.location.href).searchParams.get(name);
            if(current!==null&&current!=='')u.searchParams.set(name,current);
          }
        });
      }
      return u.pathname+u.search+u.hash;
    }catch(e){return raw;}
  }
  function prepare(){
    document.querySelectorAll('a[href]').forEach(function(a){
      const href=a.getAttribute('href')||'';
      if(href===''||href.startsWith('#'))return;
      const next=keepPreviewUrl(href);
      a.setAttribute('href',next);
      if(/\/import\.php|\/index\.php|\/workflow\.php|\/relations\.php/.test(next)){
        a.addEventListener('click',function(ev){ev.preventDefault();});
        a.classList.add('df-runtime-preview-blocked');
      }
    });
    document.querySelectorAll('form').forEach(function(form){
      const method=(form.getAttribute('method')||'get').toLowerCase();
      const currentParams=new URL(window.location.href).searchParams;
      if(method==='get'){
        ['embed','preview','dialog_embed','parent_relation','parent_record','preview_depth','child_preview','runtime_view'].forEach(function(name){
          let value=currentParams.get(name);
          if(name==='embed') value='1';
          if(name==='preview'&&preview) value='1';
          if(name==='dialog_embed'&&dialogEmbed) value='1';
          if(value===null||value==='')return;
          if(!form.querySelector('input[name="'+name+'"]')){
            const i=document.createElement('input');i.type='hidden';i.name=name;i.value=String(value);form.appendChild(i);
          }
        });
      }else if(preview){
        form.addEventListener('submit',function(ev){
          ev.preventDefault();
          window.parent.postMessage({type:'easyit-dataform-preview-write-blocked',dataform:<?= (int)$dataformId ?>},window.location.origin);
        });
      }else if(dialogEmbed){
        ['embed','dialog_embed','runtime_view','parent_relation','parent_record'].forEach(function(name){
          let value=currentParams.get(name);
          if(name==='embed'||name==='dialog_embed') value='1';
          if(name==='runtime_view'&&!value) value='form';
          if(value===null||value==='')return;
          if(!form.querySelector('input[name="'+name+'"]')){
            const i=document.createElement('input');i.type='hidden';i.name=name;i.value=String(value);form.appendChild(i);
          }
        });
      }
    });
  }
  function reportHeight(){
    const h=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight,520);
    window.parent.postMessage({type:'easyit-dataform-preview-height',dataform:<?= (int)$dataformId ?>,height:h},window.location.origin);
  }
  document.addEventListener('DOMContentLoaded',function(){
    prepare();reportHeight();setTimeout(reportHeight,250);
    <?php if($dialogEmbedMode && $success!==''): ?>
    window.parent.postMessage({type:'easyit-dataform-dialog-saved',dataform:<?= (int)$dataformId ?>},window.location.origin);
    <?php endif; ?>
  });
  window.addEventListener('load',reportHeight);
  if(window.ResizeObserver){new ResizeObserver(reportHeight).observe(document.documentElement);}
})();
</script>
</body></html><?php
    exit;
}

render_page([
 'title'=>(($dataform['name']??'DataForm').' – Datensätze'), 'active'=>'projects','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'body_class'=>'workspace-page',
 'help'=>[
  'title'=>'Filter, Export und Mehrfachaktionen','location'=>'Enterprise → Projekt → DataForm → Datensätze',
  'short'=>'Gespeicherte Filter, CSV-Export und Mehrfachaktionen stehen für die Datensatzliste zur Verfügung.',
  'goal'=>'Wiederkehrende Auswertungen speichern, Treffer exportieren und mehrere Datensätze gemeinsam bearbeiten.',
  'next'=>'Für größere Datenmengen können Importprofile und Duplikatabgleich verwendet werden.',
  'steps'=>['Suche und Feldfilter einstellen.','Aktuelle Auswahl als benannten Filter speichern.','Gefilterte Treffer als CSV exportieren.','Datensätze markieren und gesammelt löschen.'],
  'tips'=>['Gespeicherte Filter gelten je Benutzer und DataForm.','Der CSV-Export berücksichtigt die aktuelle Suche und alle Feldfilter.','Mehrfachlöschen erfordert eine ausdrückliche Bestätigung.']
 ]
]);
