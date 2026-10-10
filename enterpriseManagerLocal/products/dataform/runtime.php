<?php
declare(strict_types=1);

require_once __DIR__.'/system/DataFormRecordSetEventRepository.php';
require_once __DIR__.'/system/DataFormRecordSetEventEditor.php';
require_once __DIR__.'/system/DataFormEventDomains.php';
/*
 * HF76 compatibility contracts retained for regression coverage:
 * X-EasyIT-DataForm-Runtime: HF36
 * dataform_field_config($field['configuration_json'] ?? null)
 * dataform_field_config($selectedField['configuration_json'] ?? null)
 * 'derived_multienum'=>'Abgeleitete Mehrfachauswahl'
 * <option value="derived_multienum">Abgeleitete Mehrfachauswahl</option>
 * field['field_type'] === 'derived_multienum'
 * Compatibility UI contracts (not rendered): >Datensätze</a>
 * 'allow_create'=>'Datensätze anlegen' 'allow_edit'=>'Datensätze bearbeiten' 'allow_delete'=>'Datensätze löschen'
 * 'show_search'=>'Suche anzeigen' 'show_filter'=>'Filter anzeigen' 'show_pagination'=>'Paginierung anzeigen'
 */

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once __DIR__ . '/system/WorkspaceController.php';
require_once __DIR__ . '/system/DataSourceManager.php';
require_once __DIR__ . '/system/DataFormSecretKey.php';
require_once __DIR__ . '/system/TableWorkspaceManager.php';
require_once __DIR__ . '/system/RelationManager.php';
require_once __DIR__ . '/system/DataFormManager.php';
require_once __DIR__ . '/system/DerivedMultiEnumManager.php';
require_once __DIR__ . '/system/DataFormFieldTypes.php';
require_once __DIR__ . '/system/DataFormConfigNavigation.php';
require_once dirname(__DIR__, 2) . '/system/licensing/Autoload.php';

$user = enterprise_require_auth('../../');
$requestedProjectId = (int)($_GET['project'] ?? ($_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0)));
$enterpriseAdminPdo = enterprise_pdo();
enterprise_upgrade($enterpriseAdminPdo);
$enterpriseProjectStmt = $enterpriseAdminPdo->prepare('SELECT * FROM projects WHERE id=?');
$enterpriseProjectStmt->execute([$requestedProjectId]);
$enterpriseProjectForProduct = $enterpriseProjectStmt->fetch(PDO::FETCH_ASSOC);
$isCalendarDataAdmin = is_array($enterpriseProjectForProduct) && (string)($enterpriseProjectForProduct['product_type'] ?? '') === 'calendar';
if ($isCalendarDataAdmin) {
    enterprise_require_customer_module($user,'calendar');
} else {
    enterprise_require_customer_module($user,'dataform');
}
$licenseDesignerAccess=enterprise_customer_has_module($user,'designer') && (enterprise_license_capability($user,'designer.validate') || enterprise_license_capability($user,'designer.compile') || enterprise_license_capability($user,'designer.publish'));
$licenseCanPublish=enterprise_customer_has_module($user,'designer') && enterprise_license_capability($user,'designer.publish');
$licenseCanExport=enterprise_customer_has_module($user,'export') && enterprise_license_capability($user,'export.execute');
$licenseCanApi=enterprise_customer_has_module($user,'api') && enterprise_license_capability($user,'api.access');
$licenseCanRelations=enterprise_license_capability($user,'relation.read');
$licenseCanEvents=enterprise_license_capability($user,'dataform.events');
$licenseCanCustomCss=enterprise_license_capability($user,'dataform.custom_css');
$licenseCanCustomJavascript=enterprise_license_capability($user,'dataform.custom_javascript');
$licenseCanSearch=enterprise_license_capability($user,'dataform.search');
$licenseCanFilter=enterprise_license_capability($user,'dataform.filter');
$licenseCanPagination=enterprise_license_capability($user,'dataform.pagination');
$licenseCanRead=enterprise_license_capability($user,'dataset.read');
$licenseCanCreate=enterprise_license_capability($user,'dataset.create');
$licenseCanUpdate=enterprise_license_capability($user,'dataset.update');
$licenseCanDelete=enterprise_license_capability($user,'dataset.delete');
if($isCalendarDataAdmin){
    // HF105: Calendar lizenziert die interne DataForm-Schicht einschließlich
    // Feld-/Layoutkonfiguration mit; Export/API bleiben eigenständige Module.
    $licenseDesignerAccess=true;
    $licenseCanRelations=$licenseCanEvents=$licenseCanCustomCss=$licenseCanCustomJavascript=true;
    $licenseCanSearch=$licenseCanFilter=$licenseCanPagination=true;
    $licenseCanRead=$licenseCanCreate=$licenseCanUpdate=$licenseCanDelete=true;
}
$projectId = (int)($_GET['project'] ?? ($_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0)));
enterprise_require_project_scope($user,$projectId,false);
$projectWideScope=enterprise_project_scope_manage($user,$projectId);
$sectionKey = preg_replace('/[^a-z-]/', '', (string)($_GET['section'] ?? $_POST['section'] ?? 'welcome')) ?: 'welcome';
if($sectionKey==='designer'&&!$licenseDesignerAccess){http_response_code(403);exit('Der DataForm-Designer ist in der aktuellen Lizenz nicht freigegeben.');}
if(in_array($sectionKey,['sources','tables'],true)&&!$projectWideScope){http_response_code(403);exit('Dieser Projektbereich ist für die aktuelle Ressourcenzuordnung nicht freigegeben.');}
$error = '';
$success = '';
$info = '';
$project = null;
$projectPdo = null;
$dataforms = [];
$unboundManagedTables = [];
$dataformBindingsById = [];
$selectedDataform = null;
$fields = [];
$parentRelations = [];
$parentFieldsByRelation = [];
$selectedField = null;
$selectedFieldId = (int)($_GET['field'] ?? $_POST['field'] ?? 0);
$selectedDataformId = (int)($_GET['dataform'] ?? $_POST['dataform'] ?? 0);
$selectedLicensePolicy = null;
$stats = ['dataforms' => 0, 'fields' => 0, 'tables' => 0];
$dbConnected = false;
$dataSources = [];
$selectedSource = null;
$selectedSourceId = (int)($_GET['source'] ?? $_POST['source_id'] ?? 0);
$sourceKeyAvailable = false;
$tableSourceKey = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['table_source'] ?? $_POST['table_source'] ?? 'system'))) ?: 'system';
$selectedTableName = trim((string)($_GET['table'] ?? $_POST['table'] ?? ''));
$selectedColumnName = trim((string)($_GET['column'] ?? $_POST['original_column'] ?? $_POST['column'] ?? ''));
$selectedColumn = null;
$tableCatalog = [];
$tableInspection = null;
$tableError = '';
$tableSourceRow = null;
$tableSources = [];
$tableDataformBinding = null;
$derivedSourceCatalog = [];
$lookupDesignerDataforms = [];
$lookupDesignerFieldsByForm = [];
$selectedLookupRelation = null;

/**
 * HF18: Normalize legacy/current field configuration before rendering.
 */
function dataform_field_config(mixed $raw,string $fieldType='text'): array
{
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        $cfg = is_array($decoded) ? $decoded : [];
    } elseif (is_array($raw)) {
        $cfg = $raw;
    } else {
        $cfg = [];
    }

    $cfg['placeholder'] =
        isset($cfg['placeholder']) && is_scalar($cfg['placeholder'])
            ? (string)$cfg['placeholder']
            : '';

    $width =
        isset($cfg['width']) && is_scalar($cfg['width'])
            ? (string)$cfg['width']
            : '100';
    $cfg['width'] =
        in_array($width, ['25','33','50','66','75','100'], true)
            ? $width
            : '100';

    $options = $cfg['options'] ?? [];
    if (is_string($options)) {
        $options = preg_split('/\R+/', $options) ?: [];
    } elseif (!is_array($options)) {
        $options = [];
    }

    $cfg['options'] = array_values(array_filter(
        array_map(
            static fn($value): string =>
                is_scalar($value) ? trim((string)$value) : '',
            $options
        ),
        static fn(string $value): bool => $value !== ''
    ));

    foreach (['help_text','default_value','default_js_provider','pattern','min_value','max_value','step'] as $key) {
        $cfg[$key] = isset($cfg[$key]) && is_scalar($cfg[$key]) ? (string)$cfg[$key] : '';
    }

    $defaultMode = isset($cfg['default_mode']) && is_scalar($cfg['default_mode'])
        ? (string)$cfg['default_mode'] : 'defined';
    $cfg['default_mode'] = in_array($defaultMode, ['defined','current_timestamp','javascript','parent_field'], true)
        ? $defaultMode : 'defined';
    $cfg['parent_relation_id'] = isset($cfg['parent_relation_id']) ? max(0, (int)$cfg['parent_relation_id']) : 0;
    $cfg['parent_field_id'] = isset($cfg['parent_field_id']) ? max(0, (int)$cfg['parent_field_id']) : 0;

    if (
        $cfg['default_js_provider'] !== ''
        && !preg_match('/^[A-Za-z_$][A-Za-z0-9_$.-]{0,127}$/', $cfg['default_js_provider'])
    ) {
        $cfg['default_js_provider'] = '';
    }
    foreach (['min_length','max_length'] as $key) {
        if (!isset($cfg[$key]) || $cfg[$key] === '' || $cfg[$key] === null) $cfg[$key] = null;
        else $cfg[$key] = max(0, min(1000000, (int)$cfg[$key]));
    }
    $cfg['rows'] = max(2, min(30, (int)($cfg['rows'] ?? 3)));
    $autocompleteAllowed = ['', 'off', 'on', 'name', 'given-name', 'family-name', 'email', 'username', 'organization', 'tel', 'street-address', 'postal-code', 'country', 'current-password', 'new-password'];
    $autocomplete = isset($cfg['autocomplete']) && is_scalar($cfg['autocomplete']) ? (string)$cfg['autocomplete'] : '';
    $cfg['autocomplete'] = in_array($autocomplete, $autocompleteAllowed, true) ? $autocomplete : '';
    $inputmodeAllowed = ['', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'];
    $inputmode = isset($cfg['inputmode']) && is_scalar($cfg['inputmode']) ? (string)$cfg['inputmode'] : '';
    $cfg['inputmode'] = in_array($inputmode, $inputmodeAllowed, true) ? $inputmode : '';
    foreach (['readonly'=>false,'hidden'=>false,'trim'=>true,'list_visible'=>true,'searchable'=>true,'filterable'=>true,'sortable'=>true] as $key=>$default) {
        $cfg[$key] = array_key_exists($key, $cfg) ? (bool)$cfg[$key] : $default;
    }
    $cfg = DerivedMultiEnumManager::normalizeConfig($cfg);
    return DataFormFieldTypeRegistry::normalizeConfiguration($cfg,$fieldType);
}

function dataform_apply_derived_multienum_post(array $configuration): array
{
    $configuration['derived_multienum'] = [
        'source_table'=>trim((string)($_POST['derived_source_table'] ?? '')),
        'value_column'=>trim((string)($_POST['derived_value_column'] ?? '')),
        'label_column'=>trim((string)($_POST['derived_label_column'] ?? '')),
        'filter_mode'=>(string)($_POST['derived_filter_mode'] ?? 'none'),
        'filter_source_column'=>trim((string)($_POST['derived_filter_source_column'] ?? '')),
        'filter_field_name'=>trim((string)($_POST['derived_filter_field_name'] ?? '')),
        'min_selected'=>trim((string)($_POST['derived_min_selected'] ?? '0')),
        'max_selected'=>trim((string)($_POST['derived_max_selected'] ?? '0')),
        'max_options'=>trim((string)($_POST['derived_max_options'] ?? '1000')),
        'separator'=>DerivedMultiEnumManager::SEPARATOR,
        'managed_storage'=>!empty($configuration['derived_multienum']['managed_storage']),
    ];
    return DerivedMultiEnumManager::normalizeConfig($configuration);
}

function dataform_validate_html_pattern(string $pattern): bool
{
    if ($pattern === '') return true;
    $regex = '/^(?:' . str_replace('/', '\\/', $pattern) . ')$/u';
    return @preg_match($regex, '') !== false;
}


/**
 * HF147: Synchronisiert die im Designer konfigurierte Datensatz-Auswahl mit
 * einer echten n:1-Lookup-Beziehung. Dadurch ist die Quelle eines lookup-
 * Feldes direkt am Feld konfigurierbar und nicht nur über die separate
 * Beziehungsverwaltung.
 */
function dataform_sync_designer_lookup_relation(
    PDO $pdo,
    int $sourceDataformId,
    int $sourceFieldId,
    bool $required,
    int $targetDataformId,
    int $targetKeyFieldId,
    int $targetDisplayFieldId
): ?int {
    if ($sourceDataformId < 1 || $sourceFieldId < 1 || $targetDataformId < 1) {
        return null;
    }
    if ($targetDataformId === $sourceDataformId) {
        // Self lookups are valid, so intentionally allow them.
    }

    $targetCheck=$pdo->prepare('SELECT COUNT(*) FROM dataforms WHERE id=?');
    $targetCheck->execute([$targetDataformId]);
    if ((int)$targetCheck->fetchColumn() !== 1) {
        throw new RuntimeException('Die gewählte Auswahltabelle / das Kind-DataForm wurde nicht gefunden.');
    }

    foreach ([
        [$targetKeyFieldId,'Das gewählte Schlüsselfeld gehört nicht zur Auswahltabelle.'],
        [$targetDisplayFieldId,'Das gewählte Anzeigefeld gehört nicht zur Auswahltabelle.'],
    ] as [$targetFieldId,$message]) {
        if ((int)$targetFieldId < 1) continue;
        $fieldCheck=$pdo->prepare('SELECT COUNT(*) FROM dataform_fields WHERE id=? AND dataform_id=?');
        $fieldCheck->execute([(int)$targetFieldId,$targetDataformId]);
        if ((int)$fieldCheck->fetchColumn() !== 1) {
            throw new RuntimeException((string)$message);
        }
    }

    $existingStmt=$pdo->prepare(
        "SELECT id,name FROM dataform_relations
         WHERE relation_type='n:1'
           AND source_dataform_id=?
           AND source_field_id=?
         ORDER BY id
         LIMIT 1"
    );
    $existingStmt->execute([$sourceDataformId,$sourceFieldId]);
    $existing=$existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        RelationManager::updateManyToOne(
            $pdo,
            (int)$existing['id'],
            (string)$existing['name'],
            $sourceDataformId,
            $targetDataformId,
            $sourceFieldId,
            $targetDisplayFieldId,
            $required,
            $targetKeyFieldId,
            true
        );
        return (int)$existing['id'];
    }

    $name='designer_lookup_'.$sourceDataformId.'_'.$sourceFieldId;
    return RelationManager::createManyToOne(
        $pdo,
        $name,
        $sourceDataformId,
        $targetDataformId,
        $sourceFieldId,
        $targetDisplayFieldId,
        $required,
        $targetKeyFieldId,
        true
    );
}


function dataform_field_type_options(string $selected='text'): string
{
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='';
    foreach (DataFormFieldTypeRegistry::groupedOptions() as $group=>$options) {
        $html.='<optgroup label="'.$esc((string)$group).'">';
        foreach ($options as $option) {
            $value=(string)$option['value'];
            $html.='<option value="'.$esc($value).'"'.($selected===$value?' selected':'').'>'.$esc((string)$option['label']).'</option>';
        }
        $html.='</optgroup>';
    }
    return $html;
}

function dataform_field_type_settings_panel(string $fieldType,array $configuration=[],bool $newField=false): string
{
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $settings=DataFormFieldTypeRegistry::settings($fieldType,$configuration);
    $maxMb=max(1,(int)ceil(((int)($settings['max_bytes']??10485760))/1048576));
    $precision=$newField?'':(string)($settings['precision']??'');
    $scale=$newField?'':(string)($settings['scale']??'');
    $currency=(string)($settings['currency']??'EUR');
    $target=(string)($settings['target']??'_self');
    $template=(string)($settings['template']??'');
    $html='<fieldset class="df-field-type-settings" data-field-type-settings><legend>Typspezifische Einstellungen</legend>';
    $html.='<div class="df-field-type-panel" data-field-types="file image">';
    $html.='<label><strong>Speicherung</strong><select name="type_storage_driver"><option value="filesystem"'.(($settings['storage_driver']??'filesystem')==='filesystem'?' selected':'').'>Dateisystem</option><option value="database"'.(($settings['storage_driver']??'')==='database'?' selected':'').'>Datenbank</option></select></label>';
    $html.='<label><strong>Erlaubte MIME-Typen</strong><input name="type_allowed_mime" value="'.$esc($newField?'':(string)($settings['accept']??'')).'" placeholder="z. B. image/jpeg,image/png"></label>';
    $html.='<label><strong>Maximale Dateigröße (MB)</strong><input type="number" name="type_max_mb" min="1" max="100" value="'.$maxMb.'"></label>';
    $html.='<label data-field-types="image"><strong>Max. Bildbreite (px)</strong><input type="number" name="type_image_max_width" min="0" max="20000" value="'.(int)($settings['image_max_width']??0).'"><small>0 = unbegrenzt</small></label>';
    $html.='<label data-field-types="image"><strong>Max. Bildhöhe (px)</strong><input type="number" name="type_image_max_height" min="0" max="20000" value="'.(int)($settings['image_max_height']??0).'"><small>0 = unbegrenzt</small></label>';
    $html.='<label class="checkbox-line" data-field-types="image"><input type="checkbox" name="type_image_preview" value="1"'.(!array_key_exists('image_preview',$settings)||!empty($settings['image_preview'])?' checked':'').'> <span>Bildvorschau anzeigen</span></label>';
    $html.='</div>';
    $html.='<div class="df-field-type-panel" data-field-types="decimal currency percentage">';
    $html.='<label><strong>Gesamtstellen (Precision)</strong><input type="number" name="type_precision" min="1" max="65" value="'.$esc($precision).'" placeholder="Typstandard"></label>';
    $html.='<label><strong>Nachkommastellen (Scale)</strong><input type="number" name="type_scale" min="0" max="30" value="'.$esc($scale).'" placeholder="Typstandard"></label>';
    $html.='<label data-field-types="currency"><strong>Währungscode</strong><input name="type_currency" maxlength="3" value="'.$esc($currency).'" placeholder="EUR"></label>';
    $html.='</div>';
    $html.='<div class="df-field-type-panel" data-field-types="link"><label><strong>Standard-Linkziel</strong><select name="type_link_target"><option value="_self"'.($target==='_self'?' selected':'').'>gleiches Fenster</option><option value="_blank"'.($target==='_blank'?' selected':'').'>neues Fenster</option></select></label></div>';
    $html.='<div class="df-field-type-panel" data-field-types="json"><label class="checkbox-line"><input type="checkbox" name="type_json_pretty" value="1"'.(!array_key_exists('pretty',$settings)||!empty($settings['pretty'])?' checked':'').'> <span>JSON formatiert speichern/anzeigen</span></label></div>';
    $html.='<div class="df-field-type-panel" data-field-types="computed"><label class="full"><strong>Berechnungsvorlage</strong><textarea name="type_computed_template" rows="3" placeholder="z. B. {{vorname}} {{nachname}}">'.$esc($template).'</textarea><small>Felder werden mit <code>{{feldname}}</code> eingesetzt. Es wird kein PHP/JavaScript ausgeführt.</small></label></div>';
    $html.='<p class="df-field-help" data-field-types="password"><strong>Passwort:</strong> Werte werden beim Speichern gehasht. Ein leer gelassenes Passwort im Bearbeitungsformular behält den vorhandenen Hash.</p>';
    $html.='<p class="df-field-help" data-field-types="link"><strong>Link:</strong> speichert URL, Beschriftung und Ziel strukturiert als JSON.</p>';
    $html.='<p class="df-field-help" data-field-types="coordinates"><strong>Koordinaten:</strong> speichert Breitengrad und Längengrad strukturiert als JSON.</p>';
    $html.='</fieldset>';
    return $html;
}


function dataform_preview_control(array $field,array $configuration): string
{
    $type=(string)($field['field_type']??'text');
    $esc=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $placeholder=$esc((string)($configuration['placeholder']??''));
    if (in_array($type,['textarea','richtext','markdown','json'],true)) {
        return '<textarea rows="3" placeholder="'.$placeholder.'" disabled></textarea>';
    }
    if ($type==='select') {
        $html='<select disabled><option>'.$esc((string)($configuration['placeholder']??'Bitte auswählen')).'</option>';
        foreach((array)($configuration['options']??[]) as $option) $html.='<option>'.$esc((string)$option).'</option>';
        return $html.'</select>';
    }
    if (in_array($type,['multiselect','multi_lookup'],true)) {
        $html='<select multiple size="4" disabled>';
        foreach((array)($configuration['options']??[]) as $option) $html.='<option>'.$esc((string)$option).'</option>';
        return $html.'</select>';
    }
    if ($type==='derived_multienum') {
        return '<select multiple size="4" disabled><option>Abgeleitete Werte aus '.$esc((string)($configuration['derived_multienum']['source_table']??'Quelltabelle')).'</option></select>';
    }
    if (DataFormFieldTypeRegistry::isBoolean($type)) {
        return '<span class="df-checkbox-preview"><input type="checkbox" disabled> '.$esc((string)($field['label']??'')).'</span>';
    }
    if (DataFormFieldTypeRegistry::isMedia($type)) {
        $settings=DataFormFieldTypeRegistry::settings($type,$configuration);
        return '<input type="file" accept="'.$esc((string)($settings['accept']??'')).'" disabled>';
    }
    if ($type==='coordinates') return '<input type="text" value="52.0000, 13.0000" disabled>';
    if ($type==='tags') return '<input type="text" placeholder="tag1, tag2" disabled>';
    if ($type==='computed') return '<input type="text" value="berechnet" readonly disabled>';
    if ($type==='hidden') return '<input type="text" value="verborgen" readonly disabled>';
    $htmlType=DataFormFieldTypeRegistry::htmlInputType($type);
    return '<input type="'.$esc($htmlType).'" placeholder="'.$placeholder.'" disabled>';
}

try {
    $adminPdo = enterprise_pdo();
    enterprise_upgrade($adminPdo);

    if ($projectId < 1) {
        throw new RuntimeException('Kein Projekt ausgewählt. Öffnen Sie zuerst ein DataForm-Projekt aus der Projektverwaltung.');
    }

    $stmt = $adminPdo->prepare('SELECT * FROM projects WHERE id = ? AND product_type = ? LIMIT 1');
    $stmt->execute([$projectId, $isCalendarDataAdmin?'calendar':'dataform']);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Das ausgewählte DataForm-Projekt wurde nicht gefunden.');
    }

    $_SESSION['active_project_id'] = $projectId;

    $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
    $projectDriver = enterprise_project_store_driver($env, $project);
    $projectPdo = enterprise_project_store_for_project($env, $project);
    $dbConnected = true;

    // HF119: Calendar-internal DataForms are licensed by the Calendar product
    // itself. Normalize legacy registration_error/unlicensed states before the
    // workspace renders, without consuming individual DataForm license slots.
    if($isCalendarDataAdmin){
        try{
            require_once dirname(__DIR__).'/calendar/api/runtime.php';
            require_once dirname(__DIR__).'/calendar/system/CalendarDataFormBridge.php';
            require_once dirname(__DIR__,2).'/system/app/calendar_project.php';
            $calendarCtx=['project'=>$project,'env'=>$env,'config'=>enterprise_calendar_config_load($project)];
            easyit_calendar_dataform_mark_embedded($projectPdo,$calendarCtx);
        }catch(Throwable $calendarLicenseStateError){
            if($info==='') $info='Calendar/DataForm-Lizenzstatus: '.$calendarLicenseStateError->getMessage();
        }
    }

    // HF86 Phase 2: once a concrete DataForm is selected, its central policy
    // narrows the customer-wide license for both management UI and actions.
    if($selectedDataformId>0 && !$isCalendarDataAdmin){
        $selectedLicensePolicy=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::policy(dirname(__DIR__,2),$projectPdo,$projectId,$selectedDataformId,true);
        if($selectedLicensePolicy!==null){
            $dfAllows=static fn(string $cap,bool $fallback):bool=>\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::allows($selectedLicensePolicy,$cap,$fallback);
            $licenseDesignerAccess=enterprise_customer_has_module($user,'designer') && ($dfAllows('designer.validate',false)||$dfAllows('designer.compile',false)||$dfAllows('designer.publish',false));
            $licenseCanPublish=enterprise_customer_has_module($user,'designer') && $dfAllows('designer.publish',false);
            $licenseCanExport=enterprise_customer_has_module($user,'export') && $dfAllows('export.execute',false);
            $licenseCanApi=enterprise_customer_has_module($user,'api') && $dfAllows('api.access',false);
            $licenseCanRelations=$dfAllows('relation.read',false);
            $licenseCanEvents=$dfAllows('dataform.events',false);
            $licenseCanCustomCss=$dfAllows('dataform.custom_css',false);
            $licenseCanCustomJavascript=$dfAllows('dataform.custom_javascript',false);
            $licenseCanSearch=$dfAllows('dataform.search',false);
            $licenseCanFilter=$dfAllows('dataform.filter',false);
            $licenseCanPagination=$dfAllows('dataform.pagination',false);
            $licenseCanRead=$dfAllows('dataset.read',false);
            $licenseCanCreate=$dfAllows('dataset.create',false);
            $licenseCanUpdate=$dfAllows('dataset.update',false);
            $licenseCanDelete=$dfAllows('dataset.delete',false);
            if($sectionKey==='designer'&&!$licenseDesignerAccess) throw new RuntimeException('Der DataForm-Designer ist für dieses DataForm nicht freigegeben.');
        }
    }

    DataSourceManager::ensureSchema($projectPdo);
    RelationManager::ensureSchema($projectPdo);
    RelationManager::repairLegacyOneToMany($projectPdo);
    TableWorkspaceManager::ensureRegistry($projectPdo);
    DataFormManager::ensureTableBindingSchema($projectPdo);
    $sourceKeyMaterial = (string)($env['DATAFORM_APP_KEY'] ?? $env['APP_KEY'] ?? '');
    $sourceKeyAvailable = DataSourceManager::keyAvailable($sourceKeyMaterial);
    $sourceKeyProvisioned = false;
    $sourceKeyProvisionError = '';

    if (!$sourceKeyAvailable) {
        try {
            $secretState = DataFormSecretKey::ensure(
                dirname(__DIR__, 2) . '/DataForm5-Core/.env'
            );
            $sourceKeyMaterial = (string)$secretState['key'];
            $sourceKeyAvailable = DataSourceManager::keyAvailable($sourceKeyMaterial);
            $sourceKeyProvisioned = (bool)$secretState['created'];
        } catch (Throwable $secretProvisionThrowable) {
            $sourceKeyProvisionError = $secretProvisionThrowable->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'publish_remote_definition') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        if(!$licenseCanPublish) throw new RuntimeException('Veröffentlichen ist in der aktuellen Lizenz nicht freigegeben.');
        $publishDataformId=(int)($_POST['dataform'] ?? 0);
        if($publishDataformId<1) throw new RuntimeException('Kein DataForm für die Veröffentlichung ausgewählt.');
        $publishResult=\EasyIT\Enterprise\Licensing\EnterpriseDesignerBridge::publishCurrent(
            dirname(__DIR__, 2),
            $projectPdo,
            $projectId,
            $publishDataformId
        );
        $selectedDataformId=$publishDataformId;
        $sectionKey='dataform';
        $success='DataForm-Revision wurde auf dem geschützten Server validiert, kompiliert und veröffentlicht.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['save_source','test_source','delete_source'], true)) {
        if(!$projectWideScope) throw new RuntimeException('Datenquellen können nur mit vollständiger Projektzuordnung verwaltet werden.');
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $sourceAction=(string)$_POST['action'];
        $sectionKey='sources';

        if($sourceAction==='save_source'){
            $sourceId=(int)($_POST['source_id'] ?? 0);
            $parsed=DataSourceManager::fromPost($_POST);
            $savedId=DataSourceManager::save(
                $projectPdo,
                $sourceId,
                trim((string)($_POST['source_name'] ?? '')),
                (string)$parsed['driver'],
                (array)$parsed['config'],
                (string)($_POST['password'] ?? ''),
                isset($_POST['is_enabled']),
                $sourceKeyMaterial
            );
            $selectedSourceId=$savedId;
            $success=$sourceId>0?'Die Datenquelle wurde aktualisiert.':'Die Datenquelle wurde angelegt.';
        } elseif($sourceAction==='test_source'){
            $sourceId=(int)($_POST['source_id'] ?? 0);
            $result=DataSourceManager::test($projectPdo,$sourceId,$sourceKeyMaterial);
            $selectedSourceId=$sourceId;
            if($result['status']==='pass') $success='Verbindungstest erfolgreich.';
            else $error='Verbindungstest fehlgeschlagen: '.(string)$result['message'];
        } elseif($sourceAction==='delete_source'){
            DataSourceManager::delete($projectPdo,(int)($_POST['source_id'] ?? 0));
            $selectedSourceId=0;
            $success='Die Datenquelle wurde gelöscht.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dataform') {
        if(!$projectWideScope) throw new RuntimeException('Neue DataForms können nur mit vollständiger Projektzuordnung angelegt werden.');
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $description = trim((string)($_POST['description'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Bitte geben Sie einen Namen für das DataForm ein.');
        }
        if ($slug === '') {
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
            $slug = trim($slug, '-');
        }
        if ($slug === '') {
            throw new RuntimeException('Der interne Name konnte nicht erzeugt werden. Bitte verwenden Sie Buchstaben oder Ziffern.');
        }

        $check = $projectPdo->prepare('SELECT COUNT(*) FROM dataforms WHERE slug = ?');
        $check->execute([$slug]);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException('Ein DataForm mit diesem internen Namen existiert bereits.');
        }

        $insert = $projectPdo->prepare('INSERT INTO dataforms (name, slug, description, status) VALUES (?, ?, ?, ?)');
        $insert->execute([$name, $slug, $description !== '' ? $description : null, 'draft']);
        $success = 'Das DataForm „' . $name . '“ wurde angelegt.';
        $sectionKey = 'dataforms';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_dataform_settings') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        DataFormManager::ensureRuntimeSettingsSchema($projectPdo);

        $settingsDataformId=(int)($_POST['dataform'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $slug=strtolower(trim((string)($_POST['slug'] ?? '')));
        $slug=preg_replace('/[^a-z0-9-]+/','-',$slug) ?? '';
        $slug=trim($slug,'-');
        $description=trim((string)($_POST['description'] ?? ''));
        $status=trim((string)($_POST['status'] ?? 'draft'));
        $tableSaveMode=trim((string)($_POST['table_save_mode'] ?? 'manual'));
        $showSaveSuccess=isset($_POST['show_save_success']) ? 1 : 0;
        $viewMode=in_array((string)($_POST['view_mode']??'table'),['form','table','dialog'],true)?(string)$_POST['view_mode']:'table';
        $defaultPerPage=max(1,min(200,(int)($_POST['default_per_page']??20)));
        $showSearch=(int)($_POST['show_search']??1)===1?1:0;
        $showFilter=(int)($_POST['show_filter']??1)===1?1:0;
        $showPagination=isset($_POST['show_pagination'])?1:0;
        $allowCreate=isset($_POST['allow_create'])?1:0;
        $allowEdit=isset($_POST['allow_edit'])?1:0;
        $allowDelete=isset($_POST['allow_delete'])?1:0;
        $dialogSize=in_array((string)($_POST['dialog_size']??'large'),['small','medium','large','fullscreen'],true)?(string)$_POST['dialog_size']:'large';
        $cssClass=trim((string)($_POST['css_class']??''));
        if($cssClass!==''&&!preg_match('/^[a-zA-Z0-9 _-]{1,160}$/',$cssClass)) throw new RuntimeException('Die zusätzliche CSS-Klasse enthält ungültige Zeichen.');
        $additionalCss=trim((string)($_POST['additional_css']??''));
        if(strlen($additionalCss)>20000) throw new RuntimeException('AddCSS darf maximal 20.000 Zeichen enthalten.');
        if(stripos($additionalCss,'</style')!==false||stripos($additionalCss,'javascript:')!==false) throw new RuntimeException('AddCSS enthält nicht erlaubte Inhalte.');
        $eventKeys=DataFormEventDomains::DATAFORM_EVENTS;
        $recordEventKeys=DataFormEventDomains::RECORDSET_EVENTS;
        $events=DataFormEventDomains::dataFormHandlersFromPost($_POST,'event_');
        $eventsJson=json_encode($events,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);


        if ($settingsDataformId < 1) {
            throw new RuntimeException('Kein DataForm zum Bearbeiten ausgewählt.');
        }
        if ($name === '') {
            throw new RuntimeException('Bitte geben Sie einen Namen für das DataForm ein.');
        }
        if (mb_strlen($name) > 160) {
            throw new RuntimeException('Der DataForm-Name darf höchstens 160 Zeichen lang sein.');
        }
        if ($slug === '') {
            throw new RuntimeException('Bitte geben Sie einen gültigen internen Namen an.');
        }
        if (strlen($slug) > 160) {
            throw new RuntimeException('Der interne Name darf höchstens 160 Zeichen lang sein.');
        }
        if (!in_array($status,['draft','active','inactive','published','archived'],true)) {
            throw new RuntimeException('Der gewählte DataForm-Status ist nicht zulässig.');
        }
        if (!in_array($tableSaveMode,['manual','adhoc'],true)) {
            throw new RuntimeException('Das gewählte Speicherverhalten ist nicht zulässig.');
        }

        $exists=$projectPdo->prepare('SELECT COUNT(*) FROM dataforms WHERE id=?');
        $exists->execute([$settingsDataformId]);
        if ((int)$exists->fetchColumn() !== 1) {
            throw new RuntimeException('Das DataForm wurde nicht gefunden.');
        }
        $licenseCurrentStmt=$projectPdo->prepare('SELECT show_search,show_filter,show_pagination,allow_create,allow_edit,allow_delete,css_class,additional_css,event_handlers_json FROM dataforms WHERE id=? LIMIT 1');
        $licenseCurrentStmt->execute([$settingsDataformId]);
        $licenseCurrent=(array)($licenseCurrentStmt->fetch(PDO::FETCH_ASSOC)?:[]);
        if(!$licenseCanSearch)$showSearch=(int)($licenseCurrent['show_search']??0);
        if(!$licenseCanFilter)$showFilter=(int)($licenseCurrent['show_filter']??0);
        if(!$licenseCanPagination)$showPagination=(int)($licenseCurrent['show_pagination']??0);
        if(!$licenseCanCreate)$allowCreate=(int)($licenseCurrent['allow_create']??0);
        if(!$licenseCanUpdate)$allowEdit=(int)($licenseCurrent['allow_edit']??0);
        if(!$licenseCanDelete)$allowDelete=(int)($licenseCurrent['allow_delete']??0);
        if(!$licenseCanCustomCss){$cssClass=(string)($licenseCurrent['css_class']??'');$additionalCss=(string)($licenseCurrent['additional_css']??'');}
        if(!$licenseCanEvents)$eventsJson=(string)($licenseCurrent['event_handlers_json']??'{}');
        if(isset($_POST['save_recordset_events'])&&!$licenseCanEvents) throw new RuntimeException('DataForm-/DS-Events sind in der aktuellen Lizenz nicht freigegeben.');
        $duplicate=$projectPdo->prepare('SELECT COUNT(*) FROM dataforms WHERE slug=? AND id<>?');
        $duplicate->execute([$slug,$settingsDataformId]);
        if ((int)$duplicate->fetchColumn() > 0) {
            throw new RuntimeException('Ein anderes DataForm verwendet bereits diesen internen Namen.');
        }

        if (isset($_POST['save_recordset_events'])) {
            $recordsetKey=(string)($_POST['recordset_key']??DataFormRecordSetEventRepository::DEFAULT_RECORDSET_KEY);
            $recordEvents=DataFormEventDomains::recordSetHandlersFromPost($_POST);
            DataFormRecordSetEventRepository::save($projectPdo,$settingsDataformId,$recordsetKey,$recordEvents);
            $success='DS-Ereignishandler wurden gespeichert.';
        } else {
        $update=$projectPdo->prepare(
            'UPDATE dataforms SET name=?,slug=?,description=?,status=?,table_save_mode=?,show_save_success=?,view_mode=?,default_per_page=?,show_search=?,show_filter=?,show_pagination=?,allow_create=?,allow_edit=?,allow_delete=?,dialog_size=?,css_class=?,additional_css=?,event_handlers_json=? WHERE id=?'
        );
        $update->execute([
            $name,$slug,$description !== '' ? $description : null,$status,
            $tableSaveMode,$showSaveSuccess,$viewMode,$defaultPerPage,
            $showSearch,$showFilter,$showPagination,$allowCreate,$allowEdit,$allowDelete,
            $dialogSize,$cssClass !== '' ? $cssClass : null,
            $additionalCss !== '' ? $additionalCss : null,$eventsJson,$settingsDataformId,
        ]);
        }
        $selectedDataformId=$settingsDataformId;
        $sectionKey='dataform';
        // compatibility-signature: UPDATE dataforms SET name=?,slug=?,description=?,status=?,table_save_mode=?,show_save_success=? WHERE id=?
        $success='Die DataForm-Einstellungen wurden gespeichert.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_dataform') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $deleteDataformId=(int)($_POST['dataform_id'] ?? 0);
        $confirmation=trim((string)($_POST['confirm_name'] ?? ''));

        if ($deleteDataformId < 1) {
            throw new RuntimeException('Kein DataForm zum Löschen ausgewählt.');
        }

        $check=$projectPdo->prepare('SELECT name,df_uuid,license_status FROM dataforms WHERE id=? LIMIT 1');
        $check->execute([$deleteDataformId]);
        $deleteRow=$check->fetch(PDO::FETCH_ASSOC);
        if (!$deleteRow) {
            throw new RuntimeException('Das DataForm wurde nicht gefunden oder bereits gelöscht.');
        }
        $deleteName=(string)$deleteRow['name'];
        if (!hash_equals($deleteName,$confirmation)) {
            throw new RuntimeException('Die Löschbestätigung stimmt nicht mit dem DataForm-Namen überein.');
        }

        // HF85/HF119: standalone DataForms release their central slot first.
        // Calendar-internal DataForms are embedded in the Calendar product and
        // never consume an individual DataForm slot.
        $releaseResult=$isCalendarDataAdmin
            ? ['status'=>'embedded_calendar','slot_released'=>false,'already_released'=>true]
            : \EasyIT\Enterprise\Licensing\EnterpriseDataFormRegistryBridge::releaseForDelete(
                dirname(__DIR__, 2),$projectPdo,$projectId,$deleteDataformId
            );
        $deleteSummary=DataFormManager::delete($projectPdo,$deleteDataformId);
        $success=$isCalendarDataAdmin
            ? 'Das Calendar-interne DataForm „'.(string)$deleteSummary['name'].'“ wurde vollständig gelöscht.'
            : 'Das DataForm „'.(string)$deleteSummary['name'].'“ wurde vollständig gelöscht und der Lizenzplatz zentral freigegeben.';
        $info='Entfernt: '
            .(int)$deleteSummary['fields'].' Felder, '
            .(int)$deleteSummary['records'].' Datensätze, '
            .(int)$deleteSummary['relations'].' Beziehungen, '
            .(int)$deleteSummary['queries'].' Abfragen, '
            .(int)$deleteSummary['reports'].' Berichte und '
            .(int)$deleteSummary['api_endpoints'].' API-Endpunkte.'
            .($isCalendarDataAdmin?' Calendar-intern: kein separater DataForm-Lizenzplatz.':' Lizenzplatz: '.(!empty($releaseResult['slot_released'])?'freigegeben':(!empty($releaseResult['already_released'])?'bereits freigegeben':'nicht belegt')).'.');
        $selectedDataformId=0;
        $selectedDataform=null;
        $sectionKey='dataforms';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_field') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        if ($selectedDataformId < 1) {
            throw new RuntimeException('Kein DataForm ausgewählt.');
        }
        $fieldName = strtolower(trim((string)($_POST['field_name'] ?? '')));
        $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldName) ?? '';
        $fieldName = trim($fieldName, '_');
        $label = trim((string)($_POST['label'] ?? ''));
        $fieldType = (string)($_POST['field_type'] ?? 'text');
        $allowedTypes = DataFormFieldTypeRegistry::allowedTypes();
        if ($fieldName === '' || $label === '') {
            throw new RuntimeException('Interner Feldname und Beschriftung sind erforderlich.');
        }
        if (!in_array($fieldType, $allowedTypes, true)) {
            throw new RuntimeException('Der gewählte Feldtyp ist nicht zulässig.');
        }
        $exists = $projectPdo->prepare('SELECT COUNT(*) FROM dataforms WHERE id = ?');
        $exists->execute([$selectedDataformId]);
        if ((int)$exists->fetchColumn() !== 1) {
            throw new RuntimeException('Das ausgewählte DataForm wurde nicht gefunden.');
        }
        $check = $projectPdo->prepare('SELECT COUNT(*) FROM dataform_fields WHERE dataform_id = ? AND name = ?');
        $check->execute([$selectedDataformId, $fieldName]);
        if ((int)$check->fetchColumn() > 0) {
            throw new RuntimeException('Ein Feld mit diesem internen Namen existiert bereits.');
        }
        $positionStmt = $projectPdo->prepare('SELECT COALESCE(MAX(position), 0) + 10 FROM dataform_fields WHERE dataform_id = ?');
        $positionStmt->execute([$selectedDataformId]);
        $position = (int)$positionStmt->fetchColumn();
        $configuration = [];
        $placeholder = trim((string)($_POST['placeholder'] ?? ''));
        if ($placeholder !== '') { $configuration['placeholder'] = $placeholder; }
        if (in_array($fieldType,['select','multiselect','multi_lookup'],true)) {
            $options = preg_split('/\R+/', trim((string)($_POST['options'] ?? ''))) ?: [];
            $configuration['options'] = array_values(array_filter(array_map('trim',$options),static fn($v):bool=>$v!==''));
        }

        $derivedStorage = [
            'created'=>false,
            'table'=>null,
            'column'=>null,
        ];

        if ($fieldType === DerivedMultiEnumManager::FIELD_TYPE) {
            $configuration = dataform_apply_derived_multienum_post($configuration);
            $configuration = DerivedMultiEnumManager::validateDefinition(
                $projectPdo,
                $configuration,
                $selectedDataformId
            );
            $derivedStorage = DerivedMultiEnumManager::ensureStorageColumn(
                $projectPdo,
                $selectedDataformId,
                $fieldName,
                $configuration
            );
            $configuration = (array)$derivedStorage['configuration'];
        }

        $configuration = DataFormFieldTypeRegistry::applyPostSettings($configuration,$fieldType,$_POST);
        // HF149: Die im Designer gewählte Lookup-Quelle wird zusätzlich direkt
        // am Feld persistiert. Dadurch kann die Runtime die Auswahl auch dann
        // korrekt rekonstruieren, wenn eine bestehende Produktrelation später
        // synchronisiert oder umbenannt wurde.
        if ($fieldType === 'lookup') {
            $configuration['lookup_target_dataform_id']=(int)($_POST['lookup_target_dataform_id'] ?? 0);
            $configuration['lookup_target_key_field_id']=(int)($_POST['lookup_target_key_field_id'] ?? 0);
            $configuration['lookup_target_display_field_id']=(int)($_POST['lookup_target_display_field_id'] ?? 0);
        } else {
            unset($configuration['lookup_target_dataform_id'],$configuration['lookup_target_key_field_id'],$configuration['lookup_target_display_field_id']);
        }
        $designerStorage=['created'=>false,'table'=>null,'column'=>null,'configuration'=>$configuration];
        if ($fieldType !== DerivedMultiEnumManager::FIELD_TYPE) {
            $designerStorage=DataFormManager::ensureDesignerStorageColumn(
                $projectPdo,$selectedDataformId,$fieldName,$fieldType,$configuration
            );
            $configuration=(array)$designerStorage['configuration'];
        }

        $insert = $projectPdo->prepare('INSERT INTO dataform_fields (dataform_id, name, label, field_type, position, is_required, configuration_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        try {
            $insert->execute([
                $selectedDataformId,
                $fieldName,
                $label,
                $fieldType,
                $position,
                isset($_POST['is_required']) ? 1 : 0,
                $configuration ? json_encode($configuration, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null
            ]);
        } catch (Throwable $fieldCreateThrowable) {
            DerivedMultiEnumManager::rollbackStorageColumn(
                $projectPdo,
                $derivedStorage['table'] ?? null,
                $derivedStorage['column'] ?? null,
                !empty($derivedStorage['created'])
            );
            DataFormManager::rollbackDesignerStorageColumn(
                $projectPdo,
                $designerStorage['table'] ?? null,
                $designerStorage['column'] ?? null,
                !empty($designerStorage['created'])
            );
            throw $fieldCreateThrowable;
        }

        $success = 'Das Feld „' . $label . '“ wurde angelegt.';
        if ($fieldType === DerivedMultiEnumManager::FIELD_TYPE) {
            $info = 'Die abgeleitete Mehrfachauswahl speichert stabile Quellwerte kommasepariert. '
                .(!empty($derivedStorage['table'])
                    ? 'Die physische Spalte „'.(string)$derivedStorage['column'].'“ ist an die Tabelle „'.(string)$derivedStorage['table'].'“ gebunden.'
                    : 'Das DataForm verwendet den generischen Datenspeicher.');
        }
        $sectionKey = 'dataform';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_field') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $fieldId = (int)($_POST['field_id'] ?? 0);
        if ($selectedDataformId < 1 || $fieldId < 1) { throw new RuntimeException('Ungültige Feldangabe.'); }
        $label = trim((string)($_POST['label'] ?? ''));
        $fieldType = (string)($_POST['field_type'] ?? 'text');
        $allowedTypes = DataFormFieldTypeRegistry::allowedTypes();
        if ($label === '' || !in_array($fieldType, $allowedTypes, true)) { throw new RuntimeException('Beschriftung oder Feldtyp ist ungültig.'); }
        $cfgStmt = $projectPdo->prepare('SELECT name,configuration_json FROM dataform_fields WHERE id = ? AND dataform_id = ?');
        $cfgStmt->execute([$fieldId, $selectedDataformId]);
        $fieldRow = $cfgStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fieldRow) { throw new RuntimeException('Das Feld wurde nicht gefunden.'); }
        $fieldInternalName = (string)$fieldRow['name'];
        $configuration = dataform_field_config($fieldRow['configuration_json'] ?? null,$fieldType);
        $configuration['placeholder'] = trim((string)($_POST['placeholder'] ?? ''));
        $configuration['width'] = in_array((string)($_POST['width'] ?? '100'), ['25','33','50','66','75','100'], true) ? (string)$_POST['width'] : '100';
        if (in_array($fieldType,['select','multiselect','multi_lookup'],true)) {
            $options = preg_split('/\R+/', trim((string)($_POST['options'] ?? ''))) ?: [];
            $configuration['options'] = array_values(array_filter(array_map('trim', $options), static fn($v) => $v !== ''));
        } else { unset($configuration['options']); }

        $derivedStorage = [
            'created'=>false,
            'table'=>null,
            'column'=>null,
        ];
        if ($fieldType === DerivedMultiEnumManager::FIELD_TYPE) {
            $configuration = dataform_apply_derived_multienum_post($configuration);
            $configuration = DerivedMultiEnumManager::validateDefinition(
                $projectPdo,
                $configuration,
                $selectedDataformId
            );
            $derivedStorage = DerivedMultiEnumManager::ensureStorageColumn(
                $projectPdo,
                $selectedDataformId,
                $fieldInternalName,
                $configuration
            );
            $configuration = (array)$derivedStorage['configuration'];
        } else {
            unset($configuration['derived_multienum']);
        }

        $configuration['help_text'] = mb_substr(trim((string)($_POST['help_text'] ?? '')), 0, 1000);

        $existingDefaultMode=(string)($configuration['default_mode']??'defined');
        $existingDefaultJsProvider=(string)($configuration['default_js_provider']??'');
        $defaultMode = (string)($_POST['default_mode'] ?? 'defined');
        $configuration['default_mode'] = in_array($defaultMode, ['defined','current_timestamp','javascript','parent_field'], true)
            ? $defaultMode : 'defined';
        $configuration['default_value'] = mb_substr((string)($_POST['default_value'] ?? ''), 0, 4000);

        $defaultJsProvider = trim((string)($_POST['default_js_provider'] ?? ''));
        if(!$licenseCanCustomJavascript){
            if($configuration['default_mode']==='javascript'&&$existingDefaultMode!=='javascript') throw new RuntimeException('Zusätzliches JavaScript ist in der aktuellen Lizenz nicht freigegeben.');
            if($existingDefaultMode==='javascript'){$configuration['default_mode']='javascript';$defaultJsProvider=$existingDefaultJsProvider;}
        }
        if (
            $defaultJsProvider !== ''
            && !preg_match('/^[A-Za-z_$][A-Za-z0-9_$.-]{0,127}$/', $defaultJsProvider)
        ) {
            throw new RuntimeException('Der JavaScript-Providername ist ungültig.');
        }
        if ($configuration['default_mode'] === 'javascript' && $defaultJsProvider === '') {
            throw new RuntimeException('Für JavaScript-Funktion muss ein Providername angegeben werden.');
        }
        if (
            $configuration['default_mode'] === 'current_timestamp'
            && !in_array($fieldType, ['text','textarea','richtext','markdown','date','time','datetime'], true)
        ) {
            throw new RuntimeException('CURRENT_TIMESTAMP ist für diesen Feldtyp nicht zulässig.');
        }
        $configuration['default_js_provider'] = $defaultJsProvider;

        $parentRelationId = (int)($_POST['parent_relation_id'] ?? 0);
        $parentFieldId = (int)($_POST['parent_field_id'] ?? 0);

        if ($configuration['default_mode'] === 'parent_field') {
            if ($parentRelationId < 1 || $parentFieldId < 1) {
                throw new RuntimeException(
                    'Für „Wert aus Eltern-DataForm“ müssen Elternbeziehung und Elternfeld gewählt werden.'
                );
            }

            $relationStmt = $projectPdo->prepare(
                "SELECT id,source_dataform_id,target_dataform_id,lookup_field_id
                 FROM dataform_relations
                 WHERE id=?
                   AND target_dataform_id=?
                   AND relation_type='1:n'
                   AND is_enabled=1
                 LIMIT 1"
            );
            $relationStmt->execute([$parentRelationId, $selectedDataformId]);
            $parentRelation = $relationStmt->fetch();

            if (!$parentRelation) {
                throw new RuntimeException(
                    'Die gewählte Elternbeziehung ist für dieses Kind-DataForm nicht gültig.'
                );
            }

            if ((int)($parentRelation['lookup_field_id'] ?? 0) < 1) {
                throw new RuntimeException(
                    'Die Elternbeziehung benötigt ein Fremdschlüsselfeld im Kind-DataForm.'
                );
            }

            $parentFieldStmt = $projectPdo->prepare(
                'SELECT COUNT(*) FROM dataform_fields WHERE id=? AND dataform_id=?'
            );
            $parentFieldStmt->execute([
                $parentFieldId,
                (int)$parentRelation['source_dataform_id']
            ]);

            if ((int)$parentFieldStmt->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'Das gewählte Elternfeld gehört nicht zum Eltern-DataForm.'
                );
            }

            $configuration['parent_relation_id'] = $parentRelationId;
            $configuration['parent_field_id'] = $parentFieldId;
        } else {
            $configuration['parent_relation_id'] = 0;
            $configuration['parent_field_id'] = 0;
        }

        $minLengthRaw = trim((string)($_POST['min_length'] ?? ''));
        $maxLengthRaw = trim((string)($_POST['max_length'] ?? ''));
        $configuration['min_length'] = $minLengthRaw === '' ? null : max(0, (int)$minLengthRaw);
        $configuration['max_length'] = $maxLengthRaw === '' ? null : max(0, (int)$maxLengthRaw);
        if ($configuration['min_length'] !== null && $configuration['max_length'] !== null && $configuration['min_length'] > $configuration['max_length']) {
            throw new RuntimeException('Die minimale Zeichenlänge darf nicht größer als die maximale Zeichenlänge sein.');
        }
        $configuration['pattern'] = trim((string)($_POST['pattern'] ?? ''));
        if (!dataform_validate_html_pattern($configuration['pattern'])) throw new RuntimeException('Das Validierungsmuster ist kein gültiger regulärer Ausdruck.');
        $configuration['min_value'] = trim((string)($_POST['min_value'] ?? ''));
        $configuration['max_value'] = trim((string)($_POST['max_value'] ?? ''));
        $configuration['step'] = trim((string)($_POST['step'] ?? ''));
        $autocompleteAllowed = ['', 'off', 'on', 'name', 'given-name', 'family-name', 'email', 'username', 'organization', 'tel', 'street-address', 'postal-code', 'country', 'current-password', 'new-password'];
        $autocomplete = (string)($_POST['autocomplete'] ?? '');
        $configuration['autocomplete'] = in_array($autocomplete, $autocompleteAllowed, true) ? $autocomplete : '';
        $inputmodeAllowed = ['', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'];
        $inputmode = (string)($_POST['inputmode'] ?? '');
        $configuration['inputmode'] = in_array($inputmode, $inputmodeAllowed, true) ? $inputmode : '';
        $configuration['rows'] = max(2, min(30, (int)($_POST['rows'] ?? 3)));
        $configuration['readonly'] = isset($_POST['readonly']);
        $configuration['hidden'] = isset($_POST['hidden']);
        $configuration['trim'] = isset($_POST['trim']);
        $configuration['list_visible'] = isset($_POST['list_visible']);
        $configuration['searchable'] = isset($_POST['searchable']);
        $configuration['filterable'] = isset($_POST['filterable']);
        $configuration['sortable'] = isset($_POST['sortable']);
        $configuration = DataFormFieldTypeRegistry::applyPostSettings($configuration,$fieldType,$_POST);
        // HF149: Die im Designer gewählte Lookup-Quelle wird zusätzlich direkt
        // am Feld persistiert. Dadurch kann die Runtime die Auswahl auch dann
        // korrekt rekonstruieren, wenn eine bestehende Produktrelation später
        // synchronisiert oder umbenannt wurde.
        if ($fieldType === 'lookup') {
            $configuration['lookup_target_dataform_id']=(int)($_POST['lookup_target_dataform_id'] ?? 0);
            $configuration['lookup_target_key_field_id']=(int)($_POST['lookup_target_key_field_id'] ?? 0);
            $configuration['lookup_target_display_field_id']=(int)($_POST['lookup_target_display_field_id'] ?? 0);
        } else {
            unset($configuration['lookup_target_dataform_id'],$configuration['lookup_target_key_field_id'],$configuration['lookup_target_display_field_id']);
        }
        $designerStorage=['created'=>false,'table'=>null,'column'=>null,'configuration'=>$configuration];
        if ($fieldType !== DerivedMultiEnumManager::FIELD_TYPE) {
            $designerStorage=DataFormManager::ensureDesignerStorageColumn(
                $projectPdo,$selectedDataformId,$fieldInternalName,$fieldType,$configuration
            );
            $configuration=(array)$designerStorage['configuration'];
        }
        $update = $projectPdo->prepare('UPDATE dataform_fields SET label = ?, field_type = ?, is_required = ?, configuration_json = ? WHERE id = ? AND dataform_id = ?');
        try {
            $update->execute([
                $label,
                $fieldType,
                isset($_POST['is_required']) ? 1 : 0,
                json_encode($configuration, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $fieldId,
                $selectedDataformId
            ]);
        } catch (Throwable $fieldUpdateThrowable) {
            DerivedMultiEnumManager::rollbackStorageColumn(
                $projectPdo,
                $derivedStorage['table'] ?? null,
                $derivedStorage['column'] ?? null,
                !empty($derivedStorage['created'])
            );
            DataFormManager::rollbackDesignerStorageColumn(
                $projectPdo,
                $designerStorage['table'] ?? null,
                $designerStorage['column'] ?? null,
                !empty($designerStorage['created'])
            );
            throw $fieldUpdateThrowable;
        }
        if ($fieldType === 'lookup') {
            $lookupTargetDataformId=(int)($_POST['lookup_target_dataform_id'] ?? 0);
            $lookupTargetKeyFieldId=(int)($_POST['lookup_target_key_field_id'] ?? 0);
            $lookupTargetDisplayFieldId=(int)($_POST['lookup_target_display_field_id'] ?? 0);
            if ($lookupTargetDataformId > 0) {
                dataform_sync_designer_lookup_relation(
                    $projectPdo,
                    $selectedDataformId,
                    $fieldId,
                    isset($_POST['is_required']),
                    $lookupTargetDataformId,
                    $lookupTargetKeyFieldId,
                    $lookupTargetDisplayFieldId
                );
            }
        }
        $selectedFieldId = $fieldId;
        $success = 'Die Feldeigenschaften wurden gespeichert.';
        if ($fieldType === DerivedMultiEnumManager::FIELD_TYPE) {
            $info = 'Mehrfachauswahl aktiv: gespeichert werden stabile Quellwerte als CSV mit Trennzeichen „,“. ';
            $info .= !empty($derivedStorage['table'])
                ? 'Speicher: '.(string)$derivedStorage['table'].'.'.(string)$derivedStorage['column'].'.'
                : 'Speicher: generischer DataForm-Datensatz.';
        }
        $sectionKey = 'designer';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_field') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? '');
        $currentStmt = $projectPdo->prepare('SELECT id, position FROM dataform_fields WHERE id = ? AND dataform_id = ?');
        $currentStmt->execute([$fieldId, $selectedDataformId]);
        $current = $currentStmt->fetch();
        if (!$current || !in_array($direction, ['up','down'], true)) { throw new RuntimeException('Das Feld kann nicht verschoben werden.'); }
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $neighborStmt = $projectPdo->prepare("SELECT id, position FROM dataform_fields WHERE dataform_id = ? AND position $operator ? ORDER BY position $order, id $order LIMIT 1");
        $neighborStmt->execute([$selectedDataformId, (int)$current['position']]);
        $neighbor = $neighborStmt->fetch();
        if ($neighbor) {
            $projectPdo->beginTransaction();
            $tmp = -1 * (int)$current['position'] - 1;
            $projectPdo->prepare('UPDATE dataform_fields SET position = ? WHERE id = ?')->execute([$tmp, $fieldId]);
            $projectPdo->prepare('UPDATE dataform_fields SET position = ? WHERE id = ?')->execute([(int)$current['position'], (int)$neighbor['id']]);
            $projectPdo->prepare('UPDATE dataform_fields SET position = ? WHERE id = ?')->execute([(int)$neighbor['position'], $fieldId]);
            $projectPdo->commit();
            $success = 'Die Feldreihenfolge wurde geändert.';
        }
        $selectedFieldId = $fieldId;
        $sectionKey = 'designer';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_field') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $fieldId = (int)($_POST['field_id'] ?? 0);
        if ($selectedDataformId < 1 || $fieldId < 1) {
            throw new RuntimeException('Ungültige Feldangabe.');
        }
        $deleteCfgStmt=$projectPdo->prepare('SELECT field_type,configuration_json FROM dataform_fields WHERE id=? AND dataform_id=? LIMIT 1');
        $deleteCfgStmt->execute([$fieldId,$selectedDataformId]);
        $deleteFieldRow=$deleteCfgStmt->fetch(PDO::FETCH_ASSOC);
        if (!$deleteFieldRow) throw new RuntimeException('Das Feld wurde nicht gefunden.');
        $deleteFieldCfg=dataform_field_config($deleteFieldRow['configuration_json']??null,(string)$deleteFieldRow['field_type']);
        $delete = $projectPdo->prepare('DELETE FROM dataform_fields WHERE id = ? AND dataform_id = ?');
        $delete->execute([$fieldId, $selectedDataformId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Das Feld wurde nicht gefunden.');
        $managedColumnDropped=false;
        try { $managedColumnDropped=DataFormManager::dropManagedDesignerStorageColumn($projectPdo,$deleteFieldCfg); }
        catch (Throwable $dropError) { $info='Hinweis: Die Feldmetadaten wurden gelöscht, die verwaltete physische Spalte konnte jedoch nicht entfernt werden: '.$dropError->getMessage(); }
        $success = 'Das Feld wurde gelöscht.'.($managedColumnDropped?' Die zugehörige verwaltete Tabellenspalte wurde ebenfalls entfernt.':'');
        $sectionKey = 'dataform';
    }

    $dataSources = DataSourceManager::list($projectPdo);

    $tableSources = [
        [
            'key'=>'system',
            'name'=>'Projekt-Datenbank',
            'driver'=>$projectDriver ?? 'mysql',
            'system'=>true,
        ],
    ];
    foreach ($dataSources as $sourceRow) {
        if (empty($sourceRow['is_enabled'])) continue;
        $tableSources[]=[
            'key'=>'source-'.(int)$sourceRow['id'],
            'name'=>(string)$sourceRow['name'],
            'driver'=>(string)$sourceRow['driver'],
            'system'=>false,
        ];
    }

    if ($tableSourceKey !== 'system') {
        if (
            preg_match('/^source-(\d+)$/',$tableSourceKey,$sourceMatch) !== 1
        ) {
            $tableSourceKey='system';
        } else {
            $sourceId=(int)$sourceMatch[1];
            foreach ($dataSources as $sourceCandidate) {
                if (
                    (int)$sourceCandidate['id'] === $sourceId
                    && !empty($sourceCandidate['is_enabled'])
                ) {
                    $tableSourceRow=$sourceCandidate;
                    break;
                }
            }
            if ($tableSourceRow === null) {
                $tableSourceKey='system';
                if ($sectionKey === 'tables') {
                    $tableError='Die gewählte Datenquelle ist nicht verfügbar oder deaktiviert.';
                }
            }
        }
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && (string)($_POST['action'] ?? '') === 'create_dataform_from_table'
    ) {
        if(!$projectWideScope) throw new RuntimeException('DataForms aus Tabellen können nur mit vollständiger Projektzuordnung angelegt werden.');
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $tableName=trim((string)($_POST['table'] ?? ''));
        $postedTableSource=preg_replace(
            '/[^a-z0-9-]/',
            '',
            strtolower((string)($_POST['table_source'] ?? 'system'))
        ) ?: 'system';

        if ($postedTableSource === 'system') {
            $createdDataform=DataFormManager::createFromManagedTable(
                $projectPdo,
                $tableName
            );
        } elseif (
            preg_match('/^source-(\d+)$/',$postedTableSource,$csvSourceMatch)===1
            && $tableSourceRow!==null
            && (int)$tableSourceRow['id']===(int)$csvSourceMatch[1]
            && !empty($tableSourceRow['is_enabled'])
            && (string)$tableSourceRow['driver']==='csv'
        ) {
            $createdDataform=DataFormManager::createFromCsvTable(
                $projectPdo,
                (int)$tableSourceRow['id'],
                $tableName
            );
        } else {
            throw new RuntimeException(
                'Aus dieser externen Datenquelle kann in dieser Phase noch kein schreibbares DataForm erzeugt werden.'
            );
        }

        $selectedDataformId=(int)$createdDataform['dataform_id'];
        $selectedTableName=$tableName;
        $sectionKey='designer';
        $success=$createdDataform['created']
            ? 'Das DataForm „'.(string)$createdDataform['name'].'“ wurde aus der Tabelle „'.$tableName.'“ mit '.(int)$createdDataform['fields'].' Feldern erzeugt.'
            : 'Für die Tabelle „'.$tableName.'“ existiert bereits das DataForm „'.(string)$createdDataform['name'].'“.';
        $info=(($createdDataform['source_id']??0)>0
            ? 'CSV-Bindung aktiv: Datensätze werden direkt in der CSV-Tabelle gespeichert. '
            : '')
            .'Die physische Spalte id bleibt technischer Primärschlüssel und wird nicht als normales Formularfeld angelegt.';
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array(
            (string)($_POST['action'] ?? ''),
            ['create_csv_table','drop_csv_table','add_csv_column','rename_csv_column','drop_csv_column'],
            true
        )
    ) {
        if(!$projectWideScope) throw new RuntimeException('Tabellen können nur mit vollständiger Projektzuordnung verwaltet werden.');
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $sectionKey='tables';
        if (
            $tableSourceRow===null
            || empty($tableSourceRow['is_enabled'])
            || (string)$tableSourceRow['driver']!=='csv'
        ) {
            throw new RuntimeException('Die gewählte CSV-Datenquelle ist nicht verfügbar oder deaktiviert.');
        }

        $csvAction=(string)$_POST['action'];
        $csvSourceId=(int)$tableSourceRow['id'];
        $tableName=trim((string)($_POST['table'] ?? $_POST['table_name'] ?? ''));

        if ($csvAction==='create_csv_table') {
            TableWorkspaceManager::createCsvTable(
                $tableSourceRow,
                $sourceKeyMaterial,
                $tableName,
                (string)($_POST['columns'] ?? '')
            );
            $selectedTableName=$tableName;
            $selectedColumnName='';
            $success='Die CSV-Tabelle „'.$tableName.'“ wurde angelegt.';
        } elseif ($csvAction==='drop_csv_table') {
            $bound=DataFormManager::tableBinding(
                $projectPdo,
                $tableName,
                'external',
                $csvSourceId
            );
            if ($bound!==null) {
                throw new RuntimeException(
                    'Die CSV-Tabelle ist mit dem DataForm „'.(string)$bound['dataform_name']
                    .'“ verbunden. Löschen Sie zuerst das zugehörige DataForm.'
                );
            }
            TableWorkspaceManager::dropCsvTable(
                $tableSourceRow,
                $sourceKeyMaterial,
                $tableName
            );
            $selectedTableName='';
            $selectedColumnName='';
            $success='Die CSV-Tabelle „'.$tableName.'“ wurde gelöscht.';
        } elseif ($csvAction==='add_csv_column') {
            $columnName=trim((string)($_POST['column_name'] ?? ''));
            TableWorkspaceManager::addCsvColumn(
                $tableSourceRow,
                $sourceKeyMaterial,
                $tableName,
                $columnName
            );
            $bound=DataFormManager::tableBinding($projectPdo,$tableName,'external',$csvSourceId);
            if ($bound!==null) {
                DataFormManager::synchronizeBoundTableFields($projectPdo,(int)$bound['dataform_id']);
            }
            $selectedTableName=$tableName;
            $selectedColumnName=$columnName;
            $success='Das CSV-Feld „'.$columnName.'“ wurde angelegt.';
        } elseif ($csvAction==='rename_csv_column') {
            $columnName=trim((string)($_POST['column'] ?? ''));
            $newName=trim((string)($_POST['new_column_name'] ?? ''));
            TableWorkspaceManager::renameCsvColumn(
                $tableSourceRow,
                $sourceKeyMaterial,
                $tableName,
                $columnName,
                $newName
            );
            DataFormManager::renameCsvBoundColumn(
                $projectPdo,
                $csvSourceId,
                $tableName,
                $columnName,
                $newName
            );
            $selectedTableName=$tableName;
            $selectedColumnName=$newName;
            $success='Das CSV-Feld „'.$columnName.'“ wurde in „'.$newName.'“ umbenannt.';
        } elseif ($csvAction==='drop_csv_column') {
            $columnName=trim((string)($_POST['column'] ?? ''));
            TableWorkspaceManager::dropCsvColumn(
                $tableSourceRow,
                $sourceKeyMaterial,
                $tableName,
                $columnName
            );
            DataFormManager::removeCsvBoundColumnField(
                $projectPdo,
                $csvSourceId,
                $tableName,
                $columnName
            );
            $selectedTableName=$tableName;
            $selectedColumnName='';
            $success='Das CSV-Feld „'.$columnName.'“ wurde gelöscht.';
        }
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array(
            (string)($_POST['action'] ?? ''),
            ['create_managed_table','drop_managed_table','add_managed_column','update_managed_column','drop_managed_column','move_managed_column'],
            true
        )
    ) {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $sectionKey='tables';
        $tableSourceKey='system';
        $tableSourceRow=null;
        $tableAction=(string)$_POST['action'];

        if ($tableAction === 'create_managed_table') {
            $tableName=trim((string)($_POST['table_name'] ?? ''));
            TableWorkspaceManager::createManagedTable(
                $projectPdo,
                $tableName,
                (string)($_POST['columns'] ?? '')
            );
            $selectedTableName=$tableName;
            $success='Die Tabelle „'.$tableName.'“ wurde angelegt.';
        } elseif ($tableAction === 'drop_managed_table') {
            $tableName=trim((string)($_POST['table'] ?? ''));
            DerivedMultiEnumManager::assertSourceTableNotReferenced(
                $projectPdo,
                $tableName
            );
            TableWorkspaceManager::dropManagedTable(
                $projectPdo,
                $tableName
            );
            $selectedTableName='';
            $selectedColumnName='';
            $success='Die Tabelle „'.$tableName.'“ wurde gelöscht.';
        } elseif ($tableAction === 'add_managed_column') {
            $tableName=trim((string)($_POST['table'] ?? ''));
            $columnName=trim((string)($_POST['column_name'] ?? ''));

            $normalizedColumnOptions=TableWorkspaceManager::normalizeColumnRequest(
                $projectPdo,
                $tableName,
                null,
                (string)($_POST['column_type'] ?? ''),
                isset($_POST['column_nullable']),
                (string)($_POST['column_default_kind'] ?? 'none'),
                isset($_POST['column_default_value']) ? (string)$_POST['column_default_value'] : null,
                (array)($_POST['column_extra'] ?? []),
                (string)($_POST['column_index_kind'] ?? 'none')
            );

            TableWorkspaceManager::addManagedColumn(
                $projectPdo,
                $tableName,
                $columnName,
                (string)$normalizedColumnOptions['type'],
                (bool)$normalizedColumnOptions['nullable'],
                (string)$normalizedColumnOptions['default_kind'],
                isset($normalizedColumnOptions['default_value'])
                    ? (string)$normalizedColumnOptions['default_value']
                    : null,
                (array)$normalizedColumnOptions['extras'],
                (string)$normalizedColumnOptions['index_kind']
            );
            $selectedTableName=$tableName;
            $selectedColumnName=$columnName;
            $success='Das Feld „'.$columnName.'“ wurde angelegt.';
            if (!empty($normalizedColumnOptions['messages'])) {
                $info=implode(' ',(array)$normalizedColumnOptions['messages']);
            }
        } elseif ($tableAction === 'update_managed_column') {
            $tableName=trim((string)($_POST['table'] ?? ''));
            $originalColumn=trim((string)($_POST['original_column'] ?? ''));
            $columnName=trim((string)($_POST['column_name'] ?? ''));

            $normalizedColumnOptions=TableWorkspaceManager::normalizeColumnRequest(
                $projectPdo,
                $tableName,
                $originalColumn,
                (string)($_POST['column_type'] ?? ''),
                isset($_POST['column_nullable']),
                (string)($_POST['column_default_kind'] ?? 'none'),
                isset($_POST['column_default_value']) ? (string)$_POST['column_default_value'] : null,
                (array)($_POST['column_extra'] ?? []),
                (string)($_POST['column_index_kind'] ?? 'none')
            );

            TableWorkspaceManager::updateManagedColumn(
                $projectPdo,
                $tableName,
                $originalColumn,
                $columnName,
                (string)$normalizedColumnOptions['type'],
                (bool)$normalizedColumnOptions['nullable'],
                (string)$normalizedColumnOptions['default_kind'],
                isset($normalizedColumnOptions['default_value'])
                    ? (string)$normalizedColumnOptions['default_value']
                    : null,
                (array)$normalizedColumnOptions['extras'],
                (string)$normalizedColumnOptions['index_kind']
            );

            // HF41: Änderungen am realen SQL-Datentyp sofort in das
            // persistierend gebundene DataForm übernehmen.
            $boundAfterColumnUpdate=DataFormManager::tableBinding(
                $projectPdo,
                $tableName,
                'system',
                null
            );
            if ($boundAfterColumnUpdate!==null) {
                DataFormManager::synchronizeBoundTableFields(
                    $projectPdo,
                    (int)$boundAfterColumnUpdate['dataform_id']
                );
            }
            $selectedTableName=$tableName;
            $selectedColumnName=$columnName;
            $success='Das Feld „'.$originalColumn.'“ wurde aktualisiert.';
            if (!empty($normalizedColumnOptions['messages'])) {
                $info=implode(' ',(array)$normalizedColumnOptions['messages']);
            }
        } elseif ($tableAction === 'move_managed_column') {
            $tableName=trim((string)($_POST['table'] ?? ''));
            $columnName=trim((string)($_POST['column'] ?? ''));
            $direction=(string)($_POST['direction'] ?? '');

            TableWorkspaceManager::moveManagedColumn(
                $projectPdo,$tableName,$columnName,$direction
            );
            $syncedFields=DataFormManager::syncFieldPositionsFromTable(
                $projectPdo,$tableName
            );

            $selectedTableName=$tableName;
            $selectedColumnName=$columnName;
            $success='Das Feld „'.$columnName.'“ wurde '
                .($direction==='up'?'nach oben':'nach unten').' verschoben.';
            if ($syncedFields>0) {
                $info='Die Feldreihenfolge des zugehörigen DataForms wurde automatisch mit der neuen Tabellenspalten-Reihenfolge synchronisiert.';
            }
        } elseif ($tableAction === 'drop_managed_column') {
            $tableName=trim((string)($_POST['table'] ?? ''));
            $columnName=trim((string)($_POST['column'] ?? ''));
            DerivedMultiEnumManager::assertSourceColumnNotReferenced(
                $projectPdo,
                $tableName,
                $columnName
            );
            TableWorkspaceManager::dropManagedColumn(
                $projectPdo,$tableName,$columnName
            );
            $selectedTableName=$tableName;
            $selectedColumnName='';
            $success='Das Feld „'.$columnName.'“ wurde gelöscht.';
        }
    }

    if ($sectionKey === 'tables') {
        try {
            $tableCatalog=TableWorkspaceManager::catalog(
                $projectPdo,
                $tableSourceRow,
                $sourceKeyMaterial
            );

            if ($selectedTableName !== '') {
                $tableInspection=TableWorkspaceManager::inspect(
                    $projectPdo,
                    $tableSourceRow,
                    $sourceKeyMaterial,
                    $selectedTableName,
                    20
                );
                if ($tableSourceKey === 'system' && !empty($tableInspection['field_crud'])) {
                    $tableDataformBinding=DataFormManager::tableBinding(
                        $projectPdo,
                        $selectedTableName,
                        'system',
                        null
                    );
                } elseif (
                    $tableSourceRow!==null
                    && (string)$tableSourceRow['driver']==='csv'
                    && !empty($tableInspection['external_writable'])
                ) {
                    $tableDataformBinding=DataFormManager::tableBinding(
                        $projectPdo,
                        $selectedTableName,
                        'external',
                        (int)$tableSourceRow['id']
                    );
                }
                if ($selectedColumnName !== '') {
                    foreach ((array)$tableInspection['columns'] as $columnCandidate) {
                        if ((string)$columnCandidate['name'] === $selectedColumnName) {
                            $selectedColumn=$columnCandidate;
                            break;
                        }
                    }
                    if ($selectedColumn === null && $tableSourceKey === 'system') {
                        $tableError='Das ausgewählte Feld wurde nicht gefunden.';
                    }
                }
            }
        } catch (Throwable $tableThrowable) {
            $tableError=$tableThrowable->getMessage();
            $tableCatalog=[];
            $tableInspection=null;
        }
    }

    if($selectedSourceId>0){
        $selectedSource=DataSourceManager::find($projectPdo,$selectedSourceId);
        if(!$selectedSource){
            $selectedSourceId=0;
            if($sectionKey==='sources') $error='Die ausgewählte Datenquelle wurde nicht gefunden.';
        }
    }

    DataFormManager::ensureRuntimeSettingsSchema($projectPdo);
    $licenseRegistrySync=$isCalendarDataAdmin
        ? ['registered'=>0,'pending'=>0,'approved'=>0,'errors'=>[],'embedded_product'=>'calendar']
        : \EasyIT\Enterprise\Licensing\EnterpriseDataFormRegistryBridge::synchronizeProject(dirname(__DIR__, 2),$projectPdo,$projectId);
    if(!empty($licenseRegistrySync['errors']) && $info==='') $info='DataForm-Lizenzregistrierung: '.implode(' | ',array_slice((array)$licenseRegistrySync['errors'],0,3));
    $stats['dataforms'] = (int)$projectPdo->query('SELECT COUNT(*) FROM dataforms')->fetchColumn();
    $stats['fields'] = (int)$projectPdo->query('SELECT COUNT(*) FROM dataform_fields')->fetchColumn();
    $stats['tables'] = (int)$projectPdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $dataforms = $projectPdo->query('SELECT id, name, slug, description, status, df_uuid, license_status, license_registered_at, license_token_key_id, license_token_issued_at, table_save_mode, show_save_success, view_mode, default_per_page, show_search, show_filter, show_pagination, allow_create, allow_edit, allow_delete, dialog_size, css_class, additional_css, event_handlers_json, created_at, updated_at FROM dataforms ORDER BY name')->fetchAll();
    $dataforms = enterprise_filter_dataforms_for_scope($user,$projectId,$dataforms);
    $stats['dataforms'] = count($dataforms);
    $visibleDataformIds=array_fill_keys(array_map(fn($df)=>(int)$df['id'],$dataforms),true);
    DataFormManager::ensureTableBindingSchema($projectPdo);

    $bindingRows=$projectPdo->query(
        "SELECT b.*,d.name AS dataform_name,
                CASE WHEN b.source_kind='system' THEN 'Projekt-Datenbank' ELSE COALESCE(s.name,'Externe Quelle') END AS source_name,
                CASE WHEN b.source_kind='system' THEN '' ELSE COALESCE(s.driver,'') END AS source_driver
         FROM dataform_table_bindings b
         JOIN dataforms d ON d.id=b.dataform_id
         LEFT JOIN data_sources s
           ON b.source_kind='external'
          AND b.source_id=s.id
         ORDER BY b.source_kind,b.source_id,b.table_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $bindingRows=array_values(array_filter($bindingRows,fn($row)=>isset($visibleDataformIds[(int)($row['dataform_id']??0)])));
    foreach($bindingRows as $bindingRow){
        if ((string)($bindingRow['source_kind']??'') === 'system') {
            $bindingRow['source_driver']=$projectDriver ?? 'mysql';
            $bindingRow['source_name']='Projekt-'.strtoupper((string)($projectDriver ?? 'mysql')).'-Datenspeicher';
        }
        $dataformBindingsById[(int)$bindingRow['dataform_id']]=$bindingRow;
    }

    $unboundManagedTables=$projectPdo->query(
        "SELECT m.table_name
         FROM dataform_managed_tables m
         LEFT JOIN dataform_table_bindings b
           ON b.source_kind='system'
          AND b.source_id=0
          AND b.table_name=m.table_name
         WHERE b.id IS NULL
         ORDER BY m.table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $derivedSourceCatalog = DerivedMultiEnumManager::catalog($projectPdo);
    if ($selectedDataformId > 0) {
        // HF41: Ein tabellengebundenes DataForm liest beim Öffnen erneut die
        // reale Spaltenstruktur. Damit werden auch außerhalb des Designers
        // vorgenommene Typänderungen (z. B. INT -> VARCHAR) sichtbar.
        try {
            DataFormManager::synchronizeBoundTableFields(
                $projectPdo,
                $selectedDataformId
            );
        } catch (Throwable $syncThrowable) {
            if ($info==='') {
                $info='Tabellensynchronisation: '.$syncThrowable->getMessage();
            }
        }

        $formStmt = $projectPdo->prepare('SELECT id, name, slug, description, status, table_save_mode, show_save_success, view_mode, default_per_page, show_search, show_filter, show_pagination, allow_create, allow_edit, allow_delete, dialog_size, css_class, additional_css, event_handlers_json, created_at, updated_at FROM dataforms WHERE id = ? LIMIT 1');
        $formStmt->execute([$selectedDataformId]);
        $selectedDataform = $formStmt->fetch();
        if (!$selectedDataform) { throw new RuntimeException('Das ausgewählte DataForm wurde nicht gefunden.'); }
        $recordsetKey=(string)($_GET['recordset_key']??DataFormRecordSetEventRepository::DEFAULT_RECORDSET_KEY);
        $recordEventHandlers=DataFormRecordSetEventRepository::load($projectPdo,(int)$selectedDataform['id'],$recordsetKey);
        $fieldStmt = $projectPdo->prepare('SELECT id, name, label, field_type, position, is_required, configuration_json, created_at FROM dataform_fields WHERE dataform_id = ? ORDER BY position, id');
        $fieldStmt->execute([$selectedDataformId]);
        $fields = $fieldStmt->fetchAll();

        $relationTableExists = (int)$projectPdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name='dataform_relations'"
        )->fetchColumn() === 1;

        if ($relationTableExists) {
            $parentStmt = $projectPdo->prepare(
                "SELECT r.id,r.name,r.source_dataform_id,r.target_dataform_id,
                        r.lookup_field_id,p.name AS parent_name
                 FROM dataform_relations r
                 JOIN dataforms p ON p.id=r.source_dataform_id
                 WHERE r.target_dataform_id=?
                   AND r.relation_type='1:n'
                   AND r.is_enabled=1
                   AND r.lookup_field_id IS NOT NULL
                 ORDER BY r.name"
            );
            $parentStmt->execute([$selectedDataformId]);
            $parentRelations = $parentStmt->fetchAll();

            $parentFieldStmt = $projectPdo->prepare(
                "SELECT id,name,label,field_type
                 FROM dataform_fields
                 WHERE dataform_id=?
                 ORDER BY position,id"
            );
            foreach ($parentRelations as $parentRelation) {
                $parentFieldStmt->execute([
                    (int)$parentRelation['source_dataform_id']
                ]);
                $parentFieldsByRelation[(int)$parentRelation['id']] =
                    $parentFieldStmt->fetchAll();
            }
        }

        if ($sectionKey === 'dataforms') { $sectionKey = 'dataform'; }
        if (in_array($sectionKey, ['dataform','designer'], true) && $selectedFieldId > 0) {
            foreach ($fields as $candidate) { if ((int)$candidate['id'] === $selectedFieldId) { $selectedField = $candidate; break; } }
        }

        // HF147: Lookup-Katalog für den Feld-Designer. Die Auswahl erfolgt
        // bewusst DataForm-basiert, weil RelationManager und Runtime damit
        // dieselbe Quelle und dieselben Datensatz-IDs verwenden.
        $lookupDesignerDataforms=$projectPdo->query(
            'SELECT id,name,slug FROM dataforms ORDER BY name,id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $lookupFieldsStmt=$projectPdo->prepare(
            'SELECT id,name,label,field_type FROM dataform_fields WHERE dataform_id=? ORDER BY position,id'
        );
        foreach ($lookupDesignerDataforms as $lookupDf) {
            $lookupFieldsStmt->execute([(int)$lookupDf['id']]);
            $lookupDesignerFieldsByForm[(int)$lookupDf['id']]=$lookupFieldsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($selectedField && (string)($selectedField['field_type']??'') === 'lookup') {
            $lookupRelationStmt=$projectPdo->prepare(
                "SELECT id,name,target_dataform_id,target_display_field_id,configuration_json
                 FROM dataform_relations
                 WHERE relation_type='n:1'
                   AND source_dataform_id=?
                   AND source_field_id=?
                   AND is_enabled=1
                 ORDER BY id
                 LIMIT 1"
            );
            $lookupRelationStmt->execute([$selectedDataformId,(int)$selectedField['id']]);
            $selectedLookupRelation=$lookupRelationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selectedLookupRelation) {
                $lookupCfg=json_decode((string)($selectedLookupRelation['configuration_json']??''),true);
                $selectedLookupRelation['_target_key_field_id']=is_array($lookupCfg)
                    ? (int)($lookupCfg['target_key_field_id']??0) : 0;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$section = WorkspaceController::section($sectionKey);
$explorerItems = WorkspaceController::explorerItems();
if(!$projectWideScope){$projectWideExplorer=['sources','tables','modules','sql','packages','applications','settings'];$explorerItems=array_values(array_filter($explorerItems,static fn($item)=>!in_array((string)($item['key']??''),$projectWideExplorer,true)));}

ob_start();
try {
?>
<div class="workspace-breadcrumbs">
<?php render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../../app/dashboard.php'],
    ['label' => 'Projekte', 'href' => '../../app/projects/index.php'],
    ['label' => (string)($project['name'] ?? 'DataForm'), 'href' => $project ? '../../app/projects/view.php?id=' . (int)$project['id'] : ''],
    ['label' => $sectionKey === 'welcome' ? 'Workspace' : $section['title'], 'href' => ''],
]); ?>
</div>

<?php if ($error && !$project): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
    <p><a class="button secondary" href="../../app/projects/index.php">Zur Projektverwaltung</a></p>
<?php else: ?>
<div class="df-workspace" data-workspace>
    <header class="df-workspace-header">
        <div>
            <span class="badge">DataForm Workspace</span><?php /* Compatibility marker: DataForm Workspace · HF69; DataForm Workspace · HF67 */ ?><?php /* Compatibility marker: DataForm Workspace · HF66 */ ?><?php /* Compatibility marker: DataForm Workspace · HF65 */ ?>
            <h1><?= e((string)$project['name']) ?></h1>
        </div>
        <div class="df-workspace-actions">
            <a class="button secondary" href="../../app/projects/view.php?id=<?= (int)$project['id'] ?>">Projektübersicht</a>
            <a class="button" <?= easyit_button_attributes('auswaehlen','project_switch') ?> href="../../app/projects/index.php">Projekt wechseln</a>
        </div>
    </header>

    <?php if($projectWideScope): ?>
    <section class="df-primary-actions" aria-label="Schnellzugriff Tabellen und Formulare">
        <a class="df-primary-action" href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system#new-table">
            <span class="df-primary-action-number">1</span>
            <span><strong>Tabelle anlegen</strong><small>Projekt-Datenbank strukturieren und Felder definieren.</small></span>
        </a>
        <a class="df-primary-action" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms#new-dataform">
            <span class="df-primary-action-number">2</span>
            <span><strong>Formular anlegen</strong><small>Formular (DataForm) neu erstellen oder aus einer Tabelle ableiten.</small></span>
        </a>
    </section>
    <?php endif; ?>

    <nav class="df-menu" aria-label="Workspace-Menü" data-df-menu>
        <details class="df-menu-item">
            <summary>Datei</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Datei">
                <?php if($projectWideScope): ?><a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms#new-dataform">Neues Formular …</a><?php endif; ?>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms">Formulare öffnen</a>
                <?php if ($selectedDataformId > 0): ?>
                <a role="menuitem" href="import.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>">CSV in aktuelles DataForm importieren …</a>
                <?php endif; ?>
                <div class="df-menu-separator" role="separator"></div>
                <?php if($licenseCanExport&&$projectWideScope): ?><form method="post" action="../../app/projects/export.php" class="df-menu-form">
                    <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$project['id'] ?>">
                    <button role="menuitem" type="submit">Anwenderpaket herunterladen …</button>
                </form><?php endif; ?>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Bearbeiten</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Bearbeiten">
                <?php if ($selectedDataformId > 0): ?>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$selectedDataformId ?>#dataform-settings">DataForm-Einstellungen …</a>
                <?php if($licenseDesignerAccess): ?><a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataformId ?>">Formular-Designer …</a>
                <a role="menuitem" href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>&amp;mode=layout">Layout …</a>
                <a role="menuitem" href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>&amp;mode=behavior">Verhalten …</a><?php endif; ?>
                <a role="menuitem" href="workflow.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>">Workflow …</a>
                <?php if($licenseCanRelations): ?><a role="menuitem" href="relations.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>">Beziehungen …</a><?php endif; ?>
                <?php else: ?>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms">Formular zum Bearbeiten auswählen …</a>
                <?php endif; ?>
                <?php if($projectWideScope): ?><div class="df-menu-separator" role="separator"></div>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=sources">Datenquellen …</a>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=tables">Tabellen …</a><?php endif; ?>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Ansicht</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Ansicht">
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=welcome">Workspace-Übersicht</a>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms">Formular-Liste</a>
                <?php if($projectWideScope): ?><a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=sources">Datenquellen</a>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=tables">Tabellen</a><?php endif; ?>
                <?php if ($selectedDataformId > 0): ?>
                <div class="df-menu-separator" role="separator"></div>
                <?php if($licenseCanRead): ?><a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$selectedDataformId ?>#real-preview">Realvorschau</a>
                <a role="menuitem" href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>">Datensätze</a><?php endif; ?>
                <?php endif; ?>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Projekt</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Projekt">
                <a role="menuitem" href="../../app/projects/view.php?id=<?= (int)$project['id'] ?>">Projektübersicht</a>
                <?php if($projectWideScope): ?><a role="menuitem" href="../../app/projects/edit.php?id=<?= (int)$project['id'] ?>">Projekt bearbeiten …</a>
                <?php if($licenseCanExport): ?><a role="menuitem" href="applications.php?project=<?= (int)$project['id'] ?>">Application Builder …</a><?php endif; ?>
                <a role="menuitem" href="packages.php?project=<?= (int)$project['id'] ?>">Projektpakete …</a><?php endif; ?>
                <div class="df-menu-separator" role="separator"></div>
                <a role="menuitem" href="../../app/projects/index.php">Projekt wechseln …</a>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Werkzeuge</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Werkzeuge">
                <?php if($licenseCanSearch||$licenseCanFilter): ?><a role="menuitem" href="queries.php?project=<?= (int)$project['id'] ?>">Visual Query Builder …</a><?php endif; ?>
                <a role="menuitem" href="reports.php?project=<?= (int)$project['id'] ?>">Report Designer …</a>
                <?php if($licenseCanApi): ?><a role="menuitem" href="api.php?project=<?= (int)$project['id'] ?>">REST API Designer …</a><?php endif; ?>
                <a role="menuitem" href="modules.php?project=<?= (int)$project['id'] ?>">Module …</a>
                <?php if ($selectedDataformId > 0): ?>
                <div class="df-menu-separator" role="separator"></div>
                <a role="menuitem" href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>&amp;mode=versions">DataForm-Versionen …</a>
                <?php endif; ?>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Fenster</summary>
            <div class="df-menu-dropdown" role="menu" aria-label="Fenster">
                <a role="menuitem" href="<?= e((string)($_SERVER['REQUEST_URI'] ?? ('runtime.php?project='.(int)$project['id']))) ?>" target="_blank" rel="noopener">Aktuelle Ansicht in neuem Fenster</a>
                <?php if ($selectedDataformId > 0): ?>
                <?php if($licenseCanRead): ?><a role="menuitem" href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>" target="_blank" rel="noopener">DataForm-Runtime in neuem Fenster</a>
                <a role="menuitem" href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataformId ?>&amp;embed=1&amp;preview=1" target="_blank" rel="noopener">Realvorschau separat öffnen</a><?php endif; ?>
                <?php endif; ?>
                <div class="df-menu-separator" role="separator"></div>
                <a role="menuitem" href="<?= e((string)($_SERVER['REQUEST_URI'] ?? ('runtime.php?project='.(int)$project['id']))) ?>">Aktuelle Ansicht neu laden</a>
            </div>
        </details>

        <details class="df-menu-item">
            <summary>Hilfe</summary>
            <div class="df-menu-dropdown df-menu-dropdown-right" role="menu" aria-label="Hilfe">
                <a role="menuitem" href="#df-context-help">Kontexthilfe</a>
                <?php if ($selectedDataformId > 0): ?>
                <a role="menuitem" href="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$selectedDataformId ?>#dataform-settings">DataForm-Konfigurationskarte</a>
                <?php endif; ?>
                <a role="menuitem" href="../../documentation.php">Vollständige Dokumentation</a>
            </div>
        </details>
    </nav>

    <div class="df-workspace-grid">
        <aside class="df-explorer" aria-label="Projekt-Explorer">
            <div class="df-pane-title">Explorer</div>
            <div class="df-project-name"><?= e((string)$project['name']) ?></div>
            <nav>
                <?php foreach ($explorerItems as $item): ?>
                    <?php
                    $explorerHref = isset($item['href'])
                        ? $item['href'] . '?project=' . (int)$project['id']
                        : '?project=' . (int)$project['id'] . '&section=' . $item['key'];
                    if (($item['key'] ?? '') === 'workflow' && $selectedDataformId > 0) {
                        $explorerHref .= '&dataform=' . (int)$selectedDataformId;
                    }
                    ?>
                    <a class="<?= $sectionKey === $item['key'] ? 'active' : '' ?>" href="<?= e($explorerHref) ?>">
                        <span aria-hidden="true"><?= e($item['icon']) ?></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <main class="df-editor" aria-label="Arbeitsbereich">
            <div class="df-editor-tab"><span><?= e($selectedDataform ? (string)$selectedDataform['name'] : $section['title']) ?></span></div>
            <div class="df-editor-content">
                <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
                <?php if ($info): ?><div class="notice info" role="status"><?= e($info) ?></div><?php endif; ?>

                <?php if ($sectionKey === 'welcome'): ?>
                    <h2><?= e($section['title']) ?></h2>
                    <p><?= e($section['text']) ?></p>
                    <div class="metric-grid df-metrics">
                        <div class="metric"><strong><?= (int)$stats['dataforms'] ?></strong><span>DataForms</span></div>
                        <div class="metric"><strong><?= (int)$stats['fields'] ?></strong><span>Felder</span></div>
                        <div class="metric"><strong><?= (int)$stats['tables'] ?></strong><span>Tabellen</span></div>
                    </div>
                    <section class="card">
                        <h3>Projektstatus</h3>
                        <dl class="status-list">
                            <div><dt>Projekt</dt><dd><?= e((string)$project['name']) ?></dd></div>
                            <div><dt>Interner Projektname</dt><dd><code><?= e((string)$project['slug']) ?></code></dd></div>
                            <div><dt>Datenbank</dt><dd><code><?= e((string)$project['database_name']) ?></code></dd></div>
                            <div><dt>Verbindung</dt><dd><strong class="<?= $dbConnected ? 'ok' : 'bad' ?>"><?= $dbConnected ? 'verbunden' : 'nicht verbunden' ?></strong></dd></div>
                        </dl>
                    </section>
                    <section class="card">
                        <h3>So bauen Sie das Projekt auf</h3>
                        <ol>
                            <li><a href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system#new-table">Tabelle anlegen</a> und die benötigten Spalten definieren.</li>
                            <li><a href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms#table-dataforms">Formular aus der Tabelle erzeugen</a> oder <a href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms#new-dataform">ein leeres Formular anlegen</a>.</li>
                            <li>Felder im Formular-Designer konfigurieren und anschließend die Datensätze testen.</li>
                        </ol>
                    </section>
                <?php elseif ($sectionKey === 'sources'): $sourceCfg=$selectedSource?DataSourceManager::config($selectedSource):[]; $sourceDriver=(string)($selectedSource['driver']??'mysql'); $sourceDriverOptions=DataSourceManager::drivers(); $sourceDriverLabels=DataSourceManager::drivers(false); $systemDriver=enterprise_project_store_driver($env,$project); $systemDriverLabel=['mysql'=>'MySQL / MariaDB','pgsql'=>'PostgreSQL','sqlite'=>'SQLite','csv'=>'CSV'][$systemDriver]??strtoupper($systemDriver); ?>
                    <div class="df-toolbar">
                        <div><h2>Datenquellen</h2><p>Externe und dateibasierte Datenquellen projektbezogen konfigurieren, sicher speichern und direkt testen.</p></div>
                        <a class="button" href="?project=<?= (int)$project['id'] ?>&amp;section=sources#source-form">+ Neue Datenquelle</a>
                    </div>

                    <section class="card df-system-source">
                        <div class="df-source-heading"><div><span class="badge">Systemquelle</span><h3>Projekt-Datenbank</h3><p>Die aktuelle Projektdatenbank bleibt die interne Standardquelle des DataForm-Projekts.</p></div><strong class="ok">verbunden</strong></div>
                        <dl class="status-list">
                            <div><dt>Treiber</dt><dd><?= e($systemDriverLabel) ?></dd></div>
                            <div><dt>Speicher</dt><dd><code><?= e((string)$project['database_name']) ?></code></dd></div>
                            <?php if(in_array($systemDriver,['mysql','pgsql'],true)): ?><div><dt>Host</dt><dd><code><?= e((string)($env['PROJECT_DB_HOST']??'127.0.0.1')) ?>:<?= (int)($env['PROJECT_DB_PORT']??($systemDriver==='pgsql'?5432:3306)) ?></code></dd></div><?php endif; ?>
                            <?php if($systemDriver==='pgsql'): ?><div><dt>Schema</dt><dd><code><?= e((string)($env['PROJECT_DB_SCHEMA']??'public')) ?></code></dd></div><?php endif; ?>
                        </dl>
                    </section>

                    <div class="df-source-layout">
                        <section>
                            <h3>Projektquellen</h3>
                            <?php if($dataSources): ?>
                            <div class="df-source-list">
                                <?php foreach($dataSources as $source): $cfg=DataSourceManager::config($source); $status=(string)($source['last_test_status']??'unknown'); ?>
                                <article class="df-source-card <?= !$source['is_enabled']?'disabled':'' ?>">
                                    <div class="df-source-heading">
                                        <div><h4><?= e((string)$source['name']) ?></h4><p><?= e(DataSourceManager::drivers(false)[(string)$source['driver']]??(string)$source['driver']) ?><?= !$source['is_enabled']?' · deaktiviert':'' ?></p></div>
                                        <span class="df-source-status <?= e($status) ?>"><?= $status==='pass'?'PASS':($status==='fail'?'FAIL':'NICHT GETESTET') ?></span>
                                    </div>
                                    <p class="df-source-summary">
                                    <?php if($source['driver']==='pgsql'): ?><?= e((string)($cfg['host']??'')) ?>:<?= (int)($cfg['port']??5432) ?> / <code><?= e((string)($cfg['database']??'')) ?></code> / Schema <code><?= e((string)($cfg['schema']??'public')) ?></code>
                                    <?php elseif($source['driver']==='mysql'): ?><?= e((string)($cfg['host']??'')) ?>:<?= (int)($cfg['port']??3306) ?> / <code><?= e((string)($cfg['database']??'')) ?></code>
                                    <?php elseif($source['driver']==='oracle'): ?><?= e((string)($cfg['host']??'')) ?>:<?= (int)($cfg['port']??1521) ?> / <?= e((string)($cfg['service_name']??'')) ?>
                                    <?php elseif($source['driver']==='sqlite'): ?><code><?= e((string)($cfg['path']??'')) ?></code>
                                    <?php elseif($source['driver']==='csv'): ?><code><?= e((string)($cfg['base_path']??'')) ?>/<?= e((string)($cfg['database']??'')) ?></code>
                                    <?php endif; ?>
                                    </p>
                                    <?php if(!empty($source['last_test_message'])): ?><p class="df-form-meta"><?= e((string)$source['last_test_message']) ?><?php if(!empty($source['last_test_at'])): ?> · <?= e((string)$source['last_test_at']) ?><?php endif; ?></p><?php endif; ?>
                                    <div class="actions">
                                        <a class="button secondary" href="?project=<?= (int)$project['id'] ?>&amp;section=sources&amp;source=<?= (int)$source['id'] ?>#source-form">Bearbeiten</a>
                                        <form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= (int)$project['id'] ?>"><input type="hidden" name="section" value="sources"><input type="hidden" name="action" value="test_source"><input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>"><button class="button secondary" type="submit">Verbindung testen</button></form>
                                        <form method="post" onsubmit="return confirm('Datenquelle wirklich löschen?');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= (int)$project['id'] ?>"><input type="hidden" name="section" value="sources"><input type="hidden" name="action" value="delete_source"><input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>"><button class="link-danger" type="submit">Löschen</button></form>
                                    </div>
                                </article>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?><div class="empty-state"><h3>Noch keine zusätzliche Datenquelle</h3><p>Die Projekt-Datenbank ist bereits aktiv. Ergänzen Sie hier MySQL/MariaDB, PostgreSQL, SQLite, CSV oder Oracle.</p></div><?php endif; ?>
                        </section>

                        <section class="card" id="source-form">
                            <h3><?= $selectedSource?'Datenquelle bearbeiten':'Neue Datenquelle' ?></h3>
                            <?php if($sourceKeyProvisioned): ?>
                            <div class="notice success">
                                <strong>Secret-Schlüssel automatisch eingerichtet.</strong>
                                <p>Ein dedizierter <code>DATAFORM_APP_KEY</code> wurde erzeugt und in <code>DataForm5-Core/.env</code> gespeichert. Kennwörter können jetzt verschlüsselt gespeichert werden.</p>
                            </div>
                            <?php elseif(!$sourceKeyAvailable): ?>
                            <div class="notice error">
                                <strong>Secret-Schlüssel konnte nicht automatisch eingerichtet werden.</strong>
                                <p><?= e($sourceKeyProvisionError !== '' ? $sourceKeyProvisionError : 'Unbekannter Fehler.') ?></p>
                                <p>Prüfen Sie die Schreibrechte von <code>DataForm5-Core/.env</code>.</p>
                            </div>
                            <?php endif; ?>
                            <form method="post" data-source-form>
                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                <input type="hidden" name="section" value="sources">
                                <input type="hidden" name="action" value="save_source">
                                <input type="hidden" name="source_id" value="<?= (int)($selectedSource['id']??0) ?>">
                                <label><strong>Name</strong><input name="source_name" required maxlength="160" value="<?= e((string)($selectedSource['name']??'')) ?>" placeholder="z. B. ERP-Datenbank"></label>
                                <label><strong>Typ</strong><select name="driver" data-source-driver><?php if($selectedSource && !isset($sourceDriverOptions[$sourceDriver])): ?><option value="<?= e($sourceDriver) ?>" selected disabled><?= e((string)($sourceDriverLabels[$sourceDriver]??strtoupper($sourceDriver))) ?> · PDO-Treiber fehlt</option><?php endif; ?><?php foreach($sourceDriverOptions as $driver=>$caption): ?><option value="<?= e($driver) ?>" <?= $sourceDriver===$driver?'selected':'' ?>><?= e($caption) ?></option><?php endforeach; ?></select></label>

                                <div data-source-driver-fields="mysql pgsql oracle">
                                    <div class="df-form-grid">
                                        <label><strong>Host</strong><input name="host" value="<?= e((string)($sourceCfg['host']??'127.0.0.1')) ?>"></label>
                                        <label><strong>Port</strong><input type="number" min="1" max="65535" name="port" value="<?= (int)($sourceCfg['port']??($sourceDriver==='oracle'?1521:($sourceDriver==='pgsql'?5432:3306))) ?>"></label>
                                        <label data-driver-only="mysql pgsql"><strong>Datenbank</strong><input name="database" value="<?= e((string)($sourceCfg['database']??'')) ?>"></label>
                                        <label data-driver-only="pgsql"><strong>Schema</strong><input name="schema" value="<?= e((string)($sourceCfg['schema']??'public')) ?>" pattern="[A-Za-z][A-Za-z0-9_]{0,62}"><small>PostgreSQL verwendet die Kombination Datenbank + Schema.</small></label>
                                        <label data-driver-only="mysql pgsql"><strong>Charset</strong><input name="charset" value="<?= e((string)($sourceCfg['charset']??($sourceDriver==='pgsql'?'UTF8':'utf8mb4'))) ?>"></label>
                                        <label data-driver-only="oracle"><strong>Service-Name</strong><input name="service_name" value="<?= e((string)($sourceCfg['service_name']??'XEPDB1')) ?>"></label>
                                        <label data-driver-only="oracle"><strong>Oracle-Charset</strong><input name="oracle_charset" value="<?= e((string)($sourceCfg['charset']??'AL32UTF8')) ?>"></label>
                                        <label data-driver-only="oracle" class="full"><strong>DSN (optional)</strong><input name="dsn" value="<?= e((string)($sourceCfg['dsn']??'')) ?>" placeholder="oci:dbname=//host:1521/service;charset=AL32UTF8"></label>
                                        <label><strong>DB-Benutzer</strong><input name="username" value="<?= e((string)($sourceCfg['username']??'')) ?>"></label>
                                        <label><strong>DB-Kennwort</strong><span class="password-input-wrap"><input name="password" type="password" autocomplete="new-password" data-source-password placeholder="<?= $selectedSource&&DataSourceManager::secretConfigured($selectedSource)?'gespeichert – leer lassen zum Beibehalten':'Kennwort' ?>"><button type="button" class="password-toggle" data-password-toggle aria-label="Kennwort anzeigen oder verbergen">👁</button></span></label>
                                    </div>
                                </div>

                                <div data-source-driver-fields="sqlite"><label><strong>SQLite-Datei</strong><input name="path" value="<?= e((string)($sourceCfg['path']??'')) ?>" placeholder="D:\\daten\\projekt.sqlite"></label></div>
                                <div data-source-driver-fields="csv"><div class="df-form-grid"><label><strong>Basispfad</strong><input name="base_path" value="<?= e((string)($sourceCfg['base_path']??'')) ?>" placeholder="D:\\daten\\csv"></label><label><strong>Datenbankname / Ordner</strong><input name="csv_database" value="<?= e((string)($sourceCfg['database']??'')) ?>" placeholder="kunden"></label></div><p class="df-field-help"><strong>RC1.1 CSV:</strong> vollständig schreibbare DataForm-Datenquelle. Ein Datenbankname entspricht einem Ordner, jede Tabelle einer <code>.csv</code>-Datei. Trennzeichen <code>|</code>; <code>id</code> wird automatisch verwaltet.</p></div>
                                <label class="checkbox-line"><input type="checkbox" name="is_enabled" value="1" <?= !$selectedSource||!empty($selectedSource['is_enabled'])?'checked':'' ?>> <span>Datenquelle aktiviert</span></label>
                                <div class="actions"><button class="button" type="submit">Datenquelle speichern</button><?php if($selectedSource): ?><a class="button secondary" href="?project=<?= (int)$project['id'] ?>&amp;section=sources#source-form">Neue Datenquelle</a><?php endif; ?></div>
                            </form>
                        </section>
                    </div>

                    <script id="hf26-data-source-ui">
                    (function(){
                        var form=document.querySelector('[data-source-form]');
                        if(!form)return;
                        var driver=form.querySelector('[data-source-driver]');
                        function sync(){
                            var value=driver.value;
                            form.querySelectorAll('[data-source-driver-fields]').forEach(function(group){
                                group.hidden=!group.getAttribute('data-source-driver-fields').split(/\\s+/).includes(value);
                            });
                            form.querySelectorAll('[data-driver-only]').forEach(function(field){field.hidden=!field.getAttribute('data-driver-only').split(/\s+/).includes(value);});
                            var port=form.querySelector('input[name="port"]');
                            if(port && !port.dataset.touched) port.value=value==='oracle'?'1521':(value==='pgsql'?'5432':'3306');
                            var charset=form.querySelector('input[name="charset"]'); if(charset && !charset.dataset.touched) charset.value=value==='pgsql'?'UTF8':'utf8mb4';
                            var schema=form.querySelector('input[name="schema"]'); if(schema && value==='pgsql' && !schema.dataset.touched && schema.value.trim()==='') schema.value='public';
                        }
                        var port=form.querySelector('input[name="port"]');if(port)port.addEventListener('input',function(){port.dataset.touched='1';}); var charset=form.querySelector('input[name="charset"]');if(charset)charset.addEventListener('input',function(){charset.dataset.touched='1';}); var schema=form.querySelector('input[name="schema"]');if(schema)schema.addEventListener('input',function(){schema.dataset.touched='1';});
                        driver.addEventListener('change',sync);sync();
                        form.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=button.parentElement.querySelector('input');var visible=input.type==='text';input.type=visible?'password':'text';button.setAttribute('aria-label','Kennwort anzeigen oder verbergen');});});
                    })();
                    </script>
                <?php elseif ($sectionKey === 'tables'): $tableSourceIsCsv=$tableSourceKey!=='system' && $tableSourceRow!==null && (string)($tableSourceRow['driver']??'')==='csv'; ?>
                    <div class="df-toolbar">
                        <div>
                            <h2>Tabellen</h2>
                            <p>Tabellen anlegen, Struktur bearbeiten und vorhandene Tabellen der Projekt-Datenbank oder aktivierter Datenquellen untersuchen.</p>
                        </div>
                        <div class="actions">
                            <?php if($tableSourceKey==='system'): ?><a class="df-visible-action-link" href="#new-table">+ Tabelle anlegen</a><?php endif; ?>
                            <a class="button secondary" <?= easyit_button_attributes('aktualisieren') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=<?= e($tableSourceKey) ?>">↻ Aktualisieren</a>
                        </div>
                    </div>

                    <?php if($tableError): ?>
                    <div class="notice error" role="alert">
                        <strong>Tabellenquelle konnte nicht gelesen werden.</strong>
                        <p><?= e($tableError) ?></p>
                    </div>
                    <?php endif; ?>

                    <section class="card df-table-source-bar">
                        <form method="get" class="df-table-source-form">
                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                            <input type="hidden" name="section" value="tables">
                            <label>
                                <strong>Datenquelle</strong>
                                <select name="table_source" onchange="this.form.submit()">
                                    <?php foreach($tableSources as $tableSource): ?>
                                    <option value="<?= e((string)$tableSource['key']) ?>" <?= $tableSourceKey===(string)$tableSource['key']?'selected':'' ?>>
                                        <?= e((string)$tableSource['name']) ?> · <?= e(DataSourceManager::drivers(false)[(string)$tableSource['driver']]??(string)$tableSource['driver']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <span class="badge"><?= $tableSourceKey==='system'
                                ? 'Systemquelle · Schreibzugriff'
                                : ($tableSourceIsCsv ? 'CSV-Quelle · Schreibzugriff' : 'Externe Quelle · Nur Lesen') ?></span>
                        </form>
                    </section>

                    <div class="df-table-workspace">
                        <section class="card">
                            <div class="df-toolbar">
                                <div>
                                    <h3>Tabellenkatalog</h3>
                                    <p><?= count($tableCatalog) ?> Tabelle<?= count($tableCatalog)===1?'':'n' ?> gefunden.</p>
                                </div>
                            </div>

                            <?php if($tableCatalog): ?>
                            <div class="df-table-catalog">
                                <?php foreach($tableCatalog as $catalogTable): ?>
                                <a
                                    class="df-table-catalog-item <?= $selectedTableName===(string)$catalogTable['name']?'active':'' ?>"
                                    href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=<?= e($tableSourceKey) ?>&amp;table=<?= rawurlencode((string)$catalogTable['name']) ?>"
                                >
                                    <strong><?= e((string)$catalogTable['name']) ?></strong>
                                    <span><?= e((string)$catalogTable['type']) ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif(!$tableError): ?>
                            <div class="empty-state">
                                <h3>Keine Tabellen vorhanden</h3>
                                <p>Die gewählte Datenquelle enthält aktuell keine sichtbaren Tabellen.</p>
                            </div>
                            <?php endif; ?>
                        </section>

                        <section>
                            <?php if($tableInspection && $selectedTableName!==''): ?>
                            <section class="card df-table-detail">
                                <div class="df-toolbar">
                                    <div>
                                        <span class="badge"><?= e((string)$tableInspection['type']) ?></span>
                                        <h3><?= e($selectedTableName) ?></h3>
                                        <p><?= (int)$tableInspection['row_count'] ?> Datensätze · <?= count((array)$tableInspection['columns']) ?> Spalten</p>
                                    </div>
                                    <?php if(($tableSourceKey==='system' && !empty($tableInspection['field_crud'])) || ($tableSourceIsCsv && !empty($tableInspection['external_writable']))): ?>
                                    <div class="actions df-table-actions">
                                        <?php if($tableDataformBinding): ?>
                                        <a class="button" <?= easyit_button_attributes('formular','dataform') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$tableDataformBinding['dataform_id'] ?>">
                                            Zugehöriges DataForm öffnen
                                        </a>
                                        <?php else: ?>
                                        <form method="post" onsubmit="return confirm('Aus der Tabelle <?= e($selectedTableName) ?> jetzt ein DataForm erzeugen?');">
                                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                            <input type="hidden" name="section" value="tables">
                                            <input type="hidden" name="action" value="create_dataform_from_table">
                                            <input type="hidden" name="table_source" value="<?= e($tableSourceKey) ?>">
                                            <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                            <button class="button" <?= easyit_button_attributes('neu','dataform') ?> type="submit">DataForm aus Tabelle erstellen</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if(!empty($tableInspection['managed']) || $tableSourceIsCsv): ?>
                                        <form method="post" onsubmit="return confirm('Die Tabelle <?= e($selectedTableName) ?> und alle enthaltenen Daten wirklich löschen?');">
                                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                            <input type="hidden" name="section" value="tables">
                                            <input type="hidden" name="action" value="<?= $tableSourceIsCsv?'drop_csv_table':'drop_managed_table' ?>">
                                            <input type="hidden" name="table_source" value="<?= e($tableSourceKey) ?>">
                                            <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                            <button class="link-danger" <?= easyit_button_attributes('loeschen') ?> type="submit">Tabelle löschen</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if($tableSourceKey==='system' && empty($tableInspection['field_crud'])): ?>
                                <div class="notice">
                                    Diese interne Systemtabelle ist geschützt. Tabellen- und Feldstruktur können hier nur gelesen werden.
                                </div>
                                <?php elseif($tableSourceKey==='system' && !empty($tableInspection['application']) && empty($tableInspection['managed'])): ?>
                                <div class="notice info">
                                    Anwendungstabelle der Projekt-Datenbank: <strong>Feld-CRUD ist aktiv</strong>. Die Tabelle selbst bleibt gegen versehentliches Löschen geschützt.
                                </div>
                                <?php elseif($tableSourceIsCsv): ?>
                                <div class="notice success">
                                    <strong>CSV-Schreibzugriff aktiv.</strong> Tabelle, Felder und Datensätze werden über die DataForm-CSV-Engine gespeichert. <code>id</code> bleibt als technische Pflichtspalte geschützt; alle Nutzfelder werden als Text gespeichert.
                                </div>
                                <?php endif; ?>

                                <?php if(($tableSourceKey==='system' && !empty($tableInspection['field_crud'])) || $tableSourceIsCsv): ?>
                                <?php if($tableDataformBinding): ?>
                                <div class="notice info">
                                    Zugeordnetes DataForm: <strong><?= e((string)$tableDataformBinding['dataform_name']) ?></strong>.
                                    Die Tabellenherkunft ist persistent gespeichert<?= $tableSourceIsCsv?' und der Runtime-Speicher ist CSV':'' ?>.
                                </div>
                                <?php else: ?>
                                <div class="notice info">
                                    Für diese <?= $tableSourceIsCsv?'CSV-':'Projekt' ?>tabelle existiert noch kein DataForm. Es kann direkt aus der aktuellen Spaltendefinition erzeugt werden.
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>

                                <div class="df-toolbar df-column-toolbar">
                                    <div>
                                        <h4>Felder / Spaltenstruktur</h4>
                                        <?php if($tableSourceIsCsv): ?>
                                        <p class="df-field-help"><code>id</code> bleibt geschützt an erster Stelle. CSV-Nutzfelder werden als Text gespeichert. Hinzufügen, Umbenennen und Löschen schreibt die Kopfzeile und alle Datensätze atomar neu.</p>
                                        <p>Felder dieser CSV-Tabelle können angelegt, gelesen, umbenannt und gelöscht werden.</p>
                                        <?php else: ?>
                                        <p class="df-field-help">Mit <strong>↑</strong> und <strong>↓</strong> können die Spalten der Projekttabelle real verschoben werden. <code>id</code> bleibt geschützt an erster Stelle. Ist ein DataForm an die Tabelle gebunden, wird dessen Feldreihenfolge automatisch mitgeführt.</p>
                                        <?php if($tableSourceKey==='system' && !empty($tableInspection['field_crud'])): ?>
                                        <p>Felder dieser Projekt-/Anwendungstabelle können angelegt, gelesen, bearbeitet und gelöscht werden.</p>
                                        <?php else: ?>
                                        <p>Die Feldstruktur dieser Tabelle wird nur gelesen.</p>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if($tableSourceKey==='system' && !empty($tableInspection['field_crud'])): ?>
                                    <a class="button secondary" <?= easyit_button_attributes('neu') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system&amp;table=<?= rawurlencode($selectedTableName) ?>#field-crud">+ Feld hinzufügen</a>
                                    <?php elseif($tableSourceIsCsv): ?>
                                    <a class="button secondary" <?= easyit_button_attributes('neu') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=<?= e($tableSourceKey) ?>&amp;table=<?= rawurlencode($selectedTableName) ?>#csv-field-crud">+ CSV-Feld hinzufügen</a>
                                    <?php endif; ?>
                                </div>
                                <div class="df-table-scroll">
                                    <table class="df-field-table">
                                        <thead>
                                            <tr>
                                                <th>Feld</th><th>Typ</th><th>NULL</th><th>Schlüssel</th><th>Standard</th><th>Extra</th>
                                                <?php if(($tableSourceKey==='system' && !empty($tableInspection['field_crud'])) || $tableSourceIsCsv): ?><th>Aktionen</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach((array)$tableInspection['columns'] as $column): ?>
                                            <tr class="<?= $selectedColumnName===(string)$column['name']?'selected-row':'' ?>">
                                                <td><code><?= e((string)$column['name']) ?></code></td>
                                                <td><?= e((string)$column['type']) ?></td>
                                                <td><?= e((string)$column['nullable']) ?></td>
                                                <td><?= e((string)$column['key']) ?></td>
                                                <td><?= e((string)$column['default']) ?></td>
                                                <td><?= e((string)$column['extra']) ?></td>
                                                <?php if($tableSourceKey==='system' && !empty($tableInspection['field_crud'])): ?>
                                                <td>
                                                    <?php
                                                    $columnRows=(array)$tableInspection['columns'];
                                                    $columnIndex=0;
                                                    foreach($columnRows as $candidateIndex=>$positionColumn){
                                                        if((string)$positionColumn['name']===(string)$column['name']){
                                                            $columnIndex=(int)$candidateIndex;
                                                            break;
                                                        }
                                                    }
                                                    $isIdColumn=(string)$column['name']==='id';
                                                    $canMoveUp=!$isIdColumn && $columnIndex>1;
                                                    $canMoveDown=!$isIdColumn && $columnIndex<count($columnRows)-1;
                                                    ?>
                                                    <div class="df-column-actions">
                                                        <?php if(!$isIdColumn): ?>
                                                        <div class="df-column-order-actions" aria-label="Spaltenreihenfolge">
                                                            <form method="post">
                                                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                                                <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                                                <input type="hidden" name="section" value="tables">
                                                                <input type="hidden" name="action" value="move_managed_column">
                                                                <input type="hidden" name="table_source" value="system">
                                                                <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                                                <input type="hidden" name="column" value="<?= e((string)$column['name']) ?>">
                                                                <input type="hidden" name="direction" value="up">
                                                                <button class="button secondary compact df-order-button" type="submit" <?= $canMoveUp?'':'disabled' ?> title="Eine Position nach oben">↑</button>
                                                            </form>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                                                <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                                                <input type="hidden" name="section" value="tables">
                                                                <input type="hidden" name="action" value="move_managed_column">
                                                                <input type="hidden" name="table_source" value="system">
                                                                <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                                                <input type="hidden" name="column" value="<?= e((string)$column['name']) ?>">
                                                                <input type="hidden" name="direction" value="down">
                                                                <button class="button secondary compact df-order-button" type="submit" <?= $canMoveDown?'':'disabled' ?> title="Eine Position nach unten">↓</button>
                                                            </form>
                                                        </div>
                                                        <?php endif; ?>

                                                        <?php if(!empty($column['mutable'])): ?>
                                                        <a class="button secondary compact" href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system&amp;table=<?= rawurlencode($selectedTableName) ?>&amp;column=<?= rawurlencode((string)$column['name']) ?>#field-crud">Bearbeiten</a>
                                                        <form method="post" onsubmit="return confirm('Das Feld <?= e((string)$column['name']) ?> und alle darin gespeicherten Werte wirklich löschen?');">
                                                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                                            <input type="hidden" name="section" value="tables">
                                                            <input type="hidden" name="action" value="drop_managed_column">
                                                            <input type="hidden" name="table_source" value="system">
                                                            <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                                            <input type="hidden" name="column" value="<?= e((string)$column['name']) ?>">
                                                            <button class="link-danger" type="submit">Löschen</button>
                                                        </form>
                                                        <?php else: ?>
                                                        <span class="df-protected-field" title="<?= e((string)($column['protection_reason']??'geschützt')) ?>">geschützt</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                                <?php if($tableSourceIsCsv): ?>
                                                <td>
                                                    <?php if((string)$column['name']==='id'): ?>
                                                    <span class="df-protected-field" title="DataForm-Pflichtfeld">geschützt</span>
                                                    <?php else: ?>
                                                    <div class="df-column-actions">
                                                        <a class="button secondary compact" <?= easyit_button_attributes('bearbeiten') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=<?= e($tableSourceKey) ?>&amp;table=<?= rawurlencode($selectedTableName) ?>&amp;column=<?= rawurlencode((string)$column['name']) ?>#csv-field-crud">Umbenennen</a>
                                                        <form method="post" onsubmit="return confirm('Das CSV-Feld <?= e((string)$column['name']) ?> und alle darin gespeicherten Werte wirklich löschen?');">
                                                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                                            <input type="hidden" name="section" value="tables">
                                                            <input type="hidden" name="action" value="drop_csv_column">
                                                            <input type="hidden" name="table_source" value="<?= e($tableSourceKey) ?>">
                                                            <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                                            <input type="hidden" name="column" value="<?= e((string)$column['name']) ?>">
                                                            <button class="link-danger" <?= easyit_button_attributes('loeschen') ?> type="submit">Löschen</button>
                                                        </form>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if($tableSourceKey==='system' && !empty($tableInspection['field_crud'])): ?>
                                <section class="df-column-editor" id="field-crud">
                                    <div class="df-toolbar">
                                        <div>
                                            <h4><?= $selectedColumn?'Feld bearbeiten':'Feld hinzufügen' ?></h4>
                                            <p><?= $selectedColumn?'Name, Datentyp, NULL, Vorgabewert, Extra-Eigenschaften und sekundären Index bearbeiten.':'Ein neues physisches Feld mit Vorgabewert, Extra-Eigenschaften und optionalem Sekundärindex anlegen.' ?></p>
                                        </div>
                                        <?php if($selectedColumn): ?>
                                        <a class="button secondary compact" href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system&amp;table=<?= rawurlencode($selectedTableName) ?>#field-crud">Neues Feld</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $tableAutoIncrementColumn='';
                                    foreach ((array)($tableInspection['columns']??[]) as $autoIncrementCandidate) {
                                        if (str_contains(strtolower((string)($autoIncrementCandidate['extra']??'')),'auto_increment')) {
                                            $tableAutoIncrementColumn=(string)$autoIncrementCandidate['name'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <form
                                        method="post"
                                        class="df-column-form"
                                        data-auto-increment-column="<?= e($tableAutoIncrementColumn) ?>"
                                        data-original-column="<?= e((string)($selectedColumn['name']??'')) ?>"
                                        data-driver="<?= e((string)($projectDriver??'mysql')) ?>"
                                    >
                                        <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                        <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                        <input type="hidden" name="section" value="tables">
                                        <input type="hidden" name="table_source" value="system">
                                        <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                        <input type="hidden" name="action" value="<?= $selectedColumn?'update_managed_column':'add_managed_column' ?>">
                                        <?php if($selectedColumn): ?><input type="hidden" name="original_column" value="<?= e((string)$selectedColumn['name']) ?>"><?php endif; ?>
                                        <div class="df-form-grid">
                                            <label>
                                                <strong>Feldname</strong>
                                                <input name="column_name" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{0,63}" value="<?= e((string)($selectedColumn['name']??'')) ?>" placeholder="z. B. date_time">
                                            </label>
                                            <label>
                                                <strong>Datentyp</strong>
                                                <input name="column_type" required value="<?= e((string)($selectedColumn['editable_type']??'varchar(255)')) ?>" placeholder="varchar(255)" data-column-type list="df-column-types">
                                                <datalist id="df-column-types">
                                                    <option value="varchar(255)">
                                                    <option value="text">
                                                    <option value="int">
                                                    <option value="bigint">
                                                    <option value="decimal(12,2)">
                                                    <option value="date">
                                                    <?php if(($projectDriver??'mysql')!=='pgsql'): ?><option value="datetime"><?php endif; ?>
                                                    <option value="timestamp">
                                                    <option value="boolean">
                                                </datalist>
                                            </label>
                                            <label class="full checkbox-line">
                                                <input type="checkbox" name="column_nullable" value="1" data-column-nullable <?= !$selectedColumn || strtoupper((string)($selectedColumn['nullable']??'YES'))==='YES'?'checked':'' ?>>
                                                <span>NULL-Werte zulassen</span>
                                            </label>

                                            <label>
                                                <strong>Vorgabewert</strong>
                                                <select name="column_default_kind" data-column-default-kind>
                                                    <option value="none" <?= ($selectedColumn['default_kind']??'none')==='none'?'selected':'' ?>>Kein Vorgabewert</option>
                                                    <option value="null" <?= ($selectedColumn['default_kind']??'')==='null'?'selected':'' ?>>NULL</option>
                                                    <option value="literal" <?= ($selectedColumn['default_kind']??'')==='literal'?'selected':'' ?>>Fester Wert</option>
                                                    <option value="current_timestamp" <?= ($selectedColumn['default_kind']??'')==='current_timestamp'?'selected':'' ?>>CURRENT_TIMESTAMP</option>
                                                </select>
                                            </label>

                                            <label>
                                                <strong>Wert</strong>
                                                <input
                                                    name="column_default_value"
                                                    value="<?= e((string)($selectedColumn['default_value']??'')) ?>"
                                                    placeholder="z. B. 0, offen oder 2026-01-01"
                                                    data-column-default-value
                                                >
                                            </label>

                                            <label>
                                                <strong>Sekundärindex</strong>
                                                <select name="column_index_kind" data-column-index-kind>
                                                    <option value="none" <?= ($selectedColumn['secondary_index']??'none')==='none'?'selected':'' ?>>Kein DataForm-Index</option>
                                                    <option value="index" <?= ($selectedColumn['secondary_index']??'')==='index'?'selected':'' ?>>INDEX</option>
                                                    <option value="unique" <?= ($selectedColumn['secondary_index']??'')==='unique'?'selected':'' ?>>UNIQUE INDEX</option>
                                                </select>
                                            </label>

                                            <label>
                                                <strong>Extra</strong>
                                                <?php $selectedExtras=(array)($selectedColumn['editable_extras']??[]); ?>
                                                <select name="column_extra[]" multiple size="4" class="df-multi-select" data-column-extra>
                                                    <?php if(($projectDriver??'mysql')!=='pgsql'): ?>
                                                    <option value="unsigned" <?= in_array('unsigned',$selectedExtras,true)?'selected':'' ?>>UNSIGNED</option>
                                                    <option value="zerofill" <?= in_array('zerofill',$selectedExtras,true)?'selected':'' ?>>ZEROFILL</option>
                                                    <option value="auto_increment" <?= in_array('auto_increment',$selectedExtras,true)?'selected':'' ?>>AUTO_INCREMENT</option>
                                                    <?php endif; ?>
                                                    <option value="on_update_current_timestamp" <?= in_array('on_update_current_timestamp',$selectedExtras,true)?'selected':'' ?>>ON UPDATE CURRENT_TIMESTAMP<?= ($projectDriver??'mysql')==='pgsql'?' (Trigger)':'' ?></option>
                                                </select>
                                            </label>
                                        </div>

                                        <div class="notice info df-column-option-info" data-column-option-info hidden></div>

                                        <p class="df-field-help">
                                            <?php if($selectedColumn): ?>
                                            Aktueller Vorgabewert:
                                            <code><?= e((string)($selectedColumn['default']??'NULL')) ?></code>.
                                            <?php endif; ?>
                                            Primär- und Fremdschlüssel sind geschützt. DataForm verwaltet ausschließlich eigene sekundäre Einspalten-Indizes.
                                            <?php if($selectedColumn && !empty($selectedColumn['external_indexes'])): ?>
                                            Bereits vorhandene externe/mehrspaltige Indizes:
                                            <code><?= e(implode(', ',(array)$selectedColumn['external_indexes'])) ?></code>.
                                            Diese werden nicht verändert.
                                            <?php endif; ?>
                                        </p>

                                        <p class="df-field-help">
                                            Mehrfachauswahl bei <strong>Extra</strong> mit Strg/Ctrl bzw. Cmd.
                                            <?php if(($projectDriver??'mysql')==='pgsql'): ?>
                                            PostgreSQL besitzt keinen eigenen Typ <code>DATETIME</code>; DataForm verwendet dafür <code>TIMESTAMP</code>.
                                            <code>CURRENT_TIMESTAMP</code> ist als Vorgabewert für <code>TIMESTAMP</code> zulässig.
                                            <code>ON UPDATE CURRENT_TIMESTAMP</code> wird bei PostgreSQL durch einen verwalteten Trigger umgesetzt.
                                            Zulässige Typen: <code>varchar(n)</code>, <code>text</code>, <code>int</code>, <code>bigint</code>,
                                            <code>decimal(p,s)</code>, <code>date</code>, <code>timestamp</code>, <code>boolean</code>.
                                            <?php else: ?>
                                            Extra-Regeln:
                                            <code>UNSIGNED</code>/<code>ZEROFILL</code> nur numerisch,
                                            <code>AUTO_INCREMENT</code> nur INT/BIGINT und nur einmal je Tabelle,
                                            <code>ON UPDATE CURRENT_TIMESTAMP</code> nur DATETIME/TIMESTAMP.
                                            Zulässige Typen:
                                            <code>varchar(n)</code>, <code>text</code>, <code>int</code>,
                                            <code>bigint</code>, <code>decimal(p,s)</code>, <code>date</code>,
                                            <code>datetime</code>, <code>timestamp</code>, <code>boolean</code>.
                                            <?php endif; ?>
                                        </p>
                                        <button class="button" <?= easyit_button_attributes($selectedColumn?'speichern':'neu') ?> type="submit"><?= $selectedColumn?'Feld speichern':'Feld anlegen' ?></button>
                                    </form>
                                </section>
                                <?php endif; ?>

                                <?php if($tableSourceIsCsv): ?>
                                <section class="df-column-editor" id="csv-field-crud">
                                    <div class="df-toolbar">
                                        <div>
                                            <h4><?= ($selectedColumn && (string)$selectedColumn['name']!=='id')?'CSV-Feld umbenennen':'CSV-Feld hinzufügen' ?></h4>
                                            <p>CSV-Felder besitzen keinen SQL-Datentyp. Alle Nutzwerte werden als Text gespeichert; <code>id</code> wird durch die CSV-Engine verwaltet.</p>
                                        </div>
                                        <?php if($selectedColumn && (string)$selectedColumn['name']!=='id'): ?>
                                        <a class="button secondary compact" <?= easyit_button_attributes('neu') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=<?= e($tableSourceKey) ?>&amp;table=<?= rawurlencode($selectedTableName) ?>#csv-field-crud">Neues CSV-Feld</a>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" class="df-column-form">
                                        <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                        <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                        <input type="hidden" name="section" value="tables">
                                        <input type="hidden" name="table_source" value="<?= e($tableSourceKey) ?>">
                                        <input type="hidden" name="table" value="<?= e($selectedTableName) ?>">
                                        <?php if($selectedColumn && (string)$selectedColumn['name']!=='id'): ?>
                                        <input type="hidden" name="action" value="rename_csv_column">
                                        <input type="hidden" name="column" value="<?= e((string)$selectedColumn['name']) ?>">
                                        <label><strong>Neuer Feldname</strong><input name="new_column_name" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{0,63}" value="<?= e((string)$selectedColumn['name']) ?>"></label>
                                        <p><button class="button" <?= easyit_button_attributes('speichern') ?> type="submit">CSV-Feld umbenennen</button></p>
                                        <?php else: ?>
                                        <input type="hidden" name="action" value="add_csv_column">
                                        <label><strong>Feldname</strong><input name="column_name" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{0,63}" placeholder="z. B. bezeichnung"></label>
                                        <p><button class="button" <?= easyit_button_attributes('neu') ?> type="submit">CSV-Feld anlegen</button></p>
                                        <?php endif; ?>
                                    </form>
                                </section>
                                <?php endif; ?>

                                <h4>Datenschau · maximal 20 Zeilen</h4>
                                <?php if(!empty($tableInspection['preview'])): ?>
                                <div class="df-table-scroll">
                                    <table class="df-field-table df-table-preview">
                                        <thead><tr>
                                            <?php foreach(array_keys((array)$tableInspection['preview'][0]) as $previewColumn): ?>
                                            <th><?= e((string)$previewColumn) ?></th>
                                            <?php endforeach; ?>
                                        </tr></thead>
                                        <tbody>
                                            <?php foreach((array)$tableInspection['preview'] as $previewRow): ?>
                                            <tr>
                                                <?php foreach($previewRow as $previewValue): ?>
                                                <td><?= e(is_scalar($previewValue)||$previewValue===null ? (string)$previewValue : (string)json_encode($previewValue,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state"><p>Die Tabelle enthält keine Datensätze.</p></div>
                                <?php endif; ?>
                            </section>
                            <?php else: ?>
                            <div class="empty-state">
                                <h3>Tabelle auswählen</h3>
                                <p>Wählen Sie links eine Tabelle, um Struktur, Zeilenzahl und Datenvorschau zu öffnen.</p>
                            </div>
                            <?php endif; ?>

                            <?php if($tableSourceKey==='system'): ?>
                            <section class="card df-create-table" id="new-table">
                                <h3>Neue Projekttabelle anlegen</h3>
                                <p>Tabellen, die hier angelegt werden, werden als DataForm-verwaltet registriert und können später kontrolliert wieder gelöscht werden.</p>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                    <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                    <input type="hidden" name="section" value="tables">
                                    <input type="hidden" name="action" value="create_managed_table">
                                    <input type="hidden" name="table_source" value="system">
                                    <label>
                                        <strong>Tabellenname</strong>
                                        <input name="table_name" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{0,63}" placeholder="z. B. artikel">
                                    </label>
                                    <label>
                                        <strong>Spalten</strong>
                                        <textarea name="columns" rows="7" required placeholder="artikelnummer:varchar(100)&#10;bezeichnung:varchar(255)&#10;preis:decimal(12,2)&#10;aktiv:boolean"></textarea>
                                    </label>
                                    <p class="df-field-help">
                                        <code>id</code> wird automatisch als Primärschlüssel erzeugt.
                                        Zulässige Typen: <code>varchar(n)</code>, <code>text</code>,
                                        <code>int</code>, <code>bigint</code>, <code>decimal(p,s)</code>,
                                        <code>date</code>, <code>datetime</code>, <code>timestamp</code>,
                                        <code>boolean</code>.
                                    </p>
                                    <button class="button" type="submit">Tabelle anlegen</button>
                                </form>
                            </section>
                            <?php elseif($tableSourceIsCsv): ?>
                            <section class="card df-create-table" id="new-table">
                                <h3>Neue CSV-Tabelle anlegen</h3>
                                <p>Die CSV-Engine legt im Datenbankordner eine neue <code>.csv</code>-Datei an. <code>id</code> wird automatisch als erste Pflichtspalte erzeugt.</p>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                    <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                    <input type="hidden" name="section" value="tables">
                                    <input type="hidden" name="action" value="create_csv_table">
                                    <input type="hidden" name="table_source" value="<?= e($tableSourceKey) ?>">
                                    <label>
                                        <strong>Tabellenname</strong>
                                        <input name="table_name" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_]{0,63}" placeholder="z. B. kunden">
                                    </label>
                                    <label>
                                        <strong>Spalten</strong>
                                        <textarea name="columns" rows="7" required placeholder="name&#10;email&#10;telefon&#10;notiz"></textarea>
                                    </label>
                                    <p class="df-field-help">Eine Spalte je Zeile. Optional angegebene Typen wie <code>name:varchar(100)</code> werden akzeptiert, aber CSV speichert Nutzfelder grundsätzlich als Text. Das Trennzeichen ist <code>|</code>.</p>
                                    <button class="button" <?= easyit_button_attributes('neu') ?> type="submit">CSV-Tabelle anlegen</button>
                                </form>
                            </section>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php elseif ($sectionKey === 'dataforms'): ?>
                    <div class="df-toolbar">
                        <div><h2>Formulare (DataForms)</h2><p>Formulare dieses Projekts öffnen, gestalten, löschen und neu anlegen.</p></div>
                        <?php if($projectWideScope): ?><a class="df-visible-action-link" href="#new-dataform">+ Formular anlegen</a><?php endif; ?>
                    </div>

                    <?php if ($dataforms): ?>
                        <div class="df-form-list">
                            <?php foreach ($dataforms as $form): ?>
                                <article class="df-form-item">
                                    <div>
                                        <h3><?= e((string)$form['name']) ?></h3>
                                        <p><?= e((string)($form['description'] ?: 'Noch keine Beschreibung.')) ?></p>
                                        <div class="df-form-meta">
                                            Interner Name: <code><?= e((string)$form['slug']) ?></code>
                                            · Status: <?= e((string)$form['status']) ?>
                                            · Lizenz: <strong><?= (string)($form['license_status'] ?? '')==='embedded_calendar' ? 'Calendar-intern' : e((string)($form['license_status'] ?? 'unlicensed')) ?></strong>
                                            · DF-UUID: <code><?= e((string)($form['df_uuid'] ?? '')) ?></code>
                                            <?php if ((string)($form['license_status'] ?? '') === 'approved' && (int)($form['license_token_issued_at'] ?? 0) > 0): ?>
                                                · DF-Token: <strong>ausgestellt</strong> (<?= e((string)($form['license_token_key_id'] ?? '')) ?>)
                                            <?php endif; ?>
                                            · Speichern: <?= (string)($form['table_save_mode'] ?? 'manual') === 'adhoc' ? 'Ad hoc' : 'manuell' ?>
                                            <?php if(isset($dataformBindingsById[(int)$form['id']])): $formBinding=$dataformBindingsById[(int)$form['id']]; ?>
                                            · Quelle: <?= e((string)($formBinding['source_name']??'Projekt-Datenbank')) ?><?= (($d=(string)($formBinding['source_driver']??'mysql'))!=='mysql')?' ('.e(['pgsql'=>'PostgreSQL','sqlite'=>'SQLite','csv'=>'CSV'][$d]??strtoupper($d)).')':'' ?>
                                            · Tabelle: <code><?= e((string)$formBinding['table_name']) ?></code>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="actions df-dataform-actions">
                                        <a class="button" <?= easyit_button_attributes('bearbeiten','dataform') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$form['id'] ?>#dataform-settings">Bearbeiten</a>
                                        <a class="button" <?= easyit_button_attributes('formular','dataform') ?> href="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$form['id'] ?>">Öffnen</a>
                                        <a class="button" <?= easyit_button_attributes('anzeigen','dataform_records') ?> href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$form['id'] ?>"><span aria-hidden="true">Datensätze anzeigen</span></a>
                                        <form
                                            method="post"
                                            action="?project=<?= (int)$project['id'] ?>&amp;section=dataforms"
                                            class="df-delete-dataform-form"
                                            data-dataform-name="<?= e((string)$form['name']) ?>"
                                        >
                                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                            <input type="hidden" name="action" value="delete_dataform">
                                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                            <input type="hidden" name="section" value="dataforms">
                                            <input type="hidden" name="dataform_id" value="<?= (int)$form['id'] ?>">
                                            <input type="hidden" name="confirm_name" value="">
                                            <button class="button" <?= easyit_button_attributes('loeschen','dataform') ?> type="submit">Löschen</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><h3>Noch keine Formulare</h3><p>Legen Sie das erste Formular (DataForm) für dieses Projekt an oder erzeugen Sie es aus einer Projekttabelle.</p></div>
                    <?php endif; ?>

                    <?php if($projectWideScope&&$unboundManagedTables): ?>
                    <section class="card" id="table-dataforms">
                        <div class="df-toolbar">
                            <div>
                                <h3>Formular aus Projekttabelle erzeugen</h3>
                                <p>Die Felddefinition wird aus der realen Tabelle übernommen. Datensätze werden anschließend direkt in dieser Tabelle gelesen und geschrieben.</p>
                            </div>
                            <a class="button secondary" href="relations.php?project=<?= (int)$project['id'] ?>">Beziehungen</a>
                        </div>
                        <div class="df-table-dataform-list">
                            <?php foreach($unboundManagedTables as $managedTable): ?>
                            <form
                                method="post"
                                action="?project=<?= (int)$project['id'] ?>&amp;section=dataforms"
                                class="df-table-dataform-item"
                                onsubmit="return confirm('Formular direkt aus der Projekttabelle <?= e((string)$managedTable) ?> erzeugen?');"
                            >
                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                <input type="hidden" name="action" value="create_dataform_from_table">
                                <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                <input type="hidden" name="section" value="dataforms">
                                <input type="hidden" name="table" value="<?= e((string)$managedTable) ?>">
                                <strong><code><?= e((string)$managedTable) ?></code></strong>
                                <button class="button" type="submit">Formular erzeugen</button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if($projectWideScope): ?><section class="card" id="new-dataform">
                        <h3>Neues Formular (DataForm) anlegen</h3>
                        <form method="post" action="?project=<?= (int)$project['id'] ?>&amp;section=dataforms">
                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                            <input type="hidden" name="action" value="create_dataform">
                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                            <input type="hidden" name="section" value="dataforms">
                            <div class="df-form-grid">
                                <label><strong>Name</strong><input name="name" required maxlength="160" placeholder="z. B. Kunden"></label>
                                <label><strong>Interner Name</strong><input name="slug" maxlength="160" placeholder="z. B. kunden"><small>Optional; wird andernfalls aus dem Namen erzeugt.</small></label>
                                <label class="full"><strong>Beschreibung</strong><textarea name="description" rows="4" placeholder="Wofür wird dieses Formular verwendet?"></textarea></label>
                            </div>
                            <p><button class="button" type="submit">Formular anlegen</button></p>
                        </form>
                    </section><?php endif; ?>
                <?php elseif (in_array($sectionKey, ['dataform','designer'], true) && $selectedDataform): ?>
                    <?php if($selectedLicensePolicy!==null): ?><div class="notice info"><strong>DataForm-Lizenz:</strong> <?= !empty($selectedLicensePolicy['has_dataform_overrides']) ? 'individuelle Funktionsbegrenzung aktiv' : 'Kundenlizenz wird vollständig geerbt' ?> · <?= count((array)($selectedLicensePolicy['capabilities']??[])) ?> Capability(s).</div><?php endif; ?>
                    <div class="df-toolbar">
                        <div><h2><?= e((string)$selectedDataform['name']) ?></h2><p><?= e((string)($selectedDataform['description'] ?: 'Formular gestalten und Felder verwalten.')) ?></p></div>
                        <a class="button secondary" href="?project=<?= (int)$project['id'] ?>&amp;section=dataforms">Zur Formular-Liste</a>
                    </div>
                    <?= dataform_config_map((int)$project['id'], (int)$selectedDataform['id'], $sectionKey === 'designer' ? 'designer' : 'settings') ?>
                    <?php if ($sectionKey === 'designer'): ?>
                    <div class="df-designer-shell">
                        <section class="df-canvas-card">
                            <div class="df-toolbar"><div><h3>Live-Vorschau</h3><p>Wählen Sie ein Feld aus, um rechts seine Eigenschaften zu bearbeiten.</p></div><span class="badge"><?= count($fields) ?> Felder</span></div>
                            <form class="df-live-form" onsubmit="return false">
                                <div class="df-preview-grid">
                                <?php foreach ($fields as $field): $cfg = dataform_field_config($field['configuration_json'] ?? null,(string)$field['field_type']); $width = $cfg['width'] ?? '100'; ?>
                                    <a class="df-preview-field width-<?= e((string)$width) ?> <?= $selectedFieldId === (int)$field['id'] ? 'selected' : '' ?>" href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;field=<?= (int)$field['id'] ?>">
                                        <label><?= e((string)$field['label']) ?><?= (int)$field['is_required'] === 1 ? ' *' : '' ?><?= !empty($cfg['hidden']) ? ' · verborgen' : '' ?><?= !empty($cfg['readonly']) ? ' · nur lesen' : '' ?></label>
                                        <?= dataform_preview_control($field,$cfg) ?>
                                    </a>
                                <?php endforeach; ?>
                                </div>
                                <?php if (!$fields): ?><div class="empty-state"><h3>Noch keine Felder</h3><p>Legen Sie im Reiter „Felder“ zuerst Felder an.</p></div><?php else: ?><div class="df-preview-actions"><button class="button" disabled>Speichern</button><button class="button secondary" disabled>Abbrechen</button></div><?php endif; ?>
                            </form>
                        </section>

                        <aside class="df-field-inspector">
                            <h3>Feldeigenschaften</h3><?= dataform_config_related((int)$project['id'],(int)$selectedDataform['id'],'designer') ?>
                            <?php if ($selectedField): $cfg = dataform_field_config($selectedField['configuration_json'] ?? null,(string)$selectedField['field_type']); $derivedCfg=(array)($cfg['derived_multienum']??[]); $typeSettings=DataFormFieldTypeRegistry::settings((string)$selectedField['field_type'],$cfg); ?>
                            <form method="post" action="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;field=<?= (int)$selectedField['id'] ?>" data-derived-field-form>
                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="update_field"><input type="hidden" name="project" value="<?= (int)$project['id'] ?>"><input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>"><input type="hidden" name="field_id" value="<?= (int)$selectedField['id'] ?>">
                                <label><strong>Beschriftung</strong><input name="label" required value="<?= e((string)$selectedField['label']) ?>"></label>
                                <label><strong>Interner Name</strong><input value="<?= e((string)$selectedField['name']) ?>" disabled></label>
                                <label><strong>Feldtyp</strong><select name="field_type" data-field-type-select><?= dataform_field_type_options((string)$selectedField['field_type']) ?></select></label>
                                <label><strong>Breite</strong><select name="width"><?php foreach (['25'=>'25 %','33'=>'33 %','50'=>'50 %','66'=>'66 %','75'=>'75 %','100'=>'100 %'] as $value=>$label): ?><option value="<?= e((string)$value) ?>" <?= (string)($cfg['width'] ?? '100')===(string)$value?'selected':'' ?>><?= e((string)$label) ?></option><?php endforeach; ?></select></label>
                                <label><strong>Platzhalter</strong><input name="placeholder" value="<?= e((string)($cfg['placeholder'] ?? '')) ?>"></label>
                                <label data-field-types="select multiselect multi_lookup"><strong>Auswahloptionen</strong><textarea name="options" rows="5" placeholder="Eine Option pro Zeile"><?= e(implode("\n", $cfg['options'] ?? [])) ?></textarea></label>

                                <fieldset class="df-lookup-config" data-field-types="lookup">
                                    <legend>Datensatz-Auswahl / Lookup</legend>
                                    <p class="df-field-help">
                                        Hier wird festgelegt, aus welcher Kind-/Referenztabelle die Datensätze für dieses Auswahlfeld kommen.
                                        Gespeichert wird der Schlüssel; angezeigt wird das gewählte Anzeigefeld.
                                    </p>
                                    <?php
                                    // HF151: Relation bevorzugen, aber auf die direkt am Feld gespeicherte
                                    // Lookup-Konfiguration zurückfallen. Damit bleiben die Einstellungen
                                    // auch sichtbar, wenn die Relationsmaterialisierung noch nicht vorhanden ist.
                                    $lookupTargetId=(int)($selectedLookupRelation['target_dataform_id']??($cfg['lookup_target_dataform_id']??0));
                                    $lookupKeyFieldId=(int)($selectedLookupRelation['_target_key_field_id']??($cfg['lookup_target_key_field_id']??0));
                                    $lookupDisplayFieldId=(int)($selectedLookupRelation['target_display_field_id']??($cfg['lookup_target_display_field_id']??0));
                                    ?>
                                    <label><strong>Auswahltabelle / Kind-DataForm</strong>
                                        <select name="lookup_target_dataform_id" data-lookup-target-dataform>
                                            <option value="">Bitte Tabelle wählen</option>
                                            <?php foreach($lookupDesignerDataforms as $lookupDf): ?>
                                            <option value="<?= (int)$lookupDf['id'] ?>" <?= $lookupTargetId===(int)$lookupDf['id']?'selected':'' ?>><?= e((string)$lookupDf['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Schlüsselfeld</strong>
                                        <select name="lookup_target_key_field_id" data-lookup-target-field>
                                            <option value="0">Technische Datensatz-ID</option>
                                            <?php foreach($lookupDesignerFieldsByForm as $lookupDfId=>$lookupDfFields): foreach($lookupDfFields as $lookupField): ?>
                                            <option data-lookup-dataform="<?= (int)$lookupDfId ?>" value="<?= (int)$lookupField['id'] ?>" <?= $lookupKeyFieldId===(int)$lookupField['id']?'selected':'' ?>><?= e((string)$lookupField['name']) ?> — <?= e((string)$lookupField['label']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Anzeigefeld</strong>
                                        <select name="lookup_target_display_field_id" data-lookup-target-field>
                                            <option value="0">Technische Datensatz-ID anzeigen</option>
                                            <?php foreach($lookupDesignerFieldsByForm as $lookupDfId=>$lookupDfFields): foreach($lookupDfFields as $lookupField): ?>
                                            <option data-lookup-dataform="<?= (int)$lookupDfId ?>" value="<?= (int)$lookupField['id'] ?>" <?= $lookupDisplayFieldId===(int)$lookupField['id']?'selected':'' ?>><?= e((string)$lookupField['name']) ?> — <?= e((string)$lookupField['label']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <small class="df-field-help">Beispiel Calendar: <code>events.category_id → categories.id</code>; angezeigt wird z. B. <code>categories.name</code>.</small>
                                </fieldset>

                                <?= dataform_field_type_settings_panel((string)$selectedField['field_type'],$cfg,false) ?>

                                <fieldset class="df-derived-config" data-derived-config-panel>
                                    <legend>Abgeleitete Mehrfachauswahl</legend>
                                    <p class="df-field-help">
                                        Die gespeicherte Spalte enthält stabile Quellwerte als kommaseparierte CSV-Liste. Angezeigt werden die Texte der gewählten Anzeigespalte.
                                    </p>
                                    <label><strong>Quelltabelle</strong>
                                        <select name="derived_source_table" data-derived-source-table>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): ?>
                                            <option value="<?= e((string)$derivedTable) ?>" <?= (string)($derivedCfg['source_table']??'')===(string)$derivedTable?'selected':'' ?>><?= e((string)$derivedTable) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Wertspalte</strong>
                                        <select name="derived_value_column" data-derived-column-select>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>" <?= (string)($derivedCfg['source_table']??'')===(string)$derivedTable && (string)($derivedCfg['value_column']??'')===(string)$derivedColumn['name']?'selected':'' ?>><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Anzeigespalte</strong>
                                        <select name="derived_label_column" data-derived-column-select>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>" <?= (string)($derivedCfg['source_table']??'')===(string)$derivedTable && (string)($derivedCfg['label_column']??'')===(string)$derivedColumn['name']?'selected':'' ?>><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Abhängigkeit</strong>
                                        <select name="derived_filter_mode" data-derived-filter-mode>
                                            <?php foreach([
                                                'none'=>'keine Filterung',
                                                'current_record_id'=>'Filterspalte = ID des aktuellen Datensatzes',
                                                'parent_record_id'=>'Filterspalte = aktuelle Eltern-ID',
                                                'field'=>'Filterspalte = Wert eines Feldes dieses DataForms',
                                            ] as $value=>$caption): ?>
                                            <option value="<?= e($value) ?>" <?= (string)($derivedCfg['filter_mode']??'none')===$value?'selected':'' ?>><?= e($caption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Filterspalte der Quelltabelle</strong>
                                        <select name="derived_filter_source_column" data-derived-column-select data-derived-filter-column>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>" <?= (string)($derivedCfg['source_table']??'')===(string)$derivedTable && (string)($derivedCfg['filter_source_column']??'')===(string)$derivedColumn['name']?'selected':'' ?>><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Vergleichsfeld dieses DataForms</strong>
                                        <select name="derived_filter_field_name" data-derived-filter-field>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($fields as $filterCandidate): ?>
                                            <option value="<?= e((string)$filterCandidate['name']) ?>" <?= (string)($derivedCfg['filter_field_name']??'')===(string)$filterCandidate['name']?'selected':'' ?>><?= e((string)$filterCandidate['label']) ?> (<?= e((string)$filterCandidate['name']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <div class="df-advanced-grid">
                                        <label><strong>Mindestauswahl</strong><input type="number" name="derived_min_selected" min="0" value="<?= (int)($derivedCfg['min_selected']??0) ?>"></label>
                                        <label><strong>Maximalauswahl</strong><input type="number" name="derived_max_selected" min="0" value="<?= (int)($derivedCfg['max_selected']??0) ?>"><small>0 = unbegrenzt</small></label>
                                        <label><strong>Max. geladene Optionen</strong><input type="number" name="derived_max_options" min="1" max="5000" value="<?= (int)($derivedCfg['max_options']??1000) ?>"></label>
                                    </div>
                                    <p class="df-field-help"><strong>Speicherung:</strong> <code>3,7,12</code> – gespeichert werden die Werte der Wertspalte, nicht die Anzeigetexte. Das feste Trennzeichen ist <code>,</code>.</p>
                                </fieldset>

                                <label class="checkbox-line"><input type="checkbox" name="is_required" value="1" <?= (int)$selectedField['is_required']===1?'checked':'' ?>> <span>Pflichtfeld</span></label>
                                <details class="df-field-advanced">
                                    <summary>Erweitert</summary>
                                    <div class="df-advanced-content">
                                        <section><h4>Darstellung &amp; Vorgaben</h4>
                                            <label><strong>Hilfetext</strong><textarea name="help_text" rows="3" maxlength="1000" placeholder="Zusätzliche Erklärung unter dem Eingabefeld"><?= e((string)($cfg['help_text'] ?? '')) ?></textarea></label>
                                            <fieldset class="df-default-provider">
                                                <legend>Standardwert</legend>
                                                <label><strong>Art des Standardwertes</strong>
                                                    <select name="default_mode" data-default-mode>
                                                        <option value="defined" <?= ($cfg['default_mode'] ?? 'defined')==='defined'?'selected':'' ?>>Wie definiert [Wert]</option>
                                                        <option value="current_timestamp" <?= ($cfg['default_mode'] ?? '')==='current_timestamp'?'selected':'' ?>>CURRENT_TIMESTAMP</option>
                                                        <?php if($licenseCanCustomJavascript): ?><option value="javascript" <?= ($cfg['default_mode'] ?? '')==='javascript'?'selected':'' ?>>JavaScript-Funktion</option><?php endif; ?>
                                                        <option value="parent_field" <?= ($cfg['default_mode'] ?? '')==='parent_field'?'selected':'' ?> <?= !$parentRelations?'disabled':'' ?>>Wert aus Eltern-DataForm</option>
                                                    </select>
                                                </label>
                                                <div data-default-panel="defined">
                                                    <label><strong>Wert</strong><input name="default_value" value="<?= e((string)($cfg['default_value'] ?? '')) ?>" placeholder="Fester Vorgabewert"></label>
                                                </div>
                                                <div data-default-panel="current_timestamp">
                                                    <p class="df-field-help">Wird bei jedem neuen Datensatz serverseitig neu erzeugt. Zulässig für Text, Mehrzeilentext, Datum und Datum/Uhrzeit.</p>
                                                </div>
                                                <?php if($licenseCanCustomJavascript): ?><div data-default-panel="javascript">
                                                    <label><strong>JavaScript-Funktion / Provider</strong>
                                                        <input name="default_js_provider" list="df-default-provider-list" value="<?= e((string)($cfg['default_js_provider'] ?? '')) ?>" placeholder="z. B. customerNumber">
                                                        <datalist id="df-default-provider-list">
                                                            <option value="uuid">
                                                            <option value="today">
                                                            <option value="currentIso">
                                                            <option value="timestampMillis">
                                                        </datalist>
                                                    </label>
                                                    <p class="df-field-help">Der Name verweist auf <code>window.easyITDefaultProviders.&lt;name&gt;</code> in <code>products/dataform/assets/default-providers.js</code>. Die Funktion wird nur beim Anlegen eines neuen Datensatzes ausgeführt.</p>
                                                </div><?php endif; ?>
                                                <div data-default-panel="parent_field">
                                                    <?php if ($parentRelations): ?>
                                                    <label><strong>Elternbeziehung</strong>
                                                        <select name="parent_relation_id" data-parent-relation-select>
                                                            <option value="">Bitte wählen</option>
                                                            <?php foreach ($parentRelations as $parentRelation): ?>
                                                            <option value="<?= (int)$parentRelation['id'] ?>" <?= (int)($cfg['parent_relation_id'] ?? 0)===(int)$parentRelation['id']?'selected':'' ?>>
                                                                <?= e((string)$parentRelation['name']) ?> → <?= e((string)$parentRelation['parent_name']) ?>
                                                            </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>

                                                    <label><strong>Feld des Eltern-DataForms</strong>
                                                        <select name="parent_field_id" data-parent-field-select>
                                                            <option value="">Bitte wählen</option>
                                                            <?php foreach ($parentFieldsByRelation as $relationId=>$parentFields): foreach ($parentFields as $parentField): ?>
                                                            <option
                                                                data-parent-relation="<?= (int)$relationId ?>"
                                                                value="<?= (int)$parentField['id'] ?>"
                                                                <?= (int)($cfg['parent_field_id'] ?? 0)===(int)$parentField['id']?'selected':'' ?>
                                                            >
                                                                <?= e((string)$parentField['label']) ?>
                                                                (<?= e((string)$parentField['name']) ?>)
                                                            </option>
                                                            <?php endforeach; endforeach; ?>
                                                        </select>
                                                    </label>

                                                    <p class="df-field-help">
                                                        Der Eltern-Datensatz wird über das Lookup-Feld der
                                                        1:n-Beziehung gewählt. Der Feldwert wird beim Anlegen
                                                        des Kind-Datensatzes als Vorgabewert übernommen.
                                                    </p>
                                                    <?php else: ?>
                                                    <p class="notice warning">
                                                        Dieses DataForm besitzt keine aktive 1:n-Elternbeziehung
                                                        mit Lookup-Feld.
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                            </fieldset>
                                            <label><strong>Textarea-Zeilen</strong><input type="number" name="rows" min="2" max="30" value="<?= (int)($cfg['rows'] ?? 3) ?>"></label>
                                        </section>
                                        <section><h4>Validierung</h4><div class="df-advanced-grid">
                                            <label><strong>Min. Zeichen</strong><input type="number" name="min_length" min="0" value="<?= $cfg['min_length'] === null ? '' : (int)$cfg['min_length'] ?>"></label>
                                            <label><strong>Max. Zeichen</strong><input type="number" name="max_length" min="0" value="<?= $cfg['max_length'] === null ? '' : (int)$cfg['max_length'] ?>"></label>
                                            <label class="full"><strong>Muster / Regex</strong><input name="pattern" value="<?= e((string)($cfg['pattern'] ?? '')) ?>" placeholder="z. B. [A-Z]{2}-[0-9]{5}"></label>
                                            <label><strong>Minimalwert</strong><input name="min_value" value="<?= e((string)($cfg['min_value'] ?? '')) ?>"></label>
                                            <label><strong>Maximalwert</strong><input name="max_value" value="<?= e((string)($cfg['max_value'] ?? '')) ?>"></label>
                                            <label><strong>Schrittweite</strong><input name="step" value="<?= e((string)($cfg['step'] ?? '')) ?>" placeholder="z. B. 0.01"></label>
                                        </div></section>
                                        <section><h4>Eingabeverhalten</h4>
                                            <label><strong>Autocomplete</strong><select name="autocomplete"><?php foreach ([''=>'automatisch','off'=>'aus','on'=>'ein','name'=>'Name','given-name'=>'Vorname','family-name'=>'Nachname','email'=>'E-Mail','username'=>'Benutzername','organization'=>'Organisation','tel'=>'Telefon','street-address'=>'Anschrift','postal-code'=>'Postleitzahl','country'=>'Land','current-password'=>'aktuelles Kennwort','new-password'=>'neues Kennwort'] as $value=>$caption): ?><option value="<?= e((string)$value) ?>" <?= (string)($cfg['autocomplete'] ?? '')===(string)$value?'selected':'' ?>><?= e((string)$caption) ?></option><?php endforeach; ?></select></label>
                                            <label><strong>Eingabemodus</strong><select name="inputmode"><?php foreach ([''=>'automatisch','text'=>'Text','decimal'=>'Dezimal','numeric'=>'Numerisch','tel'=>'Telefon','search'=>'Suche','email'=>'E-Mail','url'=>'URL'] as $value=>$caption): ?><option value="<?= e((string)$value) ?>" <?= (string)($cfg['inputmode'] ?? '')===(string)$value?'selected':'' ?>><?= e((string)$caption) ?></option><?php endforeach; ?></select></label>
                                            <label class="checkbox-line"><input type="checkbox" name="readonly" value="1" <?= !empty($cfg['readonly'])?'checked':'' ?>> <span>Nur lesen</span></label>
                                            <label class="checkbox-line"><input type="checkbox" name="hidden" value="1" <?= !empty($cfg['hidden'])?'checked':'' ?>> <span>Im Datensatzformular verborgen</span></label>
                                            <label class="checkbox-line"><input type="checkbox" name="trim" value="1" <?= !empty($cfg['trim'])?'checked':'' ?>> <span>Leerzeichen am Anfang/Ende entfernen</span></label>
                                        </section>
                                        <section><h4>Listen &amp; Suche</h4>
                                            <label class="checkbox-line"><input type="checkbox" name="list_visible" value="1" <?= !empty($cfg['list_visible'])?'checked':'' ?>> <span>Als Listenspalte verfügbar</span></label>
                                            <label class="checkbox-line"><input type="checkbox" name="searchable" value="1" <?= !empty($cfg['searchable'])?'checked':'' ?>> <span>In Volltextsuche berücksichtigen</span></label>
                                            <label class="checkbox-line"><input type="checkbox" name="filterable" value="1" <?= !empty($cfg['filterable'])?'checked':'' ?>> <span>Als Feldfilter anbieten</span></label>
                                            <label class="checkbox-line"><input type="checkbox" name="sortable" value="1" <?= !empty($cfg['sortable'])?'checked':'' ?>> <span>Sortierung erlauben</span></label>
                                        </section>
                                    </div>
                                </details>
                                <div class="df-field-savebar">
                                    <button class="button df-field-save" type="submit" data-button-skip>
                                        <?= (string)$selectedField['field_type']==='lookup' ? 'Auswahlfeld / Eigenschaften speichern' : 'Eigenschaften speichern' ?>
                                    </button>
                                </div>
                            </form>
                            <div class="df-order-actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="move_field"><input type="hidden" name="project" value="<?= (int)$project['id'] ?>"><input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>"><input type="hidden" name="field_id" value="<?= (int)$selectedField['id'] ?>"><button class="button secondary" name="direction" value="up">↑ Nach oben</button><button class="button secondary" name="direction" value="down">↓ Nach unten</button></form></div>
                            <?php else: ?><p>Wählen Sie in der Vorschau ein Feld aus.</p><?php endif; ?>
                        </aside>
                    </div>
                    <?php else: ?>
                    <section class="card" id="dataform-settings">
                        <div class="df-toolbar">
                            <div>
                                <h3>DataForm-Einstellungen</h3>
                                <p>Allgemeine Runtime-Eigenschaften. Layout, Felddefinitionen, Workflow und Beziehungen haben eigene Hauptbereiche und sind unten direkt verlinkt.</p>
                            </div>
                            <div class="actions">
                                <?php if($licenseDesignerAccess): ?><a class="button" <?= easyit_button_attributes('einstellungen','configuration') ?> href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;mode=layout">Layout &amp; Verhalten</a><?php endif; ?>
                                <a class="button" <?= easyit_button_attributes('bearbeiten','dataform') ?> href="workflow.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Workflow</a>
                                <?php if($licenseCanRelations): ?><a class="button" <?= easyit_button_attributes('beziehung') ?> href="relations.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Beziehungen</a><?php endif; ?>
                                <?php if($licenseCanPublish): ?><form method="post" class="inline-form" onsubmit="return confirm('Aktuellen DataForm-Entwurf auf dem geschützten Lizenz-/Runtime-Server validieren, kompilieren und veröffentlichen?');">
                                    <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                    <input type="hidden" name="action" value="publish_remote_definition">
                                    <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                                    <input type="hidden" name="section" value="dataform">
                                    <input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>">
                                    <button class="button" type="submit">Server-Revision veröffentlichen</button>
                                </form><?php endif; ?>
                            </div>
                        </div>
                        <?= dataform_config_related((int)$project['id'],(int)$selectedDataform['id'],'settings') ?>
                        <form method="post" data-dataform-settings-form action="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$selectedDataform['id'] ?>#dataform-settings">
                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                            <input type="hidden" name="action" value="update_dataform_settings">
                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                            <input type="hidden" name="section" value="dataform">
                            <input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>">
                            <div class="df-settings-guide" aria-label="Einstellungsgruppen">
                                <a href="#settings-basis"><strong>Basis</strong><span>Name, Status, Beschreibung</span></a>
                                <a href="#settings-view"><strong>Darstellung</strong><span>Ansicht, Seiten, Suche/Filter</span></a>
                                <a href="#settings-edit"><strong>Bearbeitung</strong><span>Speichern und CRUD</span></a>
                                <?php if($licenseCanCustomCss): ?><a href="#settings-style"><strong>Gestaltung</strong><span>CSS-Klasse und AddCSS</span></a><?php endif; ?>
                                <?php if($licenseCanEvents): ?><a href="#settings-events"><strong>DataForm-Ereignisse</strong><span>Formular-Lifecycle</span></a><a href="#settings-record-events"><strong>DS-Ereignisse</strong><span>Datensatz und CRUD</span></a><?php endif; ?>
                            </div>
                            <div class="df-settings-sections">
                              <fieldset class="df-setting-group" id="settings-basis"><legend>1 · Basis</legend><p class="muted">Identität und fachliche Beschreibung des DataForms. Felddefinitionen selbst werden unter <a href="#fields">Felder</a> gepflegt.</p><div class="df-form-grid">
                                <label><strong>Name</strong><input name="name" required maxlength="160" value="<?= e((string)$selectedDataform['name']) ?>"></label>
                                <label><strong>Interner Name</strong><input name="slug" required maxlength="160" pattern="[a-z0-9-]+" value="<?= e((string)$selectedDataform['slug']) ?>"><small>Stabiler technischer Bezeichner für Runtime und Anwenderexport.</small></label>
                                <label><strong>Status</strong><select name="status"><?php foreach(['draft'=>'Entwurf','active'=>'aktiv','inactive'=>'inaktiv','published'=>'veröffentlicht','archived'=>'archiviert'] as $statusValue=>$statusLabel): ?><option value="<?= e($statusValue) ?>" <?= (string)$selectedDataform['status']===$statusValue?'selected':'' ?>><?= e($statusLabel) ?></option><?php endforeach; ?></select></label>
                                <label class="full"><strong>Beschreibung</strong><textarea name="description" rows="4" placeholder="Wofür wird dieses Formular verwendet?"><?= e((string)($selectedDataform['description'] ?? '')) ?></textarea></label>
                              </div></fieldset>

                              <fieldset class="df-setting-group" id="settings-view"><legend>2 · Darstellung</legend><p class="muted">Bestimmt die Runtime-Ansicht. Struktur und Gruppen werden<?php if($licenseDesignerAccess): ?> im <a href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;mode=layout">Layout</a>, Feldbreiten im <a href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Formular-Designer</a><?php else: ?> durch die lizenzierte Runtime-Konfiguration<?php endif; ?> gepflegt.</p><div class="df-form-grid">
                                <label><strong>Standardansicht</strong><select name="view_mode"><option value="table" <?= (string)($selectedDataform['view_mode']??'table')==='table'?'selected':'' ?>>Tabelle</option><option value="form" <?= (string)($selectedDataform['view_mode']??'table')==='form'?'selected':'' ?>>Formular</option><option value="dialog" <?= (string)($selectedDataform['view_mode']??'table')==='dialog'?'selected':'' ?>>Dialog</option></select><small><a href="#real-preview">Direkt in der Realvorschau prüfen</a>.</small></label>
                                <label><strong>Datensätze pro Seite</strong><input type="number" name="default_per_page" min="1" max="200" value="<?= (int)($selectedDataform['default_per_page']??20) ?>"><small>Wirkt auf Tabelle und Paginierung. <a href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Datensätze öffnen</a>.</small></label>
                                <label><strong>Dialoggröße</strong><select name="dialog_size"><?php foreach(['small'=>'klein','medium'=>'mittel','large'=>'groß','fullscreen'=>'Vollbild'] as $dv=>$dl): ?><option value="<?=e($dv)?>" <?= (string)($selectedDataform['dialog_size']??'large')===$dv?'selected':'' ?>><?=e($dl)?></option><?php endforeach; ?></select><small>Nur relevant, wenn die Standardansicht „Dialog“ verwendet wird.</small></label>
                                <?php if($licenseCanSearch): ?><label><strong>Volltextsuche</strong><select name="show_search"><option value="1" <?= (int)($selectedDataform['show_search']??1)===1?'selected':'' ?>>Ja</option><option value="0" <?= (int)($selectedDataform['show_search']??1)===0?'selected':'' ?>>Nein</option></select><small>Ja: Volltextsuche über alle als durchsuchbar markierten sichtbaren Felder anzeigen und ausführen.</small></label><?php endif; ?>
                                <?php if($licenseCanFilter): ?><label><strong>Filter</strong><select name="show_filter"><option value="1" <?= (int)($selectedDataform['show_filter']??1)===1?'selected':'' ?>>Ja</option><option value="0" <?= (int)($selectedDataform['show_filter']??1)===0?'selected':'' ?>>Nein</option></select><small>Ja: Feldfilter und gespeicherte Filter für dieses DataForm anzeigen und ausführen.</small></label><?php endif; ?>
                                <?php if($licenseCanPagination): ?><label class="checkbox-line"><input type="checkbox" name="show_pagination" value="1" <?= (int)($selectedDataform['show_pagination']??1)===1?'checked':'' ?>><span>Paginierung anzeigen</span></label><?php endif; ?>
                              </div></fieldset>

                              <fieldset class="df-setting-group" id="settings-edit"><legend>3 · Bearbeitung</legend><p class="muted">Legt fest, welche CRUD-Aktionen die Runtime erlaubt. Feldbezogene Pflicht-/Nur-Lesen-Regeln bleiben unter <a href="#fields">Felder</a>.</p><div class="df-form-grid">
                                <label><strong>Tabellenänderungen speichern</strong><select name="table_save_mode"><option value="manual" <?= (string)($selectedDataform['table_save_mode'] ?? 'manual')==='manual'?'selected':'' ?>>Manuell – erst mit Speichern</option><option value="adhoc" <?= (string)($selectedDataform['table_save_mode'] ?? 'manual')==='adhoc'?'selected':'' ?>>Ad hoc – nach Feldänderung automatisch</option></select><small>Unter <a href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;mode=behavior">Verhalten</a> wird dieser Wert nur noch angezeigt, nicht doppelt bearbeitet.</small></label>
                                <?php if($licenseCanCreate): ?><label class="checkbox-line"><input type="checkbox" name="allow_create" value="1" <?= (int)($selectedDataform['allow_create']??1)===1?'checked':'' ?>><span>Datensätze anlegen</span></label><?php endif; ?>
                                <?php if($licenseCanUpdate): ?><label class="checkbox-line"><input type="checkbox" name="allow_edit" value="1" <?= (int)($selectedDataform['allow_edit']??1)===1?'checked':'' ?>><span>Datensätze bearbeiten</span></label><?php endif; ?>
                                <?php if($licenseCanDelete): ?><label class="checkbox-line"><input type="checkbox" name="allow_delete" value="1" <?= (int)($selectedDataform['allow_delete']??1)===1?'checked':'' ?>><span>Datensätze löschen</span></label><?php endif; ?>
                                <label class="full checkbox-line"><input type="checkbox" name="show_save_success" value="1" <?= (int)($selectedDataform['show_save_success'] ?? 1)===1?'checked':'' ?>><span><strong>Erfolgsmeldung nach dem Speichern anzeigen</strong><br><small>Fehler und Validierungsmeldungen bleiben immer sichtbar.</small></span></label>
                              </div></fieldset>

                              <?php if($licenseCanCustomCss): ?><fieldset class="df-setting-group" id="settings-style"><legend>4 · Gestaltung</legend><p class="muted">AddCSS ergänzt nur die Darstellung. Für Strukturänderungen verwenden Sie <a href="foundation.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;mode=layout">Layout</a> oder den <a href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Formular-Designer</a>.</p><div class="df-form-grid">
                                <label><strong>Zusätzliche CSS-Klasse</strong><input name="css_class" maxlength="160" value="<?= e((string)($selectedDataform['css_class']??'')) ?>" placeholder="z. B. kundenmaske kompakt"></label>
                                <label class="full"><strong>AddCSS</strong><textarea name="additional_css" rows="8" spellcheck="false" placeholder="/* Zusätzliches CSS nur für dieses DataForm */"><?= e((string)($selectedDataform['additional_css']??'')) ?></textarea><small>Wird nach dem Standard-CSS geladen und gilt nur für dieses DataForm. <a href="#real-preview">Wirkung prüfen</a>.</small></label>
                              </div></fieldset><?php endif; ?>

                              <?php if($licenseCanEvents): ?><fieldset class="df-setting-group" id="settings-events" data-event-domain="dataform"><legend>5 · DataForm-Ereignisse</legend><p class="muted"><strong>Nur Formular-/Ansichts-Lifecycle.</strong> CRUD-, Feld- und Datensatzereignisse werden ausschließlich im eigenen DS-/RecordSet-Handler darunter bearbeitet.</p><div class="df-form-grid">
                                <?php $eventHandlers=json_decode((string)($selectedDataform['event_handlers_json']??''),true); if(!is_array($eventHandlers))$eventHandlers=[]; foreach(['open'=>'Öffnen','close'=>'Schließen','before_view_change'=>'Vor Ansichtswechsel','view_change'=>'Nach Ansichtswechsel','before_refresh'=>'Vor Aktualisieren','after_refresh'=>'Nach Aktualisieren'] as $ek=>$el): ?>
<label class="full"><strong><?=e($el)?></strong><textarea name="event_<?=e($ek)?>" rows="3" spellcheck="false"><?=e((string)($eventHandlers[$ek]??''))?></textarea><small>Übergabeobjekt: <code>dataformContext</code>.</small></label><?php endforeach; ?>
<details class="full df-action-context-help"><summary>DataFormActionContext 1.0</summary><pre>{ schema, action, project, dataform, context, ui, event, result }</pre></details>
                              </div></fieldset>

                              <fieldset class="df-setting-group" id="settings-record-events" data-event-domain="recordset"><legend>6 · DS-/RecordSet-Ereignishandler</legend><p class="muted"><strong>Nur Datensatz-/CRUD-Lifecycle.</strong> Jeder DS wird über <code>recordset_key</code> getrennt gespeichert. Übergabe-/Übernahmeobjekt: <code>recordSet</code>.</p><input type="hidden" name="recordset_key" value="<?=e($recordsetKey??'main')?>"><div class="df-form-grid">
                                <?php foreach(['before_current_change'=>'Vor Datensatzwechsel','after_current_change'=>'Nach Datensatzwechsel','before_field_change'=>'Vor Feldänderung','after_field_change'=>'Nach Feldänderung','before_new'=>'Vor Neu','after_new'=>'Nach Neu','before_validate'=>'Vor Validierung','after_validate'=>'Nach Validierung','before_save'=>'Vor Speichern','after_save'=>'Nach Speichern','before_insert'=>'Vor INSERT','after_insert'=>'Nach INSERT','before_update'=>'Vor UPDATE','after_update'=>'Nach UPDATE','before_delete'=>'Vor Löschen','after_delete'=>'Nach Löschen'] as $ek=>$el): ?>
<label class="full"><strong><?=e($el)?></strong><textarea name="record_event_<?=e($ek)?>" rows="3" spellcheck="false"><?=e((string)(($recordEventHandlers??[])[$ek]??''))?></textarea><?php if(str_starts_with($ek,'before_')): ?><small><code>return false;</code> oder <code>recordSet.event.cancel=true;</code> bricht die Aktion ab. Änderungen an <code>recordSet.current.values</code> werden vor der Persistierung übernommen.</small><?php else: ?><small>Übergabeobjekt: <code>recordSet</code>.</small><?php endif; ?></label><?php endforeach; ?>
<details class="full df-action-context-help"><summary>RecordSet 1.0</summary><pre>{ schema, action, project, dataform, current: { id, values, original_values, changes, dirty_fields }, fields, state, navigation, selection, parent, relation, validation, ui, event, result }</pre></details>
<p class="full"><button class="button secondary" type="submit" name="save_recordset_events" value="1">DS-Ereignishandler speichern</button></p>
                              </div></fieldset><?php endif; ?>
                            </div>
                            <?php if(isset($dataformBindingsById[(int)$selectedDataform['id']])): $activeBinding=$dataformBindingsById[(int)$selectedDataform['id']]; ?>
                            <div class="notice info"><strong>Physische Tabellenbindung:</strong> <?= e((string)($activeBinding['source_name']??'Projekt-Datenbank')) ?><?= (($d=(string)($activeBinding['source_driver']??'mysql'))!=='mysql')?' · '.e(['pgsql'=>'PostgreSQL','sqlite'=>'SQLite','csv'=>'CSV'][$d]??strtoupper($d)):'' ?> / <code><?= e((string)$activeBinding['table_name']) ?></code>. Die Tabellenbindung wird hier nur angezeigt und nicht versehentlich verändert.</div>
                            <?php endif; ?>
                            <p><button class="button" <?= easyit_button_attributes('speichern','settings') ?> type="submit">DataForm-Einstellungen speichern</button></p>
                        </form>
                    </section>
                    <section class="card df-real-preview-card" id="real-preview">
                        <?= dataform_config_related((int)$project['id'],(int)$selectedDataform['id'],'preview') ?>
                        <div class="df-toolbar">
                            <div><h3>Realvorschau</h3><p>Die tatsächliche DataForm-Runtime mit echten Daten, Feldtypen, Beziehungen und gespeichertem AddCSS. Änderungen in der Vorschau werden nicht gespeichert.</p></div>
                            <div class="actions">
                                <button class="button" <?= easyit_button_attributes('aktualisieren') ?> type="button" data-preview-refresh>Vorschau aktualisieren</button>
                                <a class="button" <?= easyit_button_attributes('anzeigen','dataform_records') ?> href="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>" target="_blank" rel="noopener">Runtime öffnen</a>
                            </div>
                        </div>
                        <div class="df-real-preview-shell" data-real-preview-shell>
                            <iframe
                                class="df-real-preview-frame"
                                data-dataform-preview-frame
                                title="Realvorschau: <?= e((string)$selectedDataform['name']) ?>"
                                src="records.php?project=<?= (int)$project['id'] ?>&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;embed=1&amp;preview=1&amp;runtime_view=<?= e(in_array((string)($selectedDataform['view_mode']??'table'),['form','table','dialog'],true)?(string)$selectedDataform['view_mode']:'table') ?>"
                                loading="lazy"
                                sandbox="allow-forms allow-scripts allow-same-origin"
                            ></iframe>
                        </div>
                        <p class="muted df-real-preview-status" data-preview-status>Gespeicherter Runtime-Stand. Nach dem Speichern von Einstellungen wird die Seite neu geladen und die Vorschau übernimmt den neuen Stand.</p>
                    </section>
                    <section class="card" id="fields">
                        <div class="df-toolbar"><div><h3>Felder</h3><p><?= count($fields) ?> Feld(er) angelegt.</p></div><div class="actions"><?php if($licenseDesignerAccess): ?><a class="button secondary" href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>">Formular gestalten</a><?php endif; ?><a class="button" href="#new-field">+ Neues Feld</a></div></div>
                        <?php if ($fields): ?><div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>Position</th><th>Beschriftung</th><th>Interner Name</th><th>Typ</th><th>Pflicht</th><th>Aktion</th></tr></thead><tbody><?php foreach ($fields as $field): ?><tr><td><?= (int)$field['position'] ?></td><td><strong><?= e((string)$field['label']) ?></strong></td><td><code><?= e((string)$field['name']) ?></code></td><td><?= e(DataFormFieldTypeRegistry::label((string)$field['field_type'])) ?> <code><?= e((string)$field['field_type']) ?></code></td><td><?= (int)$field['is_required'] === 1 ? 'Ja' : 'Nein' ?></td><td><a href="?project=<?= (int)$project['id'] ?>&amp;section=designer&amp;dataform=<?= (int)$selectedDataform['id'] ?>&amp;field=<?= (int)$field['id'] ?>">Bearbeiten</a> · <form class="inline-form" method="post" onsubmit="return confirm('Feld wirklich löschen?');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="delete_field"><input type="hidden" name="project" value="<?= (int)$project['id'] ?>"><input type="hidden" name="section" value="dataform"><input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>"><input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>"><button class="link-danger" type="submit">Löschen</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><h3>Noch keine Felder</h3><p>Legen Sie das erste Feld für dieses DataForm an.</p></div><?php endif; ?>
                    </section>
                    <section class="card" id="new-field">
                        <h3>Neues Feld anlegen</h3>
                        <form method="post" action="?project=<?= (int)$project['id'] ?>&amp;section=dataform&amp;dataform=<?= (int)$selectedDataform['id'] ?>" data-derived-field-form>
                            <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                            <input type="hidden" name="action" value="create_field">
                            <input type="hidden" name="project" value="<?= (int)$project['id'] ?>">
                            <input type="hidden" name="section" value="dataform">
                            <input type="hidden" name="dataform" value="<?= (int)$selectedDataform['id'] ?>">
                            <div class="df-form-grid">
                                <label><strong>Beschriftung</strong><input name="label" required maxlength="190" placeholder="z. B. Informationen"></label>
                                <label><strong>Interner Feldname</strong><input name="field_name" required maxlength="160" placeholder="z. B. info_ids"></label>
                                <label><strong>Feldtyp</strong>
                                    <select name="field_type" data-field-type-select><?= dataform_field_type_options('text') ?></select>
                                </label>
                                <label><strong>Platzhalter</strong><input name="placeholder" maxlength="190"></label>
                                <label class="full" data-field-types="select multiselect multi_lookup"><strong>Auswahloptionen</strong><textarea name="options" rows="5" placeholder="Eine Option pro Zeile"></textarea></label>
                                <label class="full checkbox-line"><input type="checkbox" name="is_required" value="1"> <span>Pflichtfeld</span></label>
                            </div>
                            <?= dataform_field_type_settings_panel('text',[],true) ?>

                            <fieldset class="df-derived-config full" data-derived-config-panel>
                                <legend>Abgeleitete Mehrfachauswahl</legend>
                                <p class="df-field-help">Beispiel: Werte aus <code>ed_ev_info.id</code> anzeigen über <code>bemerkung</code> und als <code>3,7,12</code> speichern.</p>
                                <div class="df-form-grid">
                                    <label><strong>Quelltabelle</strong>
                                        <select name="derived_source_table" data-derived-source-table>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): ?>
                                            <option value="<?= e((string)$derivedTable) ?>"><?= e((string)$derivedTable) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Wertspalte</strong>
                                        <select name="derived_value_column" data-derived-column-select>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>"><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Anzeigespalte</strong>
                                        <select name="derived_label_column" data-derived-column-select>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>"><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Abhängigkeit</strong>
                                        <select name="derived_filter_mode" data-derived-filter-mode>
                                            <option value="none">keine Filterung</option>
                                            <option value="current_record_id">Filterspalte = ID des aktuellen Datensatzes</option>
                                            <option value="parent_record_id">Filterspalte = aktuelle Eltern-ID</option>
                                            <option value="field">Filterspalte = Wert eines Feldes dieses DataForms</option>
                                        </select>
                                    </label>
                                    <label><strong>Filterspalte der Quelltabelle</strong>
                                        <select name="derived_filter_source_column" data-derived-column-select data-derived-filter-column>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($derivedSourceCatalog as $derivedTable=>$derivedColumns): foreach($derivedColumns as $derivedColumn): ?>
                                            <option data-derived-table="<?= e((string)$derivedTable) ?>" value="<?= e((string)$derivedColumn['name']) ?>"><?= e((string)$derivedColumn['name']) ?> · <?= e((string)$derivedColumn['column_type']) ?></option>
                                            <?php endforeach; endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Vergleichsfeld dieses DataForms</strong>
                                        <select name="derived_filter_field_name" data-derived-filter-field>
                                            <option value="">Bitte wählen</option>
                                            <?php foreach($fields as $filterCandidate): ?>
                                            <option value="<?= e((string)$filterCandidate['name']) ?>"><?= e((string)$filterCandidate['label']) ?> (<?= e((string)$filterCandidate['name']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label><strong>Mindestauswahl</strong><input type="number" name="derived_min_selected" min="0" value="0"></label>
                                    <label><strong>Maximalauswahl</strong><input type="number" name="derived_max_selected" min="0" value="0"><small>0 = unbegrenzt</small></label>
                                    <label><strong>Max. geladene Optionen</strong><input type="number" name="derived_max_options" min="1" max="5000" value="1000"></label>
                                </div>
                            </fieldset>

                            <p><button class="button" type="submit">Feld anlegen</button></p>
                        </form>
                    </section>
                    <?php endif; ?>
                <?php else: ?>
                    <h2><?= e($section['title']) ?></h2><p><?= e($section['text']) ?></p>
                    <div class="empty-state"><h3><?= e($section['title']) ?> ist vorbereitet</h3><p>Die Fachfunktion wird in einer folgenden Phase freigeschaltet.</p></div>
                <?php endif; ?>
            </div>
        </main>

        <aside class="df-properties" id="df-context-help" aria-label="Eigenschaften und Kontexthilfe">
            <div class="df-pane-title">Eigenschaften &amp; Hilfe</div>
            <section><h3>Aktuelle Auswahl</h3><strong><?= e($section['title']) ?></strong><p><?= e($section['text']) ?></p></section>
            <section><h3>Projekt</h3><dl><div><dt>Status</dt><dd><?= e((string)$project['status']) ?></dd></div><div><dt>Produkt</dt><dd>DataForm</dd></div><div><dt>Datenbank</dt><dd><?= $dbConnected ? 'verbunden' : 'Fehler' ?></dd></div></dl></section>
            <section><h3>Kontexthilfe</h3><p><?= $sectionKey === 'dataforms' ? 'Legen Sie Formulare (DataForms) an, öffnen Sie sie zur Bearbeitung oder löschen Sie sie kontrolliert. Formulare können leer angelegt oder direkt aus einer Projekttabelle erzeugt werden.' : ($sectionKey === 'sources' ? 'Legen Sie projektbezogene Datenquellen an und prüfen Sie jede Verbindung mit „Verbindung testen“.' : ($sectionKey === 'tables' ? 'Wählen Sie eine Datenquelle und anschließend eine Tabelle. Aus einer DataForm-verwalteten Projekttabelle kann direkt ein zugehöriges DataForm mit automatisch abgeleiteten Feldern erzeugt werden; externe Quellen bleiben lesend geschützt.' : 'Wählen Sie links einen Bereich aus. In der Mitte öffnet sich der zugehörige Arbeitsbereich.')) ?></p></section>
        </aside>
    </div>

    <footer class="df-statusbar">
        <span>Projekt: <strong><?= e((string)$project['name']) ?></strong></span>
        <span>Benutzer: <strong><?= e((string)($user['username'] ?? $user['email'] ?? 'angemeldet')) ?></strong></span>
        <span>Datenbank: <strong class="<?= $dbConnected ? 'ok' : 'bad' ?>"><?= $dbConnected ? 'verbunden' : 'Fehler' ?></strong></span>
        <span>DataForms: <strong><?= (int)$stats['dataforms'] ?></strong></span>
        <span>Version: <strong>RC1.8-FC1-HF76</strong></span>
    </footer>
</div>
<?php endif; ?>
<?php
} catch (Throwable $renderThrowable) {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    error_log(
        '[easyIT DataForm HF76 render] '
        . $renderThrowable::class
        . ': '
        . $renderThrowable->getMessage()
    );
    ?>
    <div class="workspace-breadcrumbs">
        <ol>
            <li>Enterprise</li>
            <li>DataForm</li>
            <li>Renderfehler</li>
        </ol>
    </div>
    <div class="df-workspace">
        <main class="df-editor">
            <div class="df-editor-content">
                <div class="notice error" role="alert">
                    <strong>DataForm konnte den Arbeitsbereich nicht vollständig rendern.</strong>
                    <p><?= e($renderThrowable->getMessage()) ?></p>
                </div>
                <p>
                    Die Enterprise-Shell bleibt aktiv.
                    Fehlerprotokoll-Präfix:
                    <code>easyIT DataForm HF76 render</code>
                </p>
            </div>
        </main>
    </div>
    <?php
}
$content = ob_get_clean();

/*
 * HF18: execute the DataForm workspace through the unique runtime.php entry point.
 * No secondary renderer is involved in this critical path.  The browser must
 * therefore receive this marker whenever THIS index.php is executing.
 */
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/products/dataform/index.php'));
$suffix = '/products/dataform/runtime.php';
$rootUrl = str_ends_with($scriptName, $suffix) ? substr($scriptName, 0, -strlen($suffix)) : '';
$rootUrl = rtrim((string)$rootUrl, '/');
$url = static function(string $path) use ($rootUrl): string {
    return $rootUrl . '/' . ltrim($path, '/');
};
$projectRoot = dirname(__DIR__, 2);
$enterpriseCss = $projectRoot . '/assets/css/enterprise.css';
$workspaceCss = __DIR__ . '/assets/workspace.css';
$crudCss = $projectRoot . '/assets/css/easyit-crud-3d-buttons.css';
$enterpriseV = is_file($enterpriseCss) ? (string)filemtime($enterpriseCss) : '0';
$workspaceV = is_file($workspaceCss) ? (string)filemtime($workspaceCss) : '0';
$crudV = is_file($crudCss) ? (string)filemtime($crudCss) : '0';
$title = (string)($project['name'] ?? 'DataForm') . ' – Workspace';
header('X-EasyIT-DataForm-Runtime: HF76');
header('X-EasyIT-DataForm-Entry: products/dataform/runtime.php');
?>
<?php /* Historical regression compatibility only (not rendered): HF36 DATAFORM RUNTIME ACTIVE; HF41 PHYSICAL TYPE SYNC ACTIVE; DataForm · HF41 */ ?>

<!doctype html>
<html lang="de" data-easyit-runtime="dataform-hf36">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – easyIT Enterprise</title>
<link id="easyit-enterprise-css" rel="stylesheet" href="<?= e($url('assets/css/enterprise.css')) ?>?v=<?= e($enterpriseV) ?>">
<link id="easyit-dataform-workspace-css" rel="stylesheet" href="<?= e($url('products/dataform/assets/workspace.css')) ?>?v=<?= e($workspaceV) ?>">
<link id="easyit-global-crud-3d-css" rel="stylesheet" href="<?= e($url('assets/css/easyit-crud-3d-buttons.css')) ?>?v=<?= e($crudV) ?>">
<style id="hf23-critical-shell">
html,body{margin:0;min-height:100%;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#eef2f7;color:#172033}*{box-sizing:border-box}
.hf23-proof{position:fixed;right:12px;bottom:12px;z-index:99999;background:#7c2d12;color:#fff;border:2px solid #fdba74;border-radius:999px;padding:.45rem .8rem;font:700 12px/1 system-ui;box-shadow:0 3px 14px rgba(0,0,0,.25)}
.df-runtime-topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;min-height:68px;padding:.7rem 1.25rem;background:#0f294d;color:#fff}
.df-runtime-brand{display:flex;align-items:center;gap:.65rem;color:#fff;text-decoration:none;font-weight:800}.df-runtime-enterprise-logo{display:block;width:auto;height:auto;max-width:230px;max-height:52px;object-fit:contain;background:#fff;border-radius:6px;padding:.18rem .35rem}.df-runtime-enterprise-version{display:block;color:#c8d5e7;font-size:.72rem;line-height:1.1;white-space:nowrap}
.df-runtime-nav{display:flex;gap:.3rem;flex-wrap:wrap}.df-runtime-nav a{color:#e5edf8;text-decoration:none;padding:.5rem .65rem;border-radius:7px}.df-runtime-nav a:hover{background:#294b78;color:#fff}
.df-runtime-shell{padding:.75rem}.df-runtime-main{min-height:calc(100vh - 95px);background:#fff;border:1px solid #d7dee9;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.08)}
@media(max-width:950px){.df-runtime-topbar{align-items:flex-start;flex-direction:column}.df-runtime-nav{width:100%}}
</style>
</head>
<body class="workspace-page dataform-runtime-page">
<header class="df-runtime-topbar" data-runtime-shell="topbar">
  <a class="df-runtime-brand" href="<?= e($url('index.php')) ?>" aria-label="easyIT Enterprise Manager"><img class="df-runtime-enterprise-logo" src="<?= e($url('assets/img/easyit-epManager-logo.png')) ?>" alt="easyIT Enterprise Manager"><small class="df-runtime-enterprise-version"><?= e(trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION'))) ?></small></a>
  <nav class="df-runtime-nav" aria-label="Enterprise-Navigation">
    <a href="<?= e($url('app/dashboard.php')) ?>">Dashboard</a>
    <a href="<?= e($url('app/projects/index.php')) ?>">Projekte</a>
    <a href="<?= e($url('products/dataform/runtime.php')) ?>?project=<?= (int)$projectId ?>">DataForm</a>
    <a href="<?= e($url('app/security/users.php')) ?>">Benutzer &amp; Rechte</a>
    <a href="<?= e($url('app/security/audit.php')) ?>">Audit</a>
    <a href="<?= e($url('setup.php')) ?>">Setup</a>
    <a href="<?= e($url('recovery.php')) ?>">Recovery</a>
    <a href="<?= e($url('logout.php')) ?>">Abmelden</a>
  </nav>
</header>
<div class="df-runtime-shell"><main id="main" class="df-runtime-main" data-runtime-shell="main"><?= $content ?></main></div>
<script id="hf23-default-mode-ui">
(function(){
    document.querySelectorAll('.df-default-provider').forEach(function(scope){
        var select=scope.querySelector('[data-default-mode]');
        if(!select) return;
        function sync(){
            var mode=select.value||'defined';
            scope.querySelectorAll('[data-default-panel]').forEach(function(panel){
                panel.hidden=panel.getAttribute('data-default-panel')!==mode;
            });
        }
        select.addEventListener('change',sync);
        sync();

        var relationSelect=scope.querySelector('[data-parent-relation-select]');
        var fieldSelect=scope.querySelector('[data-parent-field-select]');

        if(relationSelect&&fieldSelect){
            function syncParentFields(){
                var relationId=relationSelect.value;
                var current=fieldSelect.value;

                fieldSelect.querySelectorAll('option[data-parent-relation]').forEach(function(option){
                    option.hidden=option.getAttribute('data-parent-relation')!==relationId;
                });

                if(current){
                    var currentOption=Array.from(fieldSelect.options).find(function(option){
                        return option.value===current;
                    });
                    if(currentOption&&currentOption.hidden){
                        fieldSelect.value='';
                    }
                }
            }

            relationSelect.addEventListener('change',syncParentFields);
            syncParentFields();
        }
    });
})();
</script>
<script id="hf76-fieldtypes-ui">
(function(){
    function syncFieldTypeForm(form){
        var select=form.querySelector('[data-field-type-select]');
        if(!select) return;
        var type=select.value;
        form.querySelectorAll('[data-field-types]').forEach(function(el){
            var types=(el.getAttribute('data-field-types')||'').split(/\s+/).filter(Boolean);
            el.hidden=types.length>0 && types.indexOf(type)===-1;
        });
    }
    function init(){
        document.querySelectorAll('form').forEach(function(form){
            var select=form.querySelector('[data-field-type-select]');
            if(!select) return;
            select.addEventListener('change',function(){syncFieldTypeForm(form);});
            syncFieldTypeForm(form);
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init,{once:true}); else init();
})();
</script>
<script id="hf147-lookup-designer-ui">
(function(){
    function syncLookupDesigner(scope){
        var table=scope.querySelector('[data-lookup-target-dataform]');
        if(!table) return;
        var sync=function(){
            var id=table.value;
            scope.querySelectorAll('[data-lookup-target-field] option[data-lookup-dataform]').forEach(function(option){
                var allowed=option.getAttribute('data-lookup-dataform')===id;
                option.hidden=!allowed;
                option.disabled=!allowed;
                if(!allowed && option.selected) option.selected=false;
            });
        };
        table.addEventListener('change',sync);
        sync();
    }
    document.querySelectorAll('[data-derived-field-form]').forEach(syncLookupDesigner);
})();
</script>
<script id="hf36-derived-multienum-ui">
(function(){
    function syncDerivedForm(form){
        var typeSelect=form.querySelector('[data-field-type-select]');
        var panel=form.querySelector('[data-derived-config-panel]');
        if(!typeSelect||!panel) return;

        var source=panel.querySelector('[data-derived-source-table]');
        var filterMode=panel.querySelector('[data-derived-filter-mode]');
        var filterColumn=panel.querySelector('[data-derived-filter-column]');
        var filterField=panel.querySelector('[data-derived-filter-field]');
        var columnSelects=panel.querySelectorAll('[data-derived-column-select]');

        function filterColumns(resetInvalid){
            var table=source?source.value:'';
            columnSelects.forEach(function(select){
                var selected=select.value;
                var selectedVisible=false;
                Array.from(select.options).forEach(function(option,index){
                    if(index===0||!option.hasAttribute('data-derived-table')){
                        option.hidden=false;
                        option.disabled=false;
                        return;
                    }
                    var allowed=option.getAttribute('data-derived-table')===table;
                    option.hidden=!allowed;
                    option.disabled=!allowed;
                    if(allowed&&option.value===selected) selectedVisible=true;
                });
                if(resetInvalid&&selected&&!selectedVisible){
                    select.value='';
                }
            });
        }

        function chooseDefaults(){
            var valueSelect=panel.querySelector('select[name="derived_value_column"]');
            var labelSelect=panel.querySelector('select[name="derived_label_column"]');
            if(valueSelect&&!valueSelect.value){
                var idOption=Array.from(valueSelect.options).find(function(option){
                    return !option.disabled&&option.value==='id';
                });
                if(idOption) valueSelect.value='id';
            }
            if(labelSelect&&!labelSelect.value){
                var priorities=['name','label','bezeichnung','bemerkung','title','typ'];
                var chosen=null;
                priorities.some(function(name){
                    chosen=Array.from(labelSelect.options).find(function(option){
                        return !option.disabled&&option.value.toLowerCase()===name;
                    })||null;
                    return !!chosen;
                });
                if(!chosen){
                    chosen=Array.from(labelSelect.options).find(function(option){
                        return option.value!==''&&!option.disabled&&option.value!=='id';
                    })||null;
                }
                if(chosen) labelSelect.value=chosen.value;
            }
        }

        function syncFilter(){
            var mode=filterMode?filterMode.value:'none';
            if(filterColumn){
                filterColumn.disabled=mode==='none';
            }
            if(filterField){
                filterField.disabled=mode!=='field';
            }
        }

        function syncVisibility(){
            var active=typeSelect.value==='derived_multienum';
            panel.hidden=!active;
            panel.querySelectorAll('select,input,textarea').forEach(function(control){
                if(!active){
                    control.dataset.derivedWasDisabled=control.disabled?'1':'0';
                    control.disabled=true;
                }else if(control.dataset.derivedWasDisabled!=='1'){
                    control.disabled=false;
                }
            });
            if(active){
                filterColumns(false);
                syncFilter();
            }
        }

        if(source){
            source.addEventListener('change',function(){
                filterColumns(true);
                chooseDefaults();
            });
        }
        if(filterMode) filterMode.addEventListener('change',syncFilter);
        typeSelect.addEventListener('change',syncVisibility);

        filterColumns(false);
        syncVisibility();
    }

    document.querySelectorAll('[data-derived-field-form]').forEach(syncDerivedForm);
})();
</script>
<script id="hf28-column-editor-ui">
(function(){
    function optionByValue(select,value){
        return Array.from(select.options).find(function(option){
            return option.value===value;
        }) || null;
    }

    function setSingleOption(select,value,allowed){
        var option=optionByValue(select,value);
        if(!option) return false;
        option.hidden=!allowed;
        option.disabled=!allowed;
        if(!allowed && option.selected){
            select.value='none';
            return true;
        }
        return false;
    }

    document.querySelectorAll('.df-column-form').forEach(function(form){
        var typeInput=form.querySelector('[data-column-type]');
        var nullable=form.querySelector('[data-column-nullable]');
        var defaultKind=form.querySelector('[data-column-default-kind]');
        var defaultValue=form.querySelector('[data-column-default-value]');
        var indexKind=form.querySelector('[data-column-index-kind]');
        var extras=form.querySelector('[data-column-extra]');
        var info=form.querySelector('[data-column-option-info]');

        if(!typeInput||!nullable||!defaultKind||!defaultValue||!indexKind||!extras){
            return;
        }

        var tableAutoIncrementColumn=form.getAttribute('data-auto-increment-column')||'';
        var originalColumn=form.getAttribute('data-original-column')||'';
        var driver=(form.getAttribute('data-driver')||'mysql').toLowerCase();

        function typeState(){
            var type=typeInput.value.trim().toLowerCase();
            return {
                type:type,
                numeric:/^(?:int|integer|bigint|decimal\(\d{1,2},\d{1,2}\))$/.test(type),
                integer:/^(?:int|integer|bigint)$/.test(type),
                temporal:/^(?:datetime|timestamp)$/.test(type),
                indexable:type!=='text'
            };
        }

        function extraOption(value){
            return Array.from(extras.options).find(function(option){
                return option.value===value;
            }) || null;
        }

        function setExtraAvailability(value,allowed,message,messages){
            var option=extraOption(value);
            if(!option) return;
            option.hidden=!allowed;
            option.disabled=!allowed;
            if(!allowed && option.selected){
                option.selected=false;
                messages.push(message);
            }
        }

        function showInfo(messages){
            var unique=Array.from(new Set(messages.filter(Boolean)));
            if(!info) return;
            if(unique.length===0){
                info.hidden=true;
                info.textContent='';
                return;
            }
            info.hidden=false;
            info.textContent=unique.join(' ');
        }

        function sync(){
            var state=typeState();
            var messages=[];

            var autoIncrement=extraOption('auto_increment');
            var zerofill=extraOption('zerofill');
            var unsigned=extraOption('unsigned');

            setExtraAvailability(
                'unsigned',
                driver!=='pgsql' && state.numeric,
                driver==='pgsql'
                    ? 'UNSIGNED wurde entfernt: PostgreSQL unterstützt diese MySQL-Eigenschaft nicht.'
                    : 'UNSIGNED wurde entfernt: nur numerische Datentypen unterstützen diese Eigenschaft.',
                messages
            );
            setExtraAvailability(
                'zerofill',
                driver!=='pgsql' && state.numeric,
                driver==='pgsql'
                    ? 'ZEROFILL wurde entfernt: PostgreSQL unterstützt diese MySQL-Eigenschaft nicht.'
                    : 'ZEROFILL wurde entfernt: nur numerische Datentypen unterstützen diese Eigenschaft.',
                messages
            );

            var autoIncrementAvailable=
                driver!=='pgsql'
                && state.integer
                && !nullable.checked
                && (
                    tableAutoIncrementColumn===''
                    || tableAutoIncrementColumn===originalColumn
                );

            setExtraAvailability(
                'auto_increment',
                autoIncrementAvailable,
                driver==='pgsql'
                    ? 'AUTO_INCREMENT wurde entfernt: PostgreSQL verwendet Identity/Sequenzen.'
                    : !state.integer
                        ? 'AUTO_INCREMENT wurde entfernt: nur INT/BIGINT ist zulässig.'
                        : nullable.checked
                            ? 'AUTO_INCREMENT wurde entfernt: das Feld lässt NULL-Werte zu.'
                            : 'AUTO_INCREMENT wurde entfernt: die Tabelle besitzt bereits ein anderes AUTO_INCREMENT-Feld.',
                messages
            );

            setExtraAvailability(
                'on_update_current_timestamp',
                state.temporal,
                'ON UPDATE CURRENT_TIMESTAMP wurde entfernt: nur DATETIME/TIMESTAMP ist zulässig.',
                messages
            );

            if(zerofill && zerofill.selected && unsigned && !unsigned.selected){
                unsigned.selected=true;
                messages.push('UNSIGNED wurde automatisch ergänzt, weil ZEROFILL diese Eigenschaft voraussetzt.');
            }

            var autoIncrementSelected=autoIncrement && autoIncrement.selected;

            if(setSingleOption(defaultKind,'null',nullable.checked && !autoIncrementSelected)){
                messages.push(
                    autoIncrementSelected
                        ? 'DEFAULT NULL wurde entfernt: AUTO_INCREMENT verwendet keinen eigenen Vorgabewert.'
                        : 'DEFAULT NULL wurde entfernt: NULL-Werte sind für dieses Feld nicht zugelassen.'
                );
            }

            if(setSingleOption(defaultKind,'literal',!autoIncrementSelected)){
                messages.push('Der feste Vorgabewert wurde entfernt: AUTO_INCREMENT verwendet keinen eigenen Vorgabewert.');
            }

            if(setSingleOption(
                defaultKind,
                'current_timestamp',
                state.temporal && !autoIncrementSelected
            )){
                messages.push(
                    autoIncrementSelected
                        ? 'CURRENT_TIMESTAMP wurde entfernt: AUTO_INCREMENT verwendet keinen eigenen Vorgabewert.'
                        : 'CURRENT_TIMESTAMP wurde entfernt: nur DATETIME/TIMESTAMP ist zulässig.'
                );
            }

            if(setSingleOption(indexKind,'index',state.indexable)){
                messages.push('INDEX wurde entfernt: TEXT wird in dieser Verwaltung nicht ohne Präfix indexiert.');
            }
            if(setSingleOption(indexKind,'unique',state.indexable)){
                messages.push('UNIQUE INDEX wurde entfernt: TEXT wird in dieser Verwaltung nicht ohne Präfix indexiert.');
            }

            defaultValue.disabled=defaultKind.value!=='literal';
            if(defaultValue.disabled){
                defaultValue.setAttribute('aria-disabled','true');
            }else{
                defaultValue.removeAttribute('aria-disabled');
            }

            showInfo(messages);
        }

        typeInput.addEventListener('input',sync);
        typeInput.addEventListener('change',sync);
        nullable.addEventListener('change',sync);
        defaultKind.addEventListener('change',sync);
        indexKind.addEventListener('change',sync);
        extras.addEventListener('change',sync);

        sync();
    });
})();
</script>
<script>
(function(){
  var menu=document.querySelector('[data-df-menu]');
  if(!menu)return;
  var groups=Array.prototype.slice.call(menu.querySelectorAll('details.df-menu-item'));
  groups.forEach(function(group){
    group.addEventListener('toggle',function(){
      if(!group.open)return;
      groups.forEach(function(other){if(other!==group)other.open=false;});
    });
  });
  document.addEventListener('click',function(ev){
    if(menu.contains(ev.target))return;
    groups.forEach(function(group){group.open=false;});
  });
  document.addEventListener('keydown',function(ev){
    if(ev.key!=='Escape')return;
    var open=menu.querySelector('details[open]');
    if(!open)return;
    open.open=false;
    var summary=open.querySelector('summary');
    if(summary)summary.focus();
  });
})();
</script>
<?= easyit_button_registry_data_tag() ?>
<script>
(function(){
  function frame(){return document.querySelector('[data-dataform-preview-frame]');}
  function settingsForm(){return document.querySelector('[data-dataform-settings-form]');}
  function viewLabel(value){return value==='form'?'Formular':(value==='dialog'?'Dialog':'Tabelle');}
  function selectedViewMode(){var f=settingsForm();var c=f&&f.querySelector('[name="view_mode"]');var v=c?String(c.value||'table'):'table';return ['form','table','dialog'].includes(v)?v:'table';}
  function loadPreview(options){
    options=options||{};
    var f=frame();if(!f)return;
    var u=new URL(f.src,window.location.href);
    // PUBLISH14: Die Realvorschau folgt der im Editor aktuell gewählten
    // Standardansicht sofort. Der Wert wird nur für die Vorschau übergeben;
    // gespeichert wird weiterhin ausschließlich über den Speichern-Button.
    var mode=selectedViewMode();
    u.searchParams.set('runtime_view',mode);
    u.searchParams.set('_preview_ts',String(Date.now()));
    f.src=u.toString();
    var st=document.querySelector('[data-preview-status]');
    if(st) st.textContent=options.unsaved
      ? 'Live-Vorschau: '+viewLabel(mode)+' (noch nicht gespeicherte Auswahl).'
      : 'Vorschau wird aktualisiert: '+viewLabel(mode)+' …';
  }
  document.addEventListener('click',function(ev){var b=ev.target.closest('[data-preview-refresh]');if(!b)return;ev.preventDefault();loadPreview();});
  var form=settingsForm();
  if(form){
    var viewControl=form.querySelector('[name="view_mode"]');
    if(viewControl){
      viewControl.addEventListener('change',function(){loadPreview({unsaved:true});});
    }
  }
  window.addEventListener('message',function(ev){
    if(ev.origin!==window.location.origin||!ev.data||typeof ev.data!=='object')return;
    var f=frame();if(!f)return;
    if(ev.data.type==='easyit-dataform-preview-height'){
      var h=Math.max(520,Math.min(1200,parseInt(ev.data.height||0,10)||720));f.style.height=h+'px';
      var st=document.querySelector('[data-preview-status]');if(st&&!st.textContent.includes('noch nicht gespeicherte'))st.textContent='Realvorschau ist aktuell: '+viewLabel(selectedViewMode())+'.';
    }else if(ev.data.type==='easyit-dataform-preview-write-blocked'){
      var st=document.querySelector('[data-preview-status]');if(st)st.textContent='Vorschau: Speichern/Löschen ist gesperrt. Öffnen Sie für echte Änderungen die Runtime.';
    }
  });
})();
</script>
<script src="<?= e($url('assets/js/easyit-button-registry.js')) ?>?v=<?= e((string)(is_file($projectRoot . '/assets/js/easyit-button-registry.js') ? filemtime($projectRoot . '/assets/js/easyit-button-registry.js') : '0')) ?>" defer></script>
<script id="hf30-dataform-delete-ui">
(function(){
    document.querySelectorAll('.df-delete-dataform-form').forEach(function(form){
        form.addEventListener('submit',function(event){
            var name=form.getAttribute('data-dataform-name')||'';
            var warning='DataForm „'+name+'“ wirklich löschen?\n\n'
                +'Dabei werden auch Felder, Datensätze, Workflows, Beziehungen, '
                +'Abfragen sowie direkt abhängige Berichte/API-Endpunkte entfernt.\n\n'
                +'Dieser Vorgang kann nicht rückgängig gemacht werden.';
            if(!window.confirm(warning)){
                event.preventDefault();
                return;
            }
            var typed=window.prompt(
                'Zur Bestätigung den DataForm-Namen exakt eingeben:',
                ''
            );
            if(typed===null || typed!==name){
                event.preventDefault();
                if(typed!==null){
                    window.alert('Löschen abgebrochen: Der eingegebene Name stimmt nicht überein.');
                }
                return;
            }
            var input=form.querySelector('input[name="confirm_name"]');
            if(input) input.value=typed;
        });
    });
})();
</script>
</body>
</html>
<?php
