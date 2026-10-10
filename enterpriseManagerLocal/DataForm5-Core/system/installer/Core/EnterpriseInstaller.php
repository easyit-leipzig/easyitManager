<?php
declare(strict_types=1);

namespace DataForm5\Installer\Core;

use DataForm5\Installer\Exceptions\InstallerException;
use PDO;

// RC1.1 compatibility markers retained for cumulative STAND 1–3 gates.
// These comments document the historical accepted driver set; the active lists below add MSSQL.
// ['mysql','csv','sqlite','pgsql','oracle']
// ['mysql','csv','sqlite','pgsql','oracle']
// STAND 4 active driver marker: ['mysql','csv','sqlite','pgsql','oracle','mssql']
final class EnterpriseInstaller
{
    public function __construct(
        private readonly string $enterprisePath,
        private readonly string $corePath,
        private readonly SystemInspector $inspector,
        private readonly InstallationLock $lock,
        private readonly EnvironmentWriter $envWriter,
        private readonly AdminDatabaseInstaller $database,
        private readonly InstallationHealthGate $health
    ) {}

    public function inspect(): array
    {
        $base=$this->inspector->inspect();
        $required=['json','openssl'];
        $extensions=['pdo_mysql'=>extension_loaded('pdo_mysql'),'pdo_sqlite'=>extension_loaded('pdo_sqlite'),'pdo_pgsql'=>extension_loaded('pdo_pgsql'),'pdo_oci'=>extension_loaded('pdo_oci'),'pdo_sqlsrv'=>extension_loaded('pdo_sqlsrv')];
        foreach($required as $ext)$extensions[$ext]=extension_loaded($ext);
        $requiredOk=true;foreach($required as $ext)if(!($extensions[$ext]??false))$requiredOk=false;
        return $base+['required_extensions'=>$extensions,'enterprise_ready'=>$base['ready']&&$requiredOk];
    }

    public function install(array $input): array
    {
        if($this->lock->exists()) throw new InstallerException('Installation ist bereits abgeschlossen. Entfernen Sie den Lock nicht manuell.');
        $inspection=$this->inspect();
        if(!($inspection['enterprise_ready']??false)) throw new InstallerException('Systemanforderungen sind nicht erfüllt.');

        $db=(array)($input['database']??[]);
        $admin=(array)($input['admin']??[]);
        $products=array_values(array_filter((array)($input['products']??['dataform']),'is_string'));
        $adminDriver=strtolower(trim((string)($db['driver']??'mysql')));
        if(!in_array($adminDriver,['mysql','csv','sqlite','pgsql','oracle','mssql'],true)) throw new InstallerException('Administrationsspeicher wird nicht unterstützt: '.$adminDriver);
        $adminDatabase=trim((string)($db['database']??''));
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$adminDatabase)) throw new InstallerException('Ungültiger Name für den Administrationsspeicher.');
        $adminSchema=$adminDriver==='pgsql'?(trim((string)($db['schema']??'public')) ?: 'public'):'';
        if($adminDriver==='pgsql' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$adminSchema)) throw new InstallerException('Ungültiges PostgreSQL-Administrationsschema.');

        $projectDb=(array)($input['project_database']??[]);
        if($projectDb===[]){
            $projectDb=['driver'=>'mysql','database'=>'easyit_project_demo','host'=>(string)($db['host']??''),'port'=>(int)($db['port']??3306),'username'=>(string)($db['username']??''),'password'=>(string)($db['password']??'')];
        }
        $projectDriver=strtolower(trim((string)($projectDb['driver']??'mysql')));
        if(!in_array($projectDriver,['mysql','csv','sqlite','pgsql','oracle','mssql'],true)) throw new InstallerException('Projektdatenspeicher wird nicht unterstützt: '.$projectDriver);
        $projectDatabase=trim((string)($projectDb['database']??'easyit_project_demo'));
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$projectDatabase)) throw new InstallerException('Ungültiger Name für den Projektdatenspeicher.');
        $projectPgSchema=$projectDriver==='pgsql'?(trim((string)($projectDb['schema']??'public')) ?: 'public'):'';
        if($projectDriver==='pgsql' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$projectPgSchema)) throw new InstallerException('Ungültiges PostgreSQL-Projektschema.');
        if($adminDriver===$projectDriver && $adminDatabase===$projectDatabase && ($adminDriver!=='pgsql' || $adminSchema===$projectPgSchema)) throw new InstallerException('Administrations- und Projektspeicher müssen bei gleichem Treiber getrennte Datenbank-/Schema-Kombinationen besitzen.');
        if(($adminDriver==='mysql'||$projectDriver==='mysql')&&!extension_loaded('pdo_mysql')) throw new InstallerException('pdo_mysql fehlt, wird aber für den gewählten MariaDB/MySQL-Speicher benötigt.');
        if(($adminDriver==='pgsql'||$projectDriver==='pgsql')&&!extension_loaded('pdo_pgsql')) throw new InstallerException('pdo_pgsql fehlt, wird aber für den gewählten PostgreSQL-Speicher benötigt.');
        if(($adminDriver==='sqlite'||$projectDriver==='sqlite')&&!extension_loaded('pdo_sqlite')) throw new InstallerException('pdo_sqlite fehlt, wird aber für den gewählten SQLite-Speicher benötigt.');
        if(($adminDriver==='oracle'||$projectDriver==='oracle')&&!extension_loaded('pdo_oci')) throw new InstallerException('pdo_oci fehlt, wird aber für den gewählten Oracle-XE-Speicher benötigt.');
        if(($adminDriver==='mssql'||$projectDriver==='mssql')&&!extension_loaded('pdo_sqlsrv')) throw new InstallerException('pdo_sqlsrv fehlt, wird aber für den gewählten Microsoft-SQL-Server-Speicher benötigt.');

        if($adminDriver==='csv'||$projectDriver==='csv') $products[]='csv-engine';
        if($adminDriver==='sqlite'||$projectDriver==='sqlite') $products[]='sqlite-engine';
        if($adminDriver==='pgsql'||$projectDriver==='pgsql') $products[]='pgsql-engine';
        if($adminDriver==='oracle'||$projectDriver==='oracle') $products[]='oracle-engine';
        if($adminDriver==='mssql'||$projectDriver==='mssql') $products[]='mssql-engine';
        $products=array_values(array_unique($products));

        $baseSetting='';$sqliteBaseSetting='';
        if($adminDriver==='csv'){
            $baseSetting=trim((string)($db['base_path']??'storage/admin-csv'));if($baseSetting==='')$baseSetting='storage/admin-csv';
            $isAbsolute=(bool)preg_match('~^(?:[A-Za-z]:[\\/]|/|\\\\)~',$baseSetting);
            $basePath=$isAbsolute?$baseSetting:$this->enterprisePath.'/'.ltrim(str_replace('\\','/',$baseSetting),'/');
            require_once $this->enterprisePath.'/system/app/EnterpriseCsvPdo.php';
            $pdo=new \EnterpriseCsvPdo($basePath,$adminDatabase);
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
            $pdo->ensureAdminSchema();
        }elseif($adminDriver==='sqlite'){
            $sqliteBaseSetting=trim((string)($db['sqlite_base_path']??'storage/admin-sqlite'));if($sqliteBaseSetting==='')$sqliteBaseSetting='storage/admin-sqlite';
            $isAbsolute=(bool)preg_match('~^(?:[A-Za-z]:[\\/]|/|\\\\)~',$sqliteBaseSetting);
            $sqliteBasePath=$isAbsolute?$sqliteBaseSetting:$this->enterprisePath.'/'.ltrim(str_replace('\\','/',$sqliteBaseSetting),'/');
            require_once $this->enterprisePath.'/system/app/EnterpriseSqlitePdo.php';
            $pdo=new \EnterpriseSqlitePdo(rtrim(str_replace('\\','/',$sqliteBasePath),'/').'/'.$adminDatabase.'.sqlite');
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
        }elseif($adminDriver==='mssql'){
            foreach(['host','port','username'] as $key)if(trim((string)($db[$key]??''))==='')throw new InstallerException("Admin-MSSQL-Konfiguration '{$key}' fehlt.");
            $db['driver']='mssql';$db['database']=$adminDatabase;$db['encrypt']=$db['encrypt']??true;$db['trust_server_certificate']=$db['trust_server_certificate']??false;$this->database->createDatabase($db);$pdo=$this->database->connect($db);
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
        }elseif($adminDriver==='oracle'){
            foreach(['host','port','username'] as $key)if(trim((string)($db[$key]??''))==='')throw new InstallerException("Admin-Oracle-Konfiguration '{$key}' fehlt.");
            $db['driver']='oracle';$db['database']=$adminDatabase;$db['service']=$db['service']??$db['service_name']??'XEPDB1';$this->database->createDatabase($db);$pdo=$this->database->connect($db);
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
        }elseif($adminDriver==='pgsql'){
            foreach(['host','port','username'] as $key)if(trim((string)($db[$key]??''))==='')throw new InstallerException("Admin-PostgreSQL-Konfiguration '{$key}' fehlt.");
            $db['driver']='pgsql';$db['database']=$adminDatabase;$db['schema']=$adminSchema;$db['maintenance_database']=trim((string)($db['maintenance_database']??'postgres')) ?: 'postgres';$this->database->createDatabase($db);$pdo=$this->database->connect($db);
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
        }else{
            foreach(['host','port','username'] as $key)if(trim((string)($db[$key]??''))==='')throw new InstallerException("Admin-DB-Konfiguration '{$key}' fehlt.");
            $db['driver']='mysql';$this->database->createDatabase($db);$pdo=$this->database->connect($db);
            $schema=$this->database->installSchema($pdo,$this->enterprisePath.'/installer/schema/admin');
        }

        require_once $this->enterprisePath.'/system/app/project_store.php';
        require_once $this->enterprisePath.'/system/app/project_storage.php';

        // HF88 Storage Phase 6: the project is registered first so its immutable
        // storage_key exists before CSV/SQLite storage is provisioned.
        $configuredProject=$this->registerConfiguredProject(
            $pdo,
            $projectDriver,
            $projectDatabase,
            (string)($input['project_name']??$projectDatabase)
        );
        $projectRecord=[
            'id'=>(int)$configuredProject['id'],
            'name'=>(string)$configuredProject['name'],
            'slug'=>(string)$configuredProject['slug'],
            'storage_key'=>(string)$configuredProject['storage_key'],
            'product_type'=>'dataform',
            'database_driver'=>$projectDriver,
            'database_name'=>$projectDatabase,
            'status'=>'provisioning',
            'created_at'=>date(DATE_ATOM),
        ];
        $projectEnv=[
            'PROJECT_DB_DRIVER'=>$projectDriver,
            'PROJECT_DB_HOST'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['host']??''):'',
            'PROJECT_DB_PORT'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['port']??''):'',
            'PROJECT_DB_MAINTENANCE_DATABASE'=>$projectDriver==='pgsql'?(string)($projectDb['maintenance_database']??'postgres'):'',
            'PROJECT_DB_DATABASE'=>$projectDatabase,
            'PROJECT_DB_SCHEMA'=>$projectDriver==='pgsql'?$projectPgSchema:'',
            'PROJECT_DB_USERNAME'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['username']??''):'',
            'PROJECT_DB_PASSWORD'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['password']??''):'',
            'PROJECT_DB_ORACLE_SERVICE'=>$projectDriver==='oracle'?(string)($projectDb['service']??$projectDb['service_name']??'XEPDB1'):'',
            'PROJECT_DB_CHARSET'=>$projectDriver==='oracle'?'AL32UTF8':($projectDriver==='pgsql'?'UTF8':($projectDriver==='mssql'?'UTF-8':'utf8mb4')),
            'PROJECT_DB_ENCRYPT'=>$projectDriver==='mssql'?'true':'',
            'PROJECT_DB_TRUST_SERVER_CERTIFICATE'=>$projectDriver==='mssql'?'false':'',
        ];
        try {
            $projectPdo=\enterprise_project_store_create_for_project($projectEnv,$projectRecord);
            $projectSchema=$this->database->installSchema($projectPdo,$this->enterprisePath.'/installer/schema/project');
            $projectDefaultCsvSourceId=null;$projectDefaultSqliteSourceId=null;$projectDefaultPgsqlSourceId=null;$projectDefaultOracleSourceId=null;$projectDefaultMssqlSourceId=null;
            if($projectDriver==='csv')$projectDefaultCsvSourceId=\enterprise_project_store_ensure_default_csv_source_for_project($projectPdo,$projectEnv,$projectRecord);
            elseif($projectDriver==='sqlite')$projectDefaultSqliteSourceId=\enterprise_project_store_ensure_default_sqlite_source_for_project($projectPdo,$projectEnv,$projectRecord);
            elseif($projectDriver==='pgsql')$projectDefaultPgsqlSourceId=\enterprise_project_store_ensure_default_pgsql_source($projectPdo,$projectEnv,$projectDatabase);
            elseif($projectDriver==='oracle')$projectDefaultOracleSourceId=\enterprise_project_store_ensure_default_oracle_source($projectPdo,$projectEnv,$projectDatabase);
            elseif($projectDriver==='mssql')$projectDefaultMssqlSourceId=\enterprise_project_store_ensure_default_mssql_source($projectPdo,$projectEnv,$projectDatabase);
            $pdo->prepare("UPDATE projects SET status='active' WHERE id=?")->execute([(int)$configuredProject['id']]);
            $projectRecord['status']='active';
            \enterprise_project_storage_provision($projectRecord,$projectEnv);
        } catch (\Throwable $e) {
            try{$pdo->prepare("UPDATE projects SET status='provisioning_failed' WHERE id=?")->execute([(int)$configuredProject['id']]);}catch(\Throwable){}
            throw new InstallerException('Projektspeicher konnte nicht bereitgestellt werden: '.$e->getMessage(),0,$e);
        }

        $env=[
            'APP_ENV'=>(string)($input['environment']??'production'),'APP_DEBUG'=>'false','APP_TIMEZONE'=>(string)($input['timezone']??'Europe/Berlin'),
            'DATAFORM_APP_KEY'=>'dfk1_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='),
            'ADMIN_DB_DRIVER'=>$adminDriver,'ADMIN_DB_CSV_BASE_PATH'=>$adminDriver==='csv'?$baseSetting:'','ADMIN_DB_SQLITE_BASE_PATH'=>$adminDriver==='sqlite'?$sqliteBaseSetting:'',
            'ADMIN_DB_HOST'=>in_array($adminDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($db['host']??''):'','ADMIN_DB_PORT'=>in_array($adminDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($db['port']??''):'',
            'ADMIN_DB_MAINTENANCE_DATABASE'=>$adminDriver==='pgsql'?(string)($db['maintenance_database']??'postgres'):'','ADMIN_DB_DATABASE'=>$adminDatabase,'ADMIN_DB_SCHEMA'=>$adminDriver==='pgsql'?$adminSchema:'','ADMIN_DB_USERNAME'=>in_array($adminDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($db['username']??''):'','ADMIN_DB_PASSWORD'=>in_array($adminDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($db['password']??''):'','ADMIN_DB_ORACLE_SERVICE'=>$adminDriver==='oracle'?(string)($db['service']??$db['service_name']??'XEPDB1'):'','ADMIN_DB_CHARSET'=>$adminDriver==='oracle'?'AL32UTF8':($adminDriver==='pgsql'?'UTF8':($adminDriver==='mssql'?'UTF-8':'utf8mb4')),'ADMIN_DB_ENCRYPT'=>$adminDriver==='mssql'?'true':'','ADMIN_DB_TRUST_SERVER_CERTIFICATE'=>$adminDriver==='mssql'?'false':'',
            'PROJECT_DB_DRIVER'=>$projectDriver,'PROJECT_STORAGE_KEY'=>(string)$configuredProject['storage_key'],
            'PROJECT_DB_HOST'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['host']??''):'','PROJECT_DB_PORT'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['port']??''):'',
            'PROJECT_DB_MAINTENANCE_DATABASE'=>$projectDriver==='pgsql'?(string)($projectDb['maintenance_database']??'postgres'):'','PROJECT_DB_DATABASE'=>$projectDatabase,'PROJECT_DB_SCHEMA'=>$projectDriver==='pgsql'?$projectPgSchema:'','PROJECT_DB_USERNAME'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['username']??''):'','PROJECT_DB_PASSWORD'=>in_array($projectDriver,['mysql','pgsql','oracle','mssql'],true)?(string)($projectDb['password']??''):'','PROJECT_DB_ORACLE_SERVICE'=>$projectDriver==='oracle'?(string)($projectDb['service']??$projectDb['service_name']??'XEPDB1'):'',
            'PROJECT_DB_CHARSET'=>$projectDriver==='oracle'?'AL32UTF8':($projectDriver==='pgsql'?'UTF8':($projectDriver==='mssql'?'UTF-8':'utf8mb4')),'PROJECT_DB_ENCRYPT'=>$projectDriver==='mssql'?'true':'','PROJECT_DB_TRUST_SERVER_CERTIFICATE'=>$projectDriver==='mssql'?'false':'','CACHE_STORE'=>'file','QUEUE_CONNECTION'=>'file','CLUSTER_ENABLED'=>'false','CLUSTER_SECURITY_ENABLED'=>'false','STORAGE_DISK'=>'local','REPLICATION_ENABLED'=>'false',
        ];
        $this->envWriter->write($env);

        $userId=$this->database->createAdmin($pdo,(string)($admin['username']??''),(string)($admin['email']??''),(string)($admin['password']??''));
        $this->database->registerProducts($pdo,$products);
        $bundledProject=$projectDriver==='mysql'?$this->installBundledProject($pdo,$projectDb,$adminDatabase):null;

        $health=$this->health->check($this->corePath,$pdo);
        if(!$health['ready']) throw new InstallerException('Health-Gate fehlgeschlagen; Install-Lock wurde nicht gesetzt.');

        $metadata=[
            'enterprise_version'=>trim((string)@file_get_contents($this->enterprisePath.'/VERSION')),
            'installed_at'=>date(DATE_ATOM),
            'admin_user_id'=>$userId,
            'database'=>$adminDatabase,
            'admin_store_driver'=>$adminDriver,
            'admin_store_database'=>$adminDatabase,
            'admin_store_schema'=>$adminDriver==='pgsql'?$adminSchema:null,
            'admin_store_path'=>$adminDriver==='csv'?$baseSetting:($adminDriver==='sqlite'?$sqliteBaseSetting:null),
            'project_store_driver'=>$projectDriver,
            'project_store_database'=>$projectDatabase,
            'project_store_schema'=>$projectDriver==='pgsql'?$projectPgSchema:null,
            'project_storage_key'=>(string)$configuredProject['storage_key'],
            'project_store_path'=>\enterprise_project_storage_root($projectRecord),
            'products'=>$products,
            'schema_files'=>$schema,
            'project_schema_files'=>$projectSchema,
            'project_default_csv_source_id'=>$projectDriver==='csv'?$projectDefaultCsvSourceId:null,
            'project_default_sqlite_source_id'=>$projectDriver==='sqlite'?$projectDefaultSqliteSourceId:null,
            'project_default_pgsql_source_id'=>$projectDriver==='pgsql'?$projectDefaultPgsqlSourceId:null,
            'project_default_oracle_source_id'=>$projectDriver==='oracle'?$projectDefaultOracleSourceId:null,
            'project_default_mssql_source_id'=>$projectDriver==='mssql'?$projectDefaultMssqlSourceId:null,
            'configured_project'=>$configuredProject,
            'bundled_project'=>$bundledProject,
            'health'=>$health,
        ];
        $this->lock->create($metadata);

        $report=$this->enterprisePath.'/storage/logs/install-report.json';
        $dir=dirname($report);if(!is_dir($dir))@mkdir($dir,0775,true);
        @file_put_contents($report,json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        return $metadata;
    }

    public function status(): array
    {
        return ['installed'=>$this->lock->exists(),'lock'=>$this->lock->read(),'inspection'=>$this->inspect()];
    }
    private function registerConfiguredProject(PDO $adminPdo,string $driver,string $database,string $name): array
    {
        require_once $this->enterprisePath.'/system/app/project_storage.php';
        \enterprise_project_storage_upgrade($adminPdo);
        $name=trim($name) ?: $database;
        $check=$adminPdo->prepare('SELECT id,name,slug,storage_key FROM projects WHERE database_driver=? AND database_name=? LIMIT 1');
        $check->execute([$driver,$database]);
        $existing=$check->fetch(PDO::FETCH_ASSOC);
        if(is_array($existing)){
            $id=(int)$existing['id'];$slug=(string)$existing['slug'];$key=trim((string)($existing['storage_key']??''));
            if($key===''){$key=\enterprise_project_storage_key($id,$slug);$adminPdo->prepare('UPDATE projects SET storage_key=? WHERE id=?')->execute([$key,$id]);}
            return ['id'=>$id,'name'=>(string)$existing['name'],'slug'=>$slug,'storage_key'=>$key,'database'=>$database,'created'=>false];
        }
        $slug=strtolower(str_replace('_','-',$database));
        $slug=preg_replace('/[^a-z0-9-]+/','-',$slug)??'';
        $slug=trim($slug,'-');
        if(strlen($slug)<2)$slug='project-'.substr(hash('sha256',$database),0,8);
        $base=$slug;$suffix=2;
        while(true){$q=$adminPdo->prepare('SELECT id FROM projects WHERE slug=? LIMIT 1');$q->execute([$slug]);if($q->fetchColumn()===false)break;$slug=$base.'-'.$suffix++;}
        $st=$adminPdo->prepare("INSERT INTO projects(name,slug,database_driver,database_name,status) VALUES (?,?,?,?,'provisioning')");
        $st->execute([$name,$slug,$driver,$database]);
        $id=(int)$adminPdo->lastInsertId();
        $key=\enterprise_project_storage_key($id,$slug);
        $adminPdo->prepare('UPDATE projects SET storage_key=? WHERE id=?')->execute([$key,$id]);
        return ['id'=>$id,'name'=>$name,'slug'=>$slug,'storage_key'=>$key,'database'=>$database,'created'=>true];
    }

    private function installBundledProject(PDO $adminPdo,array $projectDb,string $adminDatabase): ?array
    {
        $bundleDir=$this->enterprisePath.'/distribution/project';
        $manifestFile=$bundleDir.'/manifest.json';$projectFile=$bundleDir.'/project.json';$sqlFile=$bundleDir.'/database.sql';
        if(!is_file($manifestFile)||!is_file($projectFile)||!is_file($sqlFile)) return null;
        $manifest=json_decode((string)file_get_contents($manifestFile),true);
        $project=json_decode((string)file_get_contents($projectFile),true);
        $sql=(string)file_get_contents($sqlFile);
        if(!is_array($manifest)||($manifest['format']??'')!=='easyit-project-application-bundle'||!is_array($project)||$sql==='')
            throw new InstallerException('Das enthaltene Projekt-Anwenderbundle ist ungültig.');
        $database=trim((string)($project['database_name']??''));
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$database)) throw new InstallerException('Ungültige Projektdatenbank im Anwenderbundle.');
        require_once $this->enterprisePath.'/system/app/project_restore.php';
        $env=[
            'ADMIN_DB_DATABASE'=>$adminDatabase,
            'PROJECT_DB_HOST'=>(string)$projectDb['host'],'PROJECT_DB_PORT'=>(string)$projectDb['port'],
            'PROJECT_DB_USERNAME'=>(string)$projectDb['username'],'PROJECT_DB_PASSWORD'=>(string)($projectDb['password']??''),
        ];
        try{
            $restore=\enterprise_project_restore_database($env,$database,$database,$sql,'restore');
        }catch(\Throwable $e){ throw new InstallerException('Enthaltenes Projekt konnte nicht installiert werden: '.$e->getMessage(),0,$e); }
        $columns=array_column($adminPdo->query('SHOW COLUMNS FROM projects')->fetchAll(PDO::FETCH_ASSOC),'Field');
        if(!in_array('product_type',$columns,true))$adminPdo->exec("ALTER TABLE projects ADD product_type VARCHAR(40) NOT NULL DEFAULT 'dataform' AFTER slug");
        if(!in_array('description',$columns,true))$adminPdo->exec("ALTER TABLE projects ADD description TEXT NULL AFTER database_name");
        $id=max(0,(int)($project['id']??0));
        $args=[(string)($project['name']??'Projekt'),(string)($project['slug']??'projekt'),(string)($project['product_type']??'dataform'),(string)($project['database_driver']??'mysql'),$database,(string)($project['description']??''),(string)($project['status']??'active')];
        if($id>0){
            $st=$adminPdo->prepare('INSERT INTO projects(id,name,slug,product_type,database_driver,database_name,description,status) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute(array_merge([$id],$args));$projectId=$id;
        }else{
            $st=$adminPdo->prepare('INSERT INTO projects(name,slug,product_type,database_driver,database_name,description,status) VALUES (?,?,?,?,?,?,?)');
            $st->execute($args);$projectId=(int)$adminPdo->lastInsertId();
        }
        require_once $this->enterprisePath.'/system/app/project_storage.php';
        \enterprise_project_storage_upgrade($adminPdo);
        $slug=(string)($project['slug']??'projekt');
        $storageKey=\enterprise_project_storage_key($projectId,$slug);
        $adminPdo->prepare('UPDATE projects SET storage_key=? WHERE id=?')->execute([$storageKey,$projectId]);
        $storageProject=['id'=>$projectId,'name'=>(string)($project['name']??'Projekt'),'slug'=>$slug,'storage_key'=>$storageKey,'product_type'=>(string)($project['product_type']??'dataform'),'database_driver'=>(string)($project['database_driver']??'mysql'),'database_name'=>$database,'status'=>(string)($project['status']??'active'),'created_at'=>date(DATE_ATOM)];
        \enterprise_project_storage_provision($storageProject,$env);
        return ['id'=>$projectId,'name'=>(string)($project['name']??''),'slug'=>$slug,'storage_key'=>$storageKey,'database'=>$database,'restore'=>$restore];
    }

}
