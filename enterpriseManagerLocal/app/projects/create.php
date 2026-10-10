<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once dirname(__DIR__, 2) . '/system/app/project_store.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.create');
$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
$pdo = null;
$adminStoreError = '';
try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $projectProducts = enterprise_available_project_products($pdo,$user);
} catch (Throwable $e) {
    $adminStoreError = $e->getMessage();
}
$error = '';
$messages = [];
if (!isset($projectProducts) || !is_array($projectProducts)) $projectProducts = [];
$pdoDrivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
$storageDrivers = [
    'csv'=>['label'=>'CSV-Engine','available'=>true],
    'sqlite'=>['label'=>'SQLite','available'=>in_array('sqlite',$pdoDrivers,true)],
    'mysql'=>['label'=>'MariaDB / MySQL','available'=>in_array('mysql',$pdoDrivers,true)],
    'pgsql'=>['label'=>'PostgreSQL','available'=>in_array('pgsql',$pdoDrivers,true)],
    'mssql'=>['label'=>'Microsoft SQL Server','available'=>in_array('sqlsrv',$pdoDrivers,true)],
];
$storageDrivers = array_filter($storageDrivers, static fn(array $d): bool => $d['available']);
$form = [
    'name' => '',
    'slug' => '',
    'product_type' => 'dataform',
    'database_driver' => enterprise_project_store_driver($env ?? []),
    'database_name' => '',
];

function project_provision_valid_db_name(string $name): bool
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/', $name) === 1;
}
function project_provision_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
function project_provision_server(array $env): PDO
{
    foreach (['PROJECT_DB_HOST','PROJECT_DB_PORT','PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') {
            throw new RuntimeException("Projekt-DB-Konfiguration {$key} fehlt. Führen Sie zuerst Setup-Schritt 6 aus.");
        }
    }
    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}
function project_provision_database_exists(PDO $server, string $database): bool
{
    $st = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
    $st->execute([$database]);
    return $st->fetchColumn() !== false;
}
function project_provision_create_database(PDO $server, string $database): void
{
    $q = project_provision_quote($database);
    try {
        $server->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), 'collation')) {
            $server->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4");
        } else {
            throw $e;
        }
    }
    if (!project_provision_database_exists($server, $database)) {
        throw new RuntimeException('Die neue Projektdatenbank konnte nach CREATE DATABASE nicht verifiziert werden.');
    }
}
function project_provision_migration_table_exists(PDO $db): bool
{
    return (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='migrations'")->fetchColumn() > 0;
}
function project_provision_migration_record(PDO $db, string $name): ?array
{
    if (!project_provision_migration_table_exists($db)) return null;
    $st=$db->prepare('SELECT migration,checksum FROM migrations WHERE migration=? LIMIT 1');
    $st->execute([$name]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}
function project_provision_register_migration(PDO $db, string $name, string $checksum): void
{
    if (!project_provision_migration_table_exists($db)) throw new RuntimeException("Migrationstabelle fehlt nach {$name}.");
    $st=$db->prepare('INSERT INTO migrations(migration,checksum) VALUES (?,?) ON DUPLICATE KEY UPDATE checksum=checksum');
    $st->execute([$name,$checksum]);
}
function project_provision_install_schema(PDO $server, string $database, string $directory): array
{
    $server->exec('USE ' . project_provision_quote($database));
    $files=glob(rtrim($directory,'/\\').'/*.php')?:[];
    sort($files,SORT_NATURAL);
    if ($files===[]) throw new RuntimeException('Keine Projekt-Schema-Dateien gefunden.');
    $done=[];
    foreach($files as $file){
        $name=basename($file);
        $checksum=hash_file('sha256',$file);
        if(!is_string($checksum)||strlen($checksum)!==64) throw new RuntimeException("Checksum für {$name} konnte nicht bestimmt werden.");
        $existing=project_provision_migration_record($server,$name);
        if($existing!==null){
            if(!hash_equals((string)$existing['checksum'],$checksum)) throw new RuntimeException("Migration {$name} wurde verändert.");
            $done[]='SKIP '.$name.' (Checksum OK)';
            continue;
        }
        $installer=require $file;
        if(!is_callable($installer)) throw new RuntimeException('Ungültige Schema-Datei: '.$name);
        $installer($server);
        project_provision_register_migration($server,$name,$checksum);
        $done[]='APPLY '.$name;
    }
    return $done;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException($adminStoreError !== '' ? $adminStoreError : 'Der Administrationsspeicher ist nicht verfügbar. Führen Sie zuerst das Setup durch.');
        }
        foreach(array_keys($form) as $key) $form[$key]=trim((string)($_POST[$key] ?? $form[$key]));

        if ($form['name']==='') throw new RuntimeException('Projektname ist erforderlich.');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,158}$/',$form['slug'])) throw new RuntimeException('Der technische Slug ist ungültig.');
        if (!isset($projectProducts[$form['product_type']])) throw new RuntimeException('Das gewählte Produkt ist nicht installiert, nicht verfügbar oder nicht lizenziert.');
        if (!isset($storageDrivers[$form['database_driver']])) throw new RuntimeException('Der gewählte Datenbank-/Speichertyp ist auf diesem System nicht verfügbar.');
        if (!project_provision_valid_db_name($form['database_name'])) throw new RuntimeException('Der Datenbankname ist ungültig.');

        $dup=$pdo->prepare('SELECT id,name,database_name FROM projects WHERE slug=? OR database_name=? LIMIT 1');
        $dup->execute([$form['slug'],$form['database_name']]);
        if($existing=$dup->fetch()) throw new RuntimeException('Slug oder Datenbankname wird bereits vom Projekt „'.(string)$existing['name'].'“ verwendet.');

        $projectDriver=$form['database_driver'];
        if(!in_array($projectDriver,['mysql','csv','sqlite','pgsql','mssql'],true)) throw new RuntimeException('Der gewählte Projektdatenspeicher wird nicht unterstützt: '.$projectDriver);
        if(!in_array($projectDriver,['csv','sqlite'],true) && enterprise_project_store_exists($env,$form['database_name'],$projectDriver)) {
            throw new RuntimeException('Der Projektdatenspeicher `'.$form['database_name'].'` existiert bereits. Verwenden Sie „Vorhandenes Projekt registrieren“ oder wählen Sie einen neuen Namen.');
        }

        $created=false;
        $id=0;
        $storageRoot='';
        $project=[];
        try {
            // HF88 Phase 2: Projekt-ID und stabilen Storage-Key zuerst erzeugen.
            // Lokale CSV/SQLite-Speicher können dadurch direkt im Projektordner provisioniert werden.
            $stmt=$pdo->prepare("INSERT INTO projects(name,slug,product_type,database_driver,database_name,status) VALUES (?,?,?,?,?,'provisioning')");
            $stmt->execute([$form['name'],$form['slug'],$form['product_type'],$projectDriver,$form['database_name']]);
            $id=(int)$pdo->lastInsertId();
            $storageKey=enterprise_project_storage_assign($pdo,$id,$form['slug']);
            $project=enterprise_project_storage_fetch($pdo,$id);
            $storageRoot=enterprise_project_storage_provision($project,$env);

            if(enterprise_project_store_exists_for_project($env,$project)) {
                throw new RuntimeException('Der Projektdatenspeicher `'.$form['database_name'].'` existiert bereits im Projekt-Storage.');
            }

            $projectPdo=enterprise_project_store_create_for_project($env,$project);
            $created=true;
            $schemaResults=[];
            if($form['product_type']==='dataform'){
                $schemaResults=enterprise_project_store_install_schema($projectPdo,dirname(__DIR__,2).'/installer/schema/project');
            } elseif($form['product_type']==='calendar'){
                // HF99: Calendar ist ein datenführendes Produkt. Die Grundstruktur wird
                // bereits beim Anlegen des Enterprise-Projekts in genau dem gewählten
                // Projektdatenspeicher erzeugt; ein späterer manueller Setup-Schritt ist
                // dafür nicht mehr erforderlich.
                require_once dirname(__DIR__,2).'/system/app/calendar_project.php';
                require_once dirname(__DIR__,2).'/products/calendar/api/runtime.php';
                require_once dirname(__DIR__,2).'/products/calendar/system/CalendarDataFormBridge.php';
                $calendarCtx=[
                    'project'=>$project,
                    'env'=>$env,
                    'config'=>enterprise_calendar_config_load($project),
                ];
                $calendarIntegration=easyit_calendar_dataform_install($projectPdo,$calendarCtx);
                foreach((array)($calendarIntegration['calendar_schema']['created']??[]) as $calendarTable){
                    $schemaResults[]='CALENDAR CREATE '.$calendarTable;
                }
                foreach((array)($calendarIntegration['schema']??[]) as $line)$schemaResults[]='DATAFORM '.$line;
                $calendarConfig=$calendarCtx['config'];
                foreach((array)($calendarIntegration['bindings']??[]) as $logical=>$binding)$calendarConfig['dataforms'][$logical]=(int)($binding['id']??0);
                $calendarConfig['term_dialog']['dataform_id']=(int)($calendarConfig['dataforms']['events']??0);
                $calendarConfig['term_dialog']['use_dataform']=true;
                enterprise_calendar_config_save($project,$calendarConfig);
                $schemaResults[]='CALENDAR + DATAFORM INTEGRATION READY ['.(string)($calendarIntegration['calendar_schema']['driver']??$projectDriver).']';
                foreach((array)($calendarIntegration['warnings']??[]) as $warning)$schemaResults[]='CALENDAR DATAFORM WARNING '.$warning;
            }
            if($projectDriver==='csv'){
                $sourceId=enterprise_project_store_ensure_default_csv_source_for_project($projectPdo,$env,$project);
                $schemaResults[]='DEFAULT CSV DATA SOURCE #'.$sourceId;
            }elseif($projectDriver==='sqlite'){
                $sourceId=enterprise_project_store_ensure_default_sqlite_source_for_project($projectPdo,$env,$project);
                $schemaResults[]='DEFAULT SQLITE DATA SOURCE #'.$sourceId;
            }
            elseif($projectDriver==='pgsql'){
                $sourceId=enterprise_project_store_ensure_default_pgsql_source($projectPdo,$env,$form['database_name']);
                $schemaResults[]='DEFAULT POSTGRESQL DATA SOURCE #'.$sourceId;
            }

            if(!enterprise_project_store_exists_for_project($env,$project)) throw new RuntimeException('Der neu angelegte Projektdatenspeicher konnte nicht verifiziert werden.');
            $active=$pdo->prepare("UPDATE projects SET status='active' WHERE id=?");$active->execute([$id]);
            $project=enterprise_project_storage_fetch($pdo,$id);
            enterprise_project_storage_provision($project,$env);

            enterprise_audit($pdo,(int)$user['id'],'project.provision','project',(string)$id,[
                'name'=>$form['name'],'slug'=>$form['slug'],'product_type'=>$form['product_type'],
                'database_driver'=>$projectDriver,'database_name'=>$form['database_name'],'database_created'=>true,'storage_key'=>$storageKey,'storage_root'=>$storageRoot,'schema'=>$schemaResults,
            ]);
            enterprise_event_dispatch('project.provisioned',[
                'project_id'=>$id,'name'=>$form['name'],'slug'=>$form['slug'],'product_type'=>$form['product_type'],
                'database_driver'=>$projectDriver,'database_name'=>$form['database_name'],
            ],['user_id'=>(int)$user['id']]);

            header('Location: view.php?id='.$id);
            exit;
        } catch(Throwable $e) {
            if($created && is_array($project) && $project!==[]){ try{ enterprise_project_store_delete_for_project($env,$project); }catch(Throwable){} }
            if($storageRoot!=='' && is_dir($storageRoot)){ try{ enterprise_project_storage_remove_tree($storageRoot); }catch(Throwable){} }
            if($id>0){ try{$del=$pdo->prepare('DELETE FROM projects WHERE id=?');$del->execute([$id]);}catch(Throwable){} }
            throw $e;
        }
    }
} catch(Throwable $e) {
    $error=$e->getMessage();
}

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Projekte','href'=>'index.php'],['label'=>'Neues Projekt anlegen','href'=>'']]);
?>
<section class="hero"><span class="badge">HF11 · Project Provisioning</span><h1>Neues Projekt anlegen</h1>
<p>Hier wird zuerst das Produkt und anschließend die Datenquelle des Projekts festgelegt. Es werden nur Produkte mit lokaler Installation/Lizenz und nur auf diesem PHP-System verfügbare Datenbanktreiber angeboten.</p>
<div class="actions"><a class="button secondary" href="register.php">Vorhandenes Projekt registrieren</a><a class="button secondary" href="restore.php">Projektsicherung wiederherstellen</a></div></section>
<?php if($adminStoreError !== ''):?>
<div class="notice error" role="alert">
<strong>Administrationsspeicher nicht verfügbar.</strong><br>
<?=e($adminStoreError)?>
<div class="actions" style="margin-top:1rem">
<a class="button" <?= easyit_button_attributes('einstellungen','setup') ?> href="../../setup.php">Setup durchführen</a>
<a class="button secondary" <?= easyit_button_attributes('datenbank_assistent') ?> href="../../installer/database.php">Datenbank-Assistent öffnen</a>
<a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">Zur Projektliste</a>
</div>
</div>
<?php endif;?>
<?php if($error):?><div class="notice error" role="alert"><?=e($error)?></div><?php endif;?>
<?php if($pdo instanceof PDO):?>
<section class="card"><form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?=e(enterprise_csrf())?>">
<label>Projektname<input name="name" required value="<?=e($form['name'])?>" placeholder="Testprojekt 63"></label>
<label>Technischer Slug<input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,158}" value="<?=e($form['slug'])?>" placeholder="testprojekt-63"><small>Kleinbuchstaben, Ziffern und Bindestriche.</small></label>
<label>Produkt / Projektart<select name="product_type" required><?php foreach($projectProducts as $product): ?><option value="<?=e($product['key'])?>" <?= $form['product_type']===$product['key'] ? 'selected' : '' ?>><?=e($product['label'])?></option><?php endforeach; ?></select><small>Nur installierte und lizenzierte produktive Produkte werden angeboten.</small></label>
<label>Datenbank / Speicher<select name="database_driver" id="database_driver" required><?php foreach($storageDrivers as $key=>$driver): ?><option value="<?=e($key)?>" <?= $form['database_driver']===$key ? 'selected' : '' ?>><?=e($driver['label'])?></option><?php endforeach; ?></select><small>PDO-Treiber, die in PHP fehlen, werden nicht angeboten.</small></label>
<label>Datenbank-/Speichername<input name="database_name" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}" value="<?=e($form['database_name'])?>" placeholder="easyit_testprojekt_63"><small>Bei CSV/SQLite wird dieser Name innerhalb des eindeutigen Projekt-Storage verwendet.</small></label>
<div class="form-span notice" id="db-settings"><strong>Aktive Verbindungseinstellungen:</strong> <span id="db-settings-text"></span><br><small>Serverzugänge werden zentral unter „Lokale Administration“ gepflegt; das Projekt speichert Datenbanktyp und Datenbank-/Speichername. PostgreSQL verwendet zusätzlich das konfigurierte Schema.</small></div>
<div class="form-span notice"><strong>Ablauf:</strong> Projektdatenspeicher erzeugen → Projektschema installieren → Projekt registrieren → eindeutigen Projekt-Storage mit csv/sqlite/pgsql/documents anlegen. Bei einem Fehler vor Abschluss wird die neu erzeugte Datenbank wieder entfernt.</div>
<div class="form-span button-row"><button class="button" type="submit">Projekt vollständig anlegen</button><a class="button secondary" href="index.php">Abbrechen</a></div>
</form></section>
<script>
(() => {
 const sel=document.getElementById('database_driver'), out=document.getElementById('db-settings-text');
 const cfg={
  csv:'Projektlokaler CSV-Ordner im Storage (csv/).',
  sqlite:'Projektlokale SQLite-Datei im Storage (sqlite/).',
  mysql:<?=json_encode('Host '.($env['PROJECT_DB_HOST']??'—').' · Port '.($env['PROJECT_DB_PORT']??'3306').' · Benutzer '.($env['PROJECT_DB_USERNAME']??'—'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,
  pgsql:<?=json_encode('Host '.($env['PROJECT_DB_HOST']??'—').' · Port '.($env['PROJECT_DB_PORT']??'5432').' · Datenbank wird neu angelegt · Schema '.($env['PROJECT_DB_SCHEMA']??'public').' · Benutzer '.($env['PROJECT_DB_USERNAME']??'—'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,
  mssql:<?=json_encode('Host '.($env['PROJECT_DB_HOST']??'—').' · Port '.($env['PROJECT_DB_PORT']??'1433').' · Benutzer '.($env['PROJECT_DB_USERNAME']??'—'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>
 };
 function render(){out.textContent=cfg[sel.value]||'—';}
 sel.addEventListener('change',render); render();
})();
</script>
<?php endif;?>
<?php
$content=ob_get_clean();
render_page(['title'=>'Neues Projekt anlegen','active'=>'projects','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>[
'title'=>'Projekt-Provisionierung','location'=>'Enterprise → Projekte → Neues Projekt anlegen','short'=>'Neue easyIT-Projekte mit auswählbarem installiertem Produkt und eigenem Projektspeicher anlegen.','goal'=>'Projekt ohne manuellen Datenbank-Assistenten vollständig provisionieren.','steps'=>['Projektname und Slug festlegen.','Neuen Datenbanknamen wählen.','Projekt vollständig anlegen.','Ergebnis in Projektliste und Datenbankserver prüfen.'],'tips'=>['Existierende Datenbanken werden nicht überschrieben.','Für bestehende Datenbanken verwenden Sie „Vorhandenes Projekt registrieren“.','Die Registrierung erfolgt erst nach erfolgreicher Schema-Installation.']
]]);
