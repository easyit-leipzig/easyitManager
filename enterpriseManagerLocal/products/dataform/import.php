<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once __DIR__ . '/system/DataFormTransport.php';
require_once dirname(__DIR__,2).'/system/licensing/Autoload.php';

$user = enterprise_require_auth('../../');
enterprise_require_customer_module($user,'dataform');
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0));
enterprise_require_project_scope($user,$projectId,false);
$dataformId = (int)($_GET['dataform'] ?? $_POST['dataform'] ?? 0);
$error = '';
$success = '';
$project = $dataform = null;
$fields = [];
$profiles = [];
$history = [];
$runDetail = null;
$runChanges = [];
$preview = $_SESSION['df_csv_import'][$projectId][$dataformId] ?? null;
$importResult = $_SESSION['df_csv_import_result'][$projectId][$dataformId] ?? null;
$importCreatedMedia = [];
$importMediaManager = null;

function df_import_delimiter(string $line): string
{
    $candidates = [";", ",", "|", "\t"];
    $best = ';';
    $bestCount = -1;
    foreach ($candidates as $candidate) {
        $count = substr_count($line, $candidate);
        if ($count > $bestCount) { $bestCount = $count; $best = $candidate; }
    }
    return $best;
}

function df_import_utf8(string $value): string
{
    if (str_starts_with($value, "\xEF\xBB\xBF")) $value = substr($value, 3);
    if (mb_check_encoding($value, 'UTF-8')) return $value;
    return mb_convert_encoding($value, 'UTF-8', ['Windows-1252', 'ISO-8859-1']);
}

function df_import_validate(array $fields, array $data): array
{
    $normalized = [];
    $errors = [];
    foreach ($fields as $field) {
        $name=(string)$field['name'];
        $label=(string)$field['label'];
        $type=(string)$field['field_type'];
        $cfg=DataFormTransport::configuration($field['configuration_json']??null);
        $raw=$data[$name]??'';
        try {
            if ($type==='computed') {
                continue;
            }
            if (DataFormFieldTypeRegistry::isMedia($type)) {
                $value=trim((string)$raw);
                if ($value==='') {
                    $normalized[$name]='';
                } else {
                    $media=json_decode($value,true,512,JSON_THROW_ON_ERROR);
                    if (!is_array($media) || trim((string)($media['data_base64']??''))==='') {
                        throw new RuntimeException('erwartet ein Media-JSON-Objekt mit data_base64.');
                    }
                    $bytes=base64_decode((string)$media['data_base64'],true);
                    if ($bytes===false) throw new RuntimeException('enthält ungültige Base64-Daten.');
                    $settings=DataFormFieldTypeRegistry::settings($type,$cfg);
                    if (strlen($bytes)>(int)$settings['max_bytes']) throw new RuntimeException('überschreitet die erlaubte Maximalgröße.');
                    $normalized[$name]=json_encode($media,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                }
            } else {
                $normalized[$name]=DataFormFieldTypeRegistry::normalizeValue($type,$raw,$cfg,$label,'');
            }
        } catch (Throwable $e) {
            $errors[]='„'.$label.'“ '.$e->getMessage();
            $normalized[$name]=is_scalar($raw)?(string)$raw:'';
        }
        if ((int)$field['is_required']===1 && !DataFormFieldTypeRegistry::isBoolean($type) && $type!=='computed' && trim((string)($normalized[$name]??''))==='') {
            $errors[]='Pflichtfeld „'.$label.'“ ist leer.';
        }
    }
    foreach ($fields as $field) {
        if ((string)$field['field_type']!=='computed') continue;
        $cfg=DataFormTransport::configuration($field['configuration_json']??null);
        $normalized[(string)$field['name']]=DataFormFieldTypeRegistry::computeValue($cfg,$normalized);
    }
    return [$normalized, array_values(array_unique($errors))];
}

function df_import_mapping(array $mapping, array $fields): array
{
    $valid = array_column($fields, 'name');
    $clean = [];
    $used = [];
    foreach ($mapping as $columnIndex => $fieldName) {
        $fieldName = (string)$fieldName;
        if ($fieldName === '') continue;
        if (!ctype_digit((string)$columnIndex) || !in_array($fieldName, $valid, true)) throw new RuntimeException('Die Feldzuordnung enthält ein unbekanntes Zielfeld.');
        if (in_array($fieldName, $used, true)) throw new RuntimeException('Ein DataForm-Feld darf nur einmal zugeordnet werden.');
        $clean[(string)(int)$columnIndex] = $fieldName;
        $used[] = $fieldName;
    }
    foreach ($fields as $field) {
        if ((int)$field['is_required'] === 1 && !DataFormFieldTypeRegistry::isBoolean((string)$field['field_type']) && (string)$field['field_type'] !== 'computed' && !in_array((string)$field['name'], $used, true)) {
            throw new RuntimeException('Das Pflichtfeld „'.$field['label'].'“ muss einer CSV-Spalte zugeordnet werden.');
        }
    }
    return $clean;
}

function df_import_row_data(array $csvRow, array $mapping): array
{
    $data = [];
    foreach ($mapping as $columnIndex => $fieldName) $data[$fieldName] = (string)($csvRow['values'][(int)$columnIndex] ?? '');
    return $data;
}

function df_import_duplicate_key(array $data, array $duplicateFields): ?string
{
    if (!$duplicateFields) return null;
    $parts = [];
    foreach ($duplicateFields as $fieldName) {
        $value = mb_strtolower(trim((string)($data[$fieldName] ?? '')));
        if ($value === '') return null; // Leere Schlüsselwerte gelten nicht als sicherer Treffer.
        $parts[] = $fieldName.'='.$value;
    }
    return hash('sha256', implode("\x1F", $parts));
}

function df_import_existing_index(PDO $pdo, int $dataformId, array $duplicateFields): array
{
    if (!$duplicateFields) return [];
    $index=[];
    foreach (DataFormRecordStore::all($pdo,$dataformId) as $row) {
        $data=is_array($row['data']??null)?$row['data']:[];
        $key=df_import_duplicate_key($data,$duplicateFields);
        if ($key!==null && !isset($index[$key])) $index[$key]=['id'=>(int)$row['id'],'data'=>$data];
    }
    return $index;
}

function df_import_analyse(array $rows, array $mapping, array $fields, array $duplicateFields, array $existingIndex): array
{
    $result = ['valid'=>0,'invalid'=>0,'duplicates'=>0,'new'=>0,'errors'=>[]];
    $seen = $existingIndex;
    foreach ($rows as $csvRow) {
        [$normalized, $rowErrors] = df_import_validate($fields, df_import_row_data($csvRow, $mapping));
        if ($rowErrors) {
            $result['invalid']++;
            $result['errors'][] = ['line'=>(int)$csvRow['line'],'errors'=>$rowErrors,'raw'=>$csvRow['values']];
            continue;
        }
        $result['valid']++;
        $key = df_import_duplicate_key($normalized, $duplicateFields);
        if ($key !== null && isset($seen[$key])) $result['duplicates']++;
        else { $result['new']++; if ($key !== null) $seen[$key] = ['id'=>0,'data'=>$normalized]; }
    }
    return $result;
}


function df_import_decode_object(?string $json): array
{
    if ($json === null || $json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function df_import_change_diff(array $before, array $after, array $fieldLabels): array
{
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    $diff = [];
    foreach ($keys as $key) {
        $old = (string)($before[$key] ?? '');
        $new = (string)($after[$key] ?? '');
        if ($old === $new) continue;
        $diff[] = [
            'field' => (string)$key,
            'label' => (string)($fieldLabels[$key] ?? $key),
            'before' => $old,
            'after' => $new,
        ];
    }
    return $diff;
}

try {
    $adminPdo = enterprise_pdo();
    enterprise_upgrade($adminPdo);
    $stmt = $adminPdo->prepare('SELECT * FROM projects WHERE id = ? AND product_type = ? LIMIT 1');
    $stmt->execute([$projectId, 'dataform']);
    $project = $stmt->fetch();
    if (!$project) throw new RuntimeException('Das ausgewählte DataForm-Projekt wurde nicht gefunden.');
    $_SESSION['active_project_id'] = $projectId;
    $importManifest=\EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::boot(dirname(__DIR__,2),$projectId,$dataformId);
    $importCaps=array_fill_keys(array_map('strval',(array)($importManifest['capabilities']??[])),true);
    if(!isset($importCaps['dataset.read'])) throw new RuntimeException('Lesen ist für dieses DataForm nicht lizenziert.');

    $env = enterprise_env(dirname(__DIR__, 2).'/DataForm5-Core/.env');
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_import_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        mapping_json LONGTEXT NOT NULL,
        duplicate_fields_json LONGTEXT NOT NULL,
        duplicate_action VARCHAR(20) NOT NULL DEFAULT 'skip',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_import_profile_name (dataform_id, user_id, name),
        INDEX idx_import_profiles_form_user (dataform_id, user_id),
        CONSTRAINT fk_import_profiles_form FOREIGN KEY (dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_import_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mapping_json LONGTEXT NOT NULL,
        duplicate_fields_json LONGTEXT NOT NULL,
        duplicate_action VARCHAR(20) NOT NULL,
        imported_count INT UNSIGNED NOT NULL DEFAULT 0,
        updated_count INT UNSIGNED NOT NULL DEFAULT 0,
        skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
        failed_count INT UNSIGNED NOT NULL DEFAULT 0,
        errors_json LONGTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'completed',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rolled_back_at DATETIME NULL,
        rolled_back_by BIGINT UNSIGNED NULL,
        INDEX idx_import_runs_form_created (dataform_id, created_at),
        CONSTRAINT fk_import_runs_form FOREIGN KEY (dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_import_changes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        import_run_id BIGINT UNSIGNED NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        change_type VARCHAR(20) NOT NULL,
        before_json LONGTEXT NULL,
        after_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_changes_run (import_run_id, id),
        CONSTRAINT fk_import_changes_run FOREIGN KEY (import_run_id) REFERENCES dataform_import_runs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->prepare('SELECT * FROM dataforms WHERE id = ? LIMIT 1');
    $stmt->execute([$dataformId]);
    $dataform = $stmt->fetch();
    if (!$dataform) throw new RuntimeException('Das ausgewählte DataForm wurde nicht gefunden.');
    $stmt = $pdo->prepare('SELECT * FROM dataform_fields WHERE dataform_id = ? ORDER BY position, id');
    $stmt->execute([$dataformId]);
    $fields = $stmt->fetchAll();
    $userId = (int)($user['id'] ?? 0);

    if (isset($_GET['download']) && $_GET['download'] === 'errors' && is_array($importResult)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="import-fehler-'.date('Ymd-His').'.csv"');
        $out = fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['CSV-Zeile','Fehler','Originaldaten'],';');
        foreach (($importResult['errors'] ?? []) as $row) fputcsv($out,[(int)$row['line'],implode(' | ',$row['errors']),json_encode($row['raw'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],';');
        fclose($out); exit;
    }


    $requestedRunId = (int)($_GET['run'] ?? $_GET['download_run'] ?? 0);
    if ($requestedRunId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM dataform_import_runs WHERE id = ? AND dataform_id = ? LIMIT 1');
        $stmt->execute([$requestedRunId, $dataformId]);
        $runDetail = $stmt->fetch();
        if (!$runDetail) throw new RuntimeException('Der angeforderte Importlauf wurde nicht gefunden.');
        $stmt = $pdo->prepare('SELECT * FROM dataform_import_changes WHERE import_run_id = ? ORDER BY id');
        $stmt->execute([$requestedRunId]);
        $runChanges = $stmt->fetchAll();

        if (isset($_GET['download']) && $_GET['download'] === 'protocol') {
            $fieldLabels = [];
            foreach ($fields as $field) $fieldLabels[(string)$field['name']] = (string)$field['label'];
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="importprotokoll-'.$requestedRunId.'-'.date('Ymd-His').'.csv"');
            $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Importlauf','Datei','Zeitpunkt','Status','Datensatz-ID','Änderungsart','Feld','Vorher','Nachher'], ';');
            if (!$runChanges) {
                fputcsv($out, [$requestedRunId,(string)$runDetail['filename'],(string)$runDetail['created_at'],(string)$runDetail['status'],'','','','',''], ';');
            }
            foreach ($runChanges as $change) {
                $before = df_import_decode_object($change['before_json'] ?? null);
                $after = df_import_decode_object((string)$change['after_json']);
                $diff = df_import_change_diff($before, $after, $fieldLabels);
                if (!$diff) $diff = [['label'=>'(keine Feldänderung)','before'=>'','after'=>'']];
                foreach ($diff as $item) fputcsv($out, [
                    $requestedRunId,(string)$runDetail['filename'],(string)$runDetail['created_at'],(string)$runDetail['status'],
                    (int)$change['record_id'],(string)$change['change_type'],(string)$item['label'],(string)$item['before'],(string)$item['after']
                ], ';');
            }
            fclose($out); exit;
        }
        if (isset($_GET['download']) && $_GET['download'] === 'run_errors') {
            $errors = df_import_decode_object($runDetail['errors_json'] ?? null);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="importfehler-'.$requestedRunId.'-'.date('Ymd-His').'.csv"');
            $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['CSV-Zeile','Fehler','Originaldaten'], ';');
            foreach ($errors as $row) fputcsv($out, [(int)($row['line'] ?? 0), implode(' | ', (array)($row['errors'] ?? [])), json_encode($row['raw'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)], ';');
            fclose($out); exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload_csv') {
            if (!$fields) throw new RuntimeException('Legen Sie zuerst mindestens ein DataForm-Feld an.');
            $file = $_FILES['csv_file'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Die CSV-Datei konnte nicht hochgeladen werden.');
            if ((int)$file['size'] > 5*1024*1024) throw new RuntimeException('Die CSV-Datei darf höchstens 5 MB groß sein.');
            if (strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Es sind ausschließlich Dateien mit der Endung .csv erlaubt.');
            $raw = file_get_contents((string)$file['tmp_name']);
            if ($raw === false || $raw === '') throw new RuntimeException('Die CSV-Datei ist leer oder nicht lesbar.');
            $raw = df_import_utf8($raw);
            $lines = preg_split('/\R/u',$raw) ?: [];
            $delimiter = df_import_delimiter((string)($lines[0] ?? ''));
            $handle = fopen('php://temp','w+b'); fwrite($handle,$raw); rewind($handle);
            $headers = fgetcsv($handle,0,$delimiter);
            if (!is_array($headers) || count($headers)<1) throw new RuntimeException('Die CSV-Kopfzeile konnte nicht gelesen werden.');
            $headers = array_map(static fn($v):string=>trim(df_import_utf8((string)$v)),$headers);
            if (count(array_unique($headers)) !== count($headers)) throw new RuntimeException('Die CSV-Kopfzeile enthält doppelte Spaltennamen.');
            $rows=[]; $lineNumber=1;
            while (($row=fgetcsv($handle,0,$delimiter))!==false) {
                $lineNumber++;
                if (count($rows)>=1000) throw new RuntimeException('Pro Import sind höchstens 1.000 Datenzeilen erlaubt.');
                if ($row===[null] || (count($row)===1 && trim((string)$row[0])==='')) continue;
                $rows[]=['line'=>$lineNumber,'values'=>array_map(static fn($v):string=>df_import_utf8((string)$v),$row)];
            }
            fclose($handle);
            if (!$rows) throw new RuntimeException('Die CSV-Datei enthält keine Datenzeilen.');
            $autoMap=[];
            foreach ($headers as $index=>$header) foreach ($fields as $field) {
                if (mb_strtolower(trim($header))===mb_strtolower((string)$field['name']) || mb_strtolower(trim($header))===mb_strtolower((string)$field['label'])) { $autoMap[(string)$index]=(string)$field['name']; break; }
            }
            $preview=['filename'=>(string)$file['name'],'delimiter'=>$delimiter,'headers'=>$headers,'rows'=>$rows,'mapping'=>$autoMap,'duplicate_fields'=>[],'duplicate_action'=>'skip','analysis'=>null,'created_at'=>date(DATE_ATOM)];
            $_SESSION['df_csv_import'][$projectId][$dataformId]=$preview;
            unset($_SESSION['df_csv_import_result'][$projectId][$dataformId]); $importResult=null;
            $success='CSV-Datei wurde gelesen. Prüfen Sie Feldzuordnung und Duplikatregeln.';
        } elseif ($action === 'apply_profile') {
            if (!is_array($preview)) throw new RuntimeException('Laden Sie zuerst eine CSV-Datei hoch.');
            $profileId=(int)($_POST['profile_id'] ?? 0);
            $stmt=$pdo->prepare('SELECT * FROM dataform_import_profiles WHERE id=? AND dataform_id=? AND user_id=?');
            $stmt->execute([$profileId,$dataformId,$userId]); $profile=$stmt->fetch();
            if (!$profile) throw new RuntimeException('Das Importprofil wurde nicht gefunden.');
            $storedMapping=json_decode((string)$profile['mapping_json'],true) ?: [];
            // Profile speichern Kopfzeile -> Feldname und bleiben dadurch auch bei geänderter Spaltenreihenfolge nutzbar.
            $mapping=[];
            foreach ($preview['headers'] as $i=>$header) if (isset($storedMapping[$header])) $mapping[(string)$i]=(string)$storedMapping[$header];
            $preview['mapping']=$mapping;
            $preview['duplicate_fields']=json_decode((string)$profile['duplicate_fields_json'],true) ?: [];
            $preview['duplicate_action']=(string)$profile['duplicate_action'];
            $preview['analysis']=null;
            $_SESSION['df_csv_import'][$projectId][$dataformId]=$preview;
            $success='Importprofil „'.$profile['name'].'“ wurde angewendet.';
        } elseif ($action === 'rollback_import') {
            if(!isset($importCaps['dataset.update'])||!isset($importCaps['dataset.delete'])) throw new RuntimeException('Die Rücknahme eines Imports ist mit der aktuellen CRUD-Lizenz nicht erlaubt.');
            $runId=(int)($_POST['run_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT * FROM dataform_import_runs WHERE id=? AND dataform_id=? FOR UPDATE');
            $stmt->execute([$runId,$dataformId]);
            $run=$stmt->fetch();
            if (!$run) throw new RuntimeException('Der Importlauf wurde nicht gefunden.');
            if ((string)$run['status']==='rolled_back') throw new RuntimeException('Dieser Import wurde bereits zurückgenommen.');
            $changesStmt=$pdo->prepare('SELECT * FROM dataform_import_changes WHERE import_run_id=? ORDER BY id DESC');
            $changesStmt->execute([$runId]);
            $changes=$changesStmt->fetchAll();
            $conflicts=[];
            foreach ($changes as $change) {
                $current=DataFormRecordStore::find($pdo,$dataformId,(int)$change['record_id']);
                if ($current===null) { $conflicts[]='Datensatz #'.(int)$change['record_id'].' fehlt.'; continue; }
                $currentCanonical=json_encode((array)$current['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $afterCanonical=json_encode(json_decode((string)$change['after_json'],true)?:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if ($currentCanonical!==$afterCanonical) $conflicts[]='Datensatz #'.(int)$change['record_id'].' wurde nach dem Import verändert.';
            }
            if ($conflicts) throw new RuntimeException('Rücknahme abgebrochen: '.implode(' ',array_slice($conflicts,0,10)));

            $mediaManager=new DataFormFieldStorageManager(dirname(__DIR__,2));
            $mediaFields=array_values(array_filter($fields,static fn(array $f):bool=>DataFormFieldTypeRegistry::isMedia((string)$f['field_type'])));
            foreach ($changes as $change) {
                $recordId=(int)$change['record_id'];
                $current=DataFormRecordStore::find($pdo,$dataformId,$recordId);
                $currentData=$current!==null?(array)$current['data']:[];
                $cleanup=[];
                foreach ($mediaFields as $field) {
                    $name=(string)$field['name']; $v=(string)($currentData[$name]??''); if ($v!=='') $cleanup[]=$v;
                }
                if ((string)$change['change_type']==='insert') {
                    DataFormRecordStore::delete($pdo,$dataformId,$recordId);
                } elseif ((string)$change['change_type']==='update') {
                    $before=df_import_decode_object($change['before_json']??null);
                    DataFormRecordStore::update($pdo,$dataformId,$recordId,$before);
                    // Only delete media introduced by the import. If the same
                    // descriptor existed before, it remains the restored value.
                    foreach ($mediaFields as $field) {
                        $name=(string)$field['name'];
                        $beforeValue=(string)($before[$name]??'');
                        $cleanup=array_values(array_filter($cleanup,static fn(string $v):bool=>$v!==$beforeValue));
                    }
                }
                DataFormTransport::cleanup($cleanup,$mediaManager);
            }
            $stmt=$pdo->prepare("UPDATE dataform_import_runs SET status='rolled_back', rolled_back_at=NOW(), rolled_back_by=? WHERE id=?");
            $stmt->execute([$userId,$runId]);
            $pdo->commit();
            $success='Importlauf #'.$runId.' wurde vollständig zurückgenommen.';
        } elseif ($action === 'save_profile' || $action === 'analyse_import' || $action === 'execute_import') {
            if (!is_array($preview)) throw new RuntimeException('Es liegt keine vorbereitete CSV-Datei vor.');
            $mapping=df_import_mapping(is_array($_POST['mapping'] ?? null)?$_POST['mapping']:[],$fields);
            $validFieldNames=array_column($fields,'name');
            $duplicateFieldNames=[];
            foreach ($fields as $field) {
                $type=(string)$field['field_type'];
                if (DataFormFieldTypeRegistry::isMedia($type) || in_array($type,['password','computed','json','link','coordinates','multiselect','tags','multi_lookup'],true)) continue;
                $duplicateFieldNames[]=(string)$field['name'];
            }
            $duplicateFields=array_values(array_unique(array_filter(array_map('strval',is_array($_POST['duplicate_fields'] ?? null)?$_POST['duplicate_fields']:[]),static fn(string $v):bool=>in_array($v,$duplicateFieldNames,true))));
            $duplicateAction=(string)($_POST['duplicate_action'] ?? 'skip');
            if (!in_array($duplicateAction,['skip','update','create'],true)) throw new RuntimeException('Die gewählte Duplikataktion ist ungültig.');
            if($action==='execute_import'){
                if(!isset($importCaps['dataset.create'])) throw new RuntimeException('Datensätze anlegen ist für dieses DataForm nicht lizenziert.');
                if($duplicateAction==='update'&&!isset($importCaps['dataset.update'])) throw new RuntimeException('Datensätze bearbeiten ist für dieses DataForm nicht lizenziert.');
            }
            if ($duplicateAction!=='create' && !$duplicateFields) throw new RuntimeException('Wählen Sie mindestens ein Vergleichsfeld für den Duplikatabgleich.');
            $preview['mapping']=$mapping; $preview['duplicate_fields']=$duplicateFields; $preview['duplicate_action']=$duplicateAction;

            if ($action === 'save_profile') {
                $name=trim((string)($_POST['profile_name'] ?? ''));
                if ($name==='' || mb_strlen($name)>120) throw new RuntimeException('Geben Sie einen Profilnamen mit höchstens 120 Zeichen ein.');
                $headerMapping=[];
                foreach ($mapping as $i=>$fieldName) $headerMapping[(string)($preview['headers'][(int)$i] ?? $i)]=$fieldName;
                $stmt=$pdo->prepare("INSERT INTO dataform_import_profiles (dataform_id,user_id,name,mapping_json,duplicate_fields_json,duplicate_action) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mapping_json=VALUES(mapping_json),duplicate_fields_json=VALUES(duplicate_fields_json),duplicate_action=VALUES(duplicate_action),updated_at=CURRENT_TIMESTAMP");
                $stmt->execute([$dataformId,$userId,$name,json_encode($headerMapping,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($duplicateFields),$duplicateAction]);
                $success='Importprofil „'.$name.'“ wurde gespeichert.';
            } else {
                $existingIndex=df_import_existing_index($pdo,$dataformId,$duplicateFields);
                $analysis=df_import_analyse($preview['rows'],$mapping,$fields,$duplicateFields,$existingIndex);
                $preview['analysis']=$analysis;
                if ($action === 'analyse_import') {
                    $success='Duplikatabgleich abgeschlossen. Prüfen Sie die Zusammenfassung vor dem Import.';
                } else {
                    $pdo->beginTransaction();
                    $importMediaManager=new DataFormFieldStorageManager(dirname(__DIR__,2));
                    $importCreatedMedia=[];
                    $headerMapping=[];
                    foreach ($mapping as $i=>$fieldName) $headerMapping[(string)($preview['headers'][(int)$i] ?? $i)]=$fieldName;
                    $runStmt=$pdo->prepare("INSERT INTO dataform_import_runs (dataform_id,user_id,filename,mapping_json,duplicate_fields_json,duplicate_action,status) VALUES (?,?,?,?,?,?,'running')");
                    $runStmt->execute([$dataformId,$userId,(string)$preview['filename'],json_encode($headerMapping,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($duplicateFields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$duplicateAction]);
                    $runId=(int)$pdo->lastInsertId();
                    $changeStmt=$pdo->prepare('INSERT INTO dataform_import_changes (import_run_id,record_id,change_type,before_json,after_json) VALUES (?,?,?,?,?)');
                    $imported=0; $updated=0; $skipped=0; $failed=[];
                    $runtimeIndex=$existingIndex;
                    foreach ($preview['rows'] as $csvRow) {
                        $rawData=df_import_row_data($csvRow,$mapping);
                        [$analysisNormalized,$rowErrors]=df_import_validate($fields,$rawData);
                        if ($rowErrors) { $failed[]=['line'=>(int)$csvRow['line'],'errors'=>$rowErrors,'raw'=>$csvRow['values']]; continue; }
                        $key=df_import_duplicate_key($analysisNormalized,$duplicateFields);
                        $match=($key!==null && isset($runtimeIndex[$key])) ? $runtimeIndex[$key] : null;
                        if ($match && $duplicateAction==='skip') { $skipped++; continue; }
                        try {
                            if ($match && $duplicateAction==='update') {
                                $norm=DataFormTransport::normalizeIncoming($fields,$rawData,(array)$match['data'],$importMediaManager,['project_id'=>$projectId,'dataform_id'=>$dataformId],true);
                                $normalized=$norm['data'];
                                $importCreatedMedia=array_merge($importCreatedMedia,$norm['created_media']);
                                $beforeJson=json_encode((array)$match['data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                                $afterJson=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                                DataFormRecordStore::update($pdo,$dataformId,(int)$match['id'],$normalized);
                                $changeStmt->execute([$runId,(int)$match['id'],'update',$beforeJson,$afterJson]);
                                $runtimeIndex[$key]=['id'=>(int)$match['id'],'data'=>$normalized];
                                $updated++;
                                continue;
                            }
                            $norm=DataFormTransport::normalizeIncoming($fields,$rawData,[],$importMediaManager,['project_id'=>$projectId,'dataform_id'=>$dataformId],false);
                            $normalized=$norm['data'];
                            $importCreatedMedia=array_merge($importCreatedMedia,$norm['created_media']);
                            $afterJson=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                            $newId=DataFormRecordStore::create($pdo,$dataformId,$normalized);
                            $changeStmt->execute([$runId,$newId,'insert',null,$afterJson]);
                            if ($key!==null && !isset($runtimeIndex[$key])) $runtimeIndex[$key]=['id'=>$newId,'data'=>$normalized];
                            $imported++;
                        } catch (Throwable $rowError) {
                            $failed[]=['line'=>(int)$csvRow['line'],'errors'=>[$rowError->getMessage()],'raw'=>$csvRow['values']];
                        }
                    }
                    $finishStmt=$pdo->prepare("UPDATE dataform_import_runs SET imported_count=?,updated_count=?,skipped_count=?,failed_count=?,errors_json=?,status='completed' WHERE id=?");
                    $finishStmt->execute([$imported,$updated,$skipped,count($failed),json_encode($failed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$runId]);
                    $pdo->commit();
                    $importCreatedMedia=[];
                    $importResult=['run_id'=>$runId,'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'failed'=>count($failed),'errors'=>$failed,'analysis'=>$analysis,'duplicate_action'=>$duplicateAction,'finished_at'=>date(DATE_ATOM)];
                    $_SESSION['df_csv_import_result'][$projectId][$dataformId]=$importResult;
                    $success=$imported.' neu, '.$updated.' aktualisiert, '.$skipped.' Duplikate übersprungen und '.count($failed).' fehlerhaft.';
                }
            }
            $_SESSION['df_csv_import'][$projectId][$dataformId]=$preview;
        } elseif ($action === 'delete_profile') {
            $stmt=$pdo->prepare('DELETE FROM dataform_import_profiles WHERE id=? AND dataform_id=? AND user_id=?');
            $stmt->execute([(int)($_POST['profile_id'] ?? 0),$dataformId,$userId]);
            $success='Importprofil wurde gelöscht.';
        } elseif ($action === 'reset_import') {
            unset($_SESSION['df_csv_import'][$projectId][$dataformId],$_SESSION['df_csv_import_result'][$projectId][$dataformId]);
            $preview=null; $importResult=null; $success='Der vorbereitete Import wurde verworfen.';
        }
    }

    $stmt=$pdo->prepare('SELECT id,name,mapping_json,duplicate_fields_json,duplicate_action,updated_at FROM dataform_import_profiles WHERE dataform_id=? AND user_id=? ORDER BY name');
    $stmt->execute([$dataformId,$userId]); $profiles=$stmt->fetchAll();
    $stmt=$pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM dataform_import_changes c WHERE c.import_run_id=r.id) AS change_count FROM dataform_import_runs r WHERE r.dataform_id=? ORDER BY r.id DESC LIMIT 50');
    $stmt->execute([$dataformId]); $history=$stmt->fetchAll();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($importCreatedMedia !== []) {
        try {
            $cleanupManager=$importMediaManager instanceof DataFormFieldStorageManager ? $importMediaManager : new DataFormFieldStorageManager(dirname(__DIR__,2));
            DataFormTransport::cleanup($importCreatedMedia,$cleanupManager);
        } catch (Throwable) {}
        $importCreatedMedia=[];
    }
    $error=$e->getMessage();
}

$selectedDuplicateFields=is_array($preview['duplicate_fields'] ?? null)?$preview['duplicate_fields']:[];
$selectedDuplicateAction=(string)($preview['duplicate_action'] ?? 'skip');
ob_start();
?>
<?php render_breadcrumbs([
 ['label'=>'Enterprise','href'=>'../../app/dashboard.php'],
 ['label'=>'Projekte','href'=>'../../app/projects/index.php'],
 ['label'=>(string)($project['name'] ?? 'DataForm'),'href'=>$project?'../../app/projects/view.php?id='.(int)$project['id']:''],
 ['label'=>'Workspace','href'=>$project?'index.php?project='.(int)$project['id'].'&section=dataforms':''],
 ['label'=>(string)($dataform['name'] ?? 'DataForm'),'href'=>'records.php?project='.$projectId.'&dataform='.$dataformId.'&mode=list'],
 ['label'=>'CSV-Import','href'=>''],
]); ?>
<div class="df-workspace records-workspace">
<header class="df-workspace-header"><div><span class="badge">DataForm Workspace</span><h1>CSV-Import</h1><p><?= e((string)($dataform['name'] ?? '')) ?></p></div><div class="df-workspace-actions"><a class="button secondary" href="records.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;mode=list">Zur Datensatzliste</a></div></header>
<div class="df-editor-content records-content">
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice success"><?= e($success) ?></div><?php endif; ?>

<section class="card import-step">
<h2>Importhistorie</h2>
<p>Die letzten 50 Importläufe dieses DataForms. Eine Rücknahme löscht neu importierte Datensätze und stellt aktualisierte Datensätze auf den Zustand vor dem Import zurück.</p>
<?php if (!$history): ?><p>Noch keine protokollierten Importläufe vorhanden.</p><?php else: ?>
<div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>Lauf</th><th>Datei / Zeitpunkt</th><th>Ergebnis</th><th>Status</th><th>Aktion</th></tr></thead><tbody>
<?php foreach ($history as $run): ?><tr><td><strong>#<?= (int)$run['id'] ?></strong></td><td><?= e((string)$run['filename']) ?><br><small><?= e((string)$run['created_at']) ?></small></td><td><?= (int)$run['imported_count'] ?> neu · <?= (int)$run['updated_count'] ?> aktualisiert · <?= (int)$run['skipped_count'] ?> übersprungen · <?= (int)$run['failed_count'] ?> fehlerhaft</td><td><?php if ((string)$run['status']==='rolled_back'): ?><span class="badge">zurückgenommen</span><br><small><?= e((string)$run['rolled_back_at']) ?></small><?php else: ?><span class="badge">abgeschlossen</span><?php endif; ?></td><td><div class="actions"><a class="button secondary" href="import.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;run=<?= (int)$run['id'] ?>#import-run-detail">Details</a><?php if ((string)$run['status']==='completed' && (int)$run['change_count']>0): ?><form method="post" class="inline-form" onsubmit="return confirm('Importlauf #<?= (int)$run['id'] ?> vollständig zurücknehmen? Neu angelegte Datensätze werden gelöscht und Aktualisierungen werden wiederhergestellt.');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="rollback_import"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>"><button class="button secondary" <?= easyit_button_attributes('rueckgaengig','import') ?> type="submit">Zurücknehmen</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>

<?php if (is_array($runDetail)): ?>
<section class="card import-step" id="import-run-detail">
<h2>Importlauf #<?= (int)$runDetail['id'] ?> – Detailprotokoll</h2>
<p><strong>Datei:</strong> <?= e((string)$runDetail['filename']) ?> · <strong>Zeitpunkt:</strong> <?= e((string)$runDetail['created_at']) ?> · <strong>Status:</strong> <?= e((string)$runDetail['status']) ?></p>
<div class="actions">
<a class="button secondary" <?= easyit_button_attributes('verlauf') ?> href="import.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;run=<?= (int)$runDetail['id'] ?>&amp;download=protocol">Änderungsprotokoll als CSV</a>
<?php if ((int)$runDetail['failed_count'] > 0): ?><a class="button secondary" <?= easyit_button_attributes('warnung') ?> href="import.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;run=<?= (int)$runDetail['id'] ?>&amp;download=run_errors">Fehlerbericht als CSV</a><?php endif; ?>
<a class="button secondary" <?= easyit_button_attributes('schliessen') ?> href="import.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>">Details schließen</a>
</div>
<dl class="summary-grid">
<div><dt>Neu</dt><dd><?= (int)$runDetail['imported_count'] ?></dd></div><div><dt>Aktualisiert</dt><dd><?= (int)$runDetail['updated_count'] ?></dd></div><div><dt>Übersprungen</dt><dd><?= (int)$runDetail['skipped_count'] ?></dd></div><div><dt>Fehlerhaft</dt><dd><?= (int)$runDetail['failed_count'] ?></dd></div>
</dl>
<?php if (!$runChanges): ?><p>Dieser Importlauf enthält keine gespeicherten Datensatzänderungen.</p><?php else: ?>
<?php $fieldLabels=[]; foreach ($fields as $field) $fieldLabels[(string)$field['name']] = (string)$field['label']; ?>
<div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>Datensatz</th><th>Art</th><th>Feldänderungen</th></tr></thead><tbody>
<?php foreach ($runChanges as $change): $before=df_import_decode_object($change['before_json'] ?? null); $after=df_import_decode_object((string)$change['after_json']); $diff=df_import_change_diff($before,$after,$fieldLabels); ?>
<tr><td>#<?= (int)$change['record_id'] ?></td><td><?= (string)$change['change_type']==='insert'?'neu':'aktualisiert' ?></td><td><?php if (!$diff): ?>keine sichtbare Feldänderung<?php else: ?><ul class="compact-list"><?php foreach ($diff as $item): ?><li><strong><?= e((string)$item['label']) ?>:</strong> <code><?= e((string)$item['before']) ?></code> → <code><?= e((string)$item['after']) ?></code></li><?php endforeach; ?></ul><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>

<section class="card import-step">
<h2>1. CSV-Datei auswählen</h2>
<p>Die erste Zeile muss Spaltennamen enthalten. Unterstützt werden Semikolon, Komma, senkrechter Strich und Tabulator.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="upload_csv"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>">
<label>CSV-Datei<input type="file" name="csv_file" accept=".csv,text/csv" required></label>
<p><button class="button" type="submit">Datei prüfen und Vorschau erzeugen</button></p>
</form>
</section>

<?php if (is_array($preview)): ?>
<?php if ($profiles): ?>
<section class="card import-step"><h2>2. Importprofil verwenden</h2><p>Ein Profil übernimmt Feldzuordnung und Duplikatregeln anhand der CSV-Kopfzeilen.</p>
<?php foreach ($profiles as $profile): ?><div class="actions" style="margin-bottom:.5rem"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="apply_profile"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="profile_id" value="<?= (int)$profile['id'] ?>"><button class="button secondary" type="submit"><?= e((string)$profile['name']) ?> anwenden</button></form><form method="post" class="inline-form" onsubmit="return confirm('Importprofil wirklich löschen?');"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="delete_profile"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="profile_id" value="<?= (int)$profile['id'] ?>"><button class="button secondary" type="submit">Löschen</button></form></div><?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card import-step">
<h2><?= $profiles?'3':'2' ?>. Feldzuordnung und Duplikatabgleich</h2>
<p><strong>Datei:</strong> <?= e((string)$preview['filename']) ?> · <strong>Datenzeilen:</strong> <?= count($preview['rows']) ?> · <strong>Trennzeichen:</strong> <code><?= e($preview['delimiter']==="\t"?'Tabulator':(string)$preview['delimiter']) ?></code></p>
<form method="post" id="import-settings-form">
<input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>">
<div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>CSV-Spalte</th><th>DataForm-Feld</th><th>Beispielwert</th></tr></thead><tbody>
<?php foreach ($preview['headers'] as $index=>$header): ?><tr><td><strong><?= e((string)$header) ?></strong></td><td><select name="mapping[<?= (int)$index ?>]"><option value="">Nicht importieren</option><?php foreach($fields as $field): $selected=(string)($preview['mapping'][(string)$index]??'')===(string)$field['name']; ?><option value="<?= e((string)$field['name']) ?>" <?= $selected?'selected':'' ?>><?= e((string)$field['label']) ?> (<?= e((string)$field['name']) ?>)<?= (int)$field['is_required']===1?' *':'' ?></option><?php endforeach; ?></select></td><td><?= e(mb_strimwidth((string)($preview['rows'][0]['values'][$index]??''),0,100,'…')) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<h3>Duplikate erkennen</h3>
<p>Wählen Sie Felder, deren gemeinsame Werte einen vorhandenen Datensatz eindeutig identifizieren, zum Beispiel Kundennummer oder E-Mail-Adresse.</p>
<div class="checkbox-grid"><?php foreach($fields as $field): $duplicateType=(string)$field['field_type']; if (DataFormFieldTypeRegistry::isMedia($duplicateType) || in_array($duplicateType,['password','computed','json','link','coordinates','multiselect','tags','multi_lookup'],true)) continue; ?><label><input type="checkbox" name="duplicate_fields[]" value="<?= e((string)$field['name']) ?>" <?= in_array((string)$field['name'],$selectedDuplicateFields,true)?'checked':'' ?>> <?= e((string)$field['label']) ?> <code><?= e((string)$field['name']) ?></code></label><?php endforeach; ?></div>
<label>Aktion bei Duplikaten<select name="duplicate_action"><option value="skip" <?= $selectedDuplicateAction==='skip'?'selected':'' ?>>Vorhandenen Datensatz überspringen</option><option value="update" <?= $selectedDuplicateAction==='update'?'selected':'' ?>>Vorhandenen Datensatz aktualisieren</option><option value="create" <?= $selectedDuplicateAction==='create'?'selected':'' ?>>Trotzdem neuen Datensatz anlegen</option></select></label>
<div class="notice warning"><strong>Sicherheit:</strong> Für „aktualisieren“ sollten nur wirklich eindeutige Vergleichsfelder gewählt werden. Leere Vergleichswerte erzeugen keinen Duplikattreffer.</div>

<h3>Importprofil speichern</h3>
<div class="actions"><label style="min-width:18rem">Profilname<input type="text" name="profile_name" maxlength="120" placeholder="z. B. Kundenexport Warenwirtschaft"></label><button class="button secondary" type="submit" name="action" value="save_profile">Profil speichern</button></div>

<h3>Vorschau der ersten fünf Datenzeilen</h3>
<div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>CSV-Zeile</th><?php foreach($preview['headers'] as $header): ?><th><?= e((string)$header) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach(array_slice($preview['rows'],0,5) as $row): ?><tr><td><?= (int)$row['line'] ?></td><?php foreach($preview['headers'] as $i=>$header): ?><td><?= e(mb_strimwidth((string)($row['values'][$i]??''),0,80,'…')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
<p class="actions"><button class="button" type="submit" name="action" value="analyse_import">Duplikate prüfen</button></p>

<?php if (is_array($preview['analysis'] ?? null)): $a=$preview['analysis']; ?>
<div class="notice success"><strong>Abgleich abgeschlossen:</strong> Der Import wurde noch nicht ausgeführt.</div>
<div class="metric-grid"><div class="metric"><strong><?= (int)$a['new'] ?></strong><span>neu</span></div><div class="metric"><strong><?= (int)$a['duplicates'] ?></strong><span>Duplikate</span></div><div class="metric"><strong><?= (int)$a['invalid'] ?></strong><span>fehlerhaft</span></div><div class="metric"><strong><?= (int)$a['valid'] ?></strong><span>gültig</span></div></div>
<p class="actions"><button class="button" type="submit" name="action" value="execute_import" onclick="return confirm('Import mit der gewählten Duplikatregel jetzt ausführen?');">Import jetzt ausführen</button></p>
<?php endif; ?>
</form>
<form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="reset_import"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><button class="button secondary" type="submit">Vorbereitung verwerfen</button></form>
</section>
<?php endif; ?>

<?php if (is_array($importResult)): ?>
<section class="card import-result"><h2>Importergebnis</h2>
<div class="metric-grid"><div class="metric"><strong><?= (int)$importResult['imported'] ?></strong><span>neu angelegt</span></div><div class="metric"><strong><?= (int)$importResult['updated'] ?></strong><span>aktualisiert</span></div><div class="metric"><strong><?= (int)$importResult['skipped'] ?></strong><span>übersprungen</span></div><div class="metric"><strong><?= (int)$importResult['failed'] ?></strong><span>fehlerhaft</span></div></div>
<?php if ((int)$importResult['failed']>0): ?><p><a class="button secondary" href="?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;download=errors">Fehlerbericht als CSV herunterladen</a></p><div class="df-field-table-wrap"><table class="df-field-table"><thead><tr><th>CSV-Zeile</th><th>Fehler</th></tr></thead><tbody><?php foreach(array_slice($importResult['errors'],0,20) as $row): ?><tr><td><?= (int)$row['line'] ?></td><td><?= e(implode(' ',$row['errors'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<p><a class="button" href="records.php?project=<?= $projectId ?>&amp;dataform=<?= $dataformId ?>&amp;mode=list">Datensätze anzeigen</a></p></section>
<?php endif; ?>
</div>
<footer class="df-statusbar"><span>Projekt: <strong><?= e((string)($project['name']??'')) ?></strong></span><span>DataForm: <strong><?= e((string)($dataform['name']??'')) ?></strong></span><span>Version: <strong>RC1.2.9-dev</strong></span></footer>
</div>
<?php
$content=ob_get_clean();
render_page([
 'title'=>'CSV-Import – '.($dataform['name']??'DataForm'),'active'=>'projects','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'body_class'=>'workspace-page',
 'help'=>[
  'title'=>'CSV-Import','location'=>'Enterprise → Projekt → DataForm → CSV-Import',
  'short'=>'Jeder Importlauf wird protokolliert und kann transaktional vollständig zurückgenommen werden.',
  'goal'=>'Importe lückenlos nachvollziehen, Feldänderungen vergleichen, Protokolle exportieren und Importe sicher zurücknehmen.',
  'next'=>'Importprofil prüfen, Daten testen und den gewünschten Import ausführen.',
  'steps'=>['CSV-Datei hochladen.','Optional ein Importprofil anwenden.','Feldzuordnung prüfen.','Eindeutige Vergleichsfelder auswählen.','Duplikate prüfen.','Erst nach der Zusammenfassung importieren.'],
  'tips'=>['Jeder Importlauf erhält eine eindeutige Laufnummer.','Neu angelegte und aktualisierte Datensätze werden getrennt protokolliert.','Die Rücknahme wird vollständig abgebrochen, sobald ein betroffener Datensatz nachträglich verändert wurde.','Ein bereits zurückgenommener Import kann nicht erneut zurückgenommen werden.']
 ]
]);
