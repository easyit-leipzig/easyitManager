<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Database;

use PDO;

final class MigrationRunner
{
    public const FULL_SCHEMA_MIGRATION = 'HF115_SHARED_CONTROL_PLANE_REBUILD_V1';

    /**
     * Vollständiger Tabellenvertrag des Lizenzservers. Das Setup darf erst
     * gesperrt/freigegeben werden, wenn jede dieser Tabellen real existiert.
     */
    public const REQUIRED_TABLES = [
        'license_schema_migrations','customers','licenses','modules','license_modules',
        'capability_registry','license_policies','license_capabilities','license_dataform_usage',
        'license_dataforms','license_dataform_capabilities','installations','installation_keys',
        'activation_events','dataform_definitions','runtime_sessions','runtime_actions','used_nonces',
        'audit_events','admin_users','customer_allowed_domains','customer_allowed_networks',
        'customer_users','customer_roles','customer_role_capabilities','customer_user_roles',
        'customer_role_resource_scopes','customer_role_projects','customer_role_dataforms',
        'customer_user_resource_scopes','customer_user_projects','customer_user_dataforms',
        'customer_access_requests','customer_license_requests','product_catalog',
        'project_product_requests','project_product_entitlements','customer_account_sessions',
        'installation_server_key_trust',
        // Enterprise-Manager control plane in the same central easyit_license DB.
        'migrations','roles','users','user_roles','projects','audit_log',
        'installed_products','enterprise_module_settings','enterprise_module_secrets',
        'capabilities','role_capabilities','enterprise_module_migrations','enterprise_licenses',
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * HF115 Setup-Neuaufbau: Der Setup-Vorgang ist absichtlich destruktiv.
     * Existierende Benutzertabellen der ausgewaehlten Datenbank bzw. des
     * aktiven PostgreSQL-Schemas werden vollstaendig geloescht und das
     * gemeinsame Lizenzserver-/Enterprise-Control-Plane anschliessend von Null neu provisioniert.
     *
     * @return array{required:int,present:int,missing:array<int,string>,tables:array<int,string>,migration:string,dropped_tables:int}
     */
    public function rebuild(): array
    {
        $dropped=$this->dropAllTables();
        $status=$this->migrate();
        $status['dropped_tables']=$dropped;
        return $status;
    }

    private function dropAllTables(): int
    {
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables=[];

        if($driver==='mysql'){
            $rows=$this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $tables=array_values(array_filter(array_map('strval',$rows?:[])));
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try{
                foreach($tables as $table)$this->pdo->exec('DROP TABLE IF EXISTS '.$this->quoteMysqlIdentifier($table));
            }finally{
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            return count($tables);
        }

        if($driver==='pgsql'){
            $rows=$this->pdo->query('SELECT tablename FROM pg_tables WHERE schemaname=current_schema()')->fetchAll(PDO::FETCH_COLUMN);
            $tables=array_values(array_filter(array_map('strval',$rows?:[])));
            foreach($tables as $table)$this->pdo->exec('DROP TABLE IF EXISTS '.$this->quotePgIdentifier($table).' CASCADE');
            return count($tables);
        }

        if($driver==='sqlite'){
            $rows=$this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $tables=array_values(array_filter(array_map('strval',$rows?:[])));
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            try{
                foreach($tables as $table)$this->pdo->exec('DROP TABLE IF EXISTS '.$this->quoteSqliteIdentifier($table));
            }finally{
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
            return count($tables);
        }

        throw new \RuntimeException('DATASOURCE_DRIVER_NOT_SUPPORTED_FOR_REBUILD: '.$driver);
    }

    private function quoteMysqlIdentifier(string $value): string
    {
        return '`'.str_replace('`','``',$value).'`';
    }

    private function quotePgIdentifier(string $value): string
    {
        return '"'.str_replace('"','""',$value).'"';
    }

    private function quoteSqliteIdentifier(string $value): string
    {
        return '"'.str_replace('"','""',$value).'"';
    }

    /**
     * Provisioniert das vollständige Schema idempotent und validiert danach
     * gegen den realen Datenbankbestand. Ein partielles Schema ist ein harter
     * Setup-Fehler und erzeugt keine Setup-Sperre.
     *
     * @return array{required:int,present:int,missing:array<int,string>,tables:array<int,string>,migration:string}
     */
    public function migrate(): array
    {
        foreach ($this->statements() as $sql) $this->pdo->exec($sql);
        $this->ensureCustomerColumns();
        $this->ensureCustomerKeyModel();
        $this->ensureDataformRegistryColumns();
        $this->seedModules();
        $this->seedCapabilities();
        $this->seedProductCatalog();
        // HF115: easyit_license is the shared central administration database.
        // A destructive license-server setup must therefore also recreate the
        // Enterprise Manager control-plane tables (projects/users/roles/etc.).
        EnterpriseControlPlaneSchema::migrate($this->pdo);

        $status=$this->schemaStatus();
        if($status['missing']){
            throw new \RuntimeException('LICENSE_SCHEMA_INCOMPLETE: '.implode(',',$status['missing']));
        }
        $this->recordMigration(self::FULL_SCHEMA_MIGRATION);
        $status['migration']=self::FULL_SCHEMA_MIGRATION;
        return $status;
    }

    /** @return array{required:int,present:int,missing:array<int,string>,tables:array<int,string>} */
    public function schemaStatus(): array
    {
        $missing=[];$present=[];
        foreach(self::REQUIRED_TABLES as $table){
            if($this->tableExists($table))$present[]=$table;else $missing[]=$table;
        }
        return [
            'required'=>count(self::REQUIRED_TABLES),
            'present'=>count($present),
            'missing'=>$missing,
            'tables'=>$present,
        ];
    }

    private function tableExists(string $table): bool
    {
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $st=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $st->execute([$table]);return (int)$st->fetchColumn()>0;
        }
        if($driver==='pgsql'){
            $st=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=?');
            $st->execute([$table]);return (int)$st->fetchColumn()>0;
        }
        if($driver==='sqlite'){
            $st=$this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$table]);return (int)$st->fetchColumn()>0;
        }
        try{$this->pdo->query('SELECT 1 FROM '.$table.' WHERE 1=0');return true;}catch(\Throwable){return false;}
    }

    private function recordMigration(string $code): void
    {
        $find=$this->pdo->prepare('SELECT migration_code FROM license_schema_migrations WHERE migration_code=?');
        $find->execute([$code]);
        if($find->fetchColumn()){
            $up=$this->pdo->prepare('UPDATE license_schema_migrations SET applied_at=? WHERE migration_code=?');
            $up->execute([time(),$code]);
            return;
        }
        $ins=$this->pdo->prepare('INSERT INTO license_schema_migrations (migration_code,applied_at) VALUES (?,?)');
        $ins->execute([$code,time()]);
    }

    private function statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS license_schema_migrations (migration_code VARCHAR(100) PRIMARY KEY, applied_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS customers (customer_id VARCHAR(64) PRIMARY KEY, customer_number VARCHAR(64) NOT NULL UNIQUE, customer_key VARCHAR(255) NULL, name VARCHAR(255) NOT NULL, company VARCHAR(255) NULL, email VARCHAR(255) NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS licenses (license_id VARCHAR(64) PRIMARY KEY, license_number VARCHAR(64) NOT NULL UNIQUE, activation_secret_hash VARCHAR(255) NOT NULL, customer_id VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, valid_from BIGINT NULL, valid_until BIGINT NULL, max_installations INTEGER NOT NULL, lease_seconds INTEGER NOT NULL, grace_seconds INTEGER NOT NULL, generation BIGINT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS modules (module_code VARCHAR(100) PRIMARY KEY, name VARCHAR(255) NOT NULL, requires_runtime INTEGER NOT NULL, active INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS license_modules (license_id VARCHAR(64) NOT NULL, module_code VARCHAR(100) NOT NULL, enabled INTEGER NOT NULL, valid_from BIGINT NULL, valid_until BIGINT NULL, configuration TEXT NULL, PRIMARY KEY (license_id,module_code))",
            "CREATE TABLE IF NOT EXISTS capability_registry (capability_code VARCHAR(120) PRIMARY KEY, name VARCHAR(255) NOT NULL, category VARCHAR(80) NOT NULL, active INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS license_policies (license_id VARCHAR(64) PRIMARY KEY, max_dataforms INTEGER NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS license_capabilities (license_id VARCHAR(64) NOT NULL, capability_code VARCHAR(120) NOT NULL, enabled INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (license_id,capability_code))",
            "CREATE TABLE IF NOT EXISTS license_dataform_usage (license_id VARCHAR(64) NOT NULL, project_id VARCHAR(100) NOT NULL, dataform_id VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, first_used_at BIGINT NULL, last_used_at BIGINT NULL, released_at BIGINT NULL, PRIMARY KEY (license_id,project_id,dataform_id))",
            "CREATE TABLE IF NOT EXISTS license_dataforms (dataform_uuid VARCHAR(36) PRIMARY KEY, license_id VARCHAR(64) NOT NULL, customer_id VARCHAR(64) NOT NULL, installation_id VARCHAR(100) NOT NULL, project_id VARCHAR(100) NOT NULL, local_dataform_id VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, registered_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL, approved_at BIGINT NULL, revoked_at BIGINT NULL, UNIQUE(license_id,project_id,local_dataform_id))",
            "CREATE TABLE IF NOT EXISTS license_dataform_capabilities (license_id VARCHAR(64) NOT NULL, project_id VARCHAR(100) NOT NULL, dataform_id VARCHAR(100) NOT NULL, capability_code VARCHAR(120) NOT NULL, enabled INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY (license_id,project_id,dataform_id,capability_code))",
            "CREATE TABLE IF NOT EXISTS installations (installation_id VARCHAR(100) PRIMARY KEY, license_id VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, generation BIGINT NOT NULL, product_code VARCHAR(100) NOT NULL, product_version VARCHAR(50) NULL, environment_hash VARCHAR(128) NULL, activated_at BIGINT NOT NULL, last_seen_at BIGINT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS installation_keys (key_id VARCHAR(100) PRIMARY KEY, installation_id VARCHAR(100) NOT NULL, algorithm VARCHAR(50) NOT NULL, public_key TEXT NOT NULL, status VARCHAR(20) NOT NULL, valid_from BIGINT NOT NULL, valid_until BIGINT NULL, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS activation_events (activation_event_id VARCHAR(64) PRIMARY KEY, installation_id VARCHAR(100) NOT NULL, license_id VARCHAR(64) NOT NULL, event_type VARCHAR(50) NOT NULL, request_id VARCHAR(100) NULL, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS dataform_definitions (definition_id VARCHAR(64) PRIMARY KEY, project_id VARCHAR(100) NOT NULL, dataform_id VARCHAR(100) NOT NULL, revision_number BIGINT NOT NULL, status VARCHAR(20) NOT NULL, definition_json TEXT NOT NULL, definition_hash VARCHAR(128) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(project_id,dataform_id,revision_number))",
            "CREATE TABLE IF NOT EXISTS runtime_sessions (runtime_id VARCHAR(100) PRIMARY KEY, license_id VARCHAR(64) NOT NULL, installation_id VARCHAR(100) NOT NULL, project_id VARCHAR(100) NOT NULL, dataform_id VARCHAR(100) NOT NULL, definition_id VARCHAR(64) NOT NULL, license_generation BIGINT NOT NULL, installation_generation BIGINT NOT NULL, manifest_version VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, issued_at BIGINT NOT NULL, lease_until BIGINT NOT NULL, grace_until BIGINT NOT NULL, last_seen_at BIGINT NULL, manifest_hash VARCHAR(128) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_actions (action_id VARCHAR(100) PRIMARY KEY, runtime_id VARCHAR(100) NOT NULL, action_type VARCHAR(100) NOT NULL, dataform_id VARCHAR(100) NOT NULL, resource_hash VARCHAR(128) NOT NULL, status VARCHAR(20) NOT NULL, issued_at BIGINT NOT NULL, valid_until BIGINT NOT NULL, completed_at BIGINT NULL, decision_hash VARCHAR(128) NOT NULL, result_code VARCHAR(100) NULL)",
            "CREATE TABLE IF NOT EXISTS used_nonces (installation_id VARCHAR(100) NOT NULL, nonce VARCHAR(128) NOT NULL, request_id VARCHAR(100) NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY (installation_id,nonce))",
            "CREATE TABLE IF NOT EXISTS audit_events (audit_id VARCHAR(64) PRIMARY KEY, event_type VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id VARCHAR(100) NULL, actor_type VARCHAR(50) NOT NULL, actor_id VARCHAR(100) NULL, old_value TEXT NULL, new_value TEXT NULL, request_id VARCHAR(100) NULL, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS admin_users (admin_id VARCHAR(64) PRIMARY KEY, username VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, role VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until BIGINT NULL, last_login_at BIGINT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS customer_allowed_domains (rule_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, domain_pattern VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(customer_id,domain_pattern))",
            "CREATE TABLE IF NOT EXISTS customer_allowed_networks (rule_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, cidr VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(customer_id,cidr))",
            "CREATE TABLE IF NOT EXISTS customer_users (customer_user_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, username VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, role VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until BIGINT NULL, last_login_at BIGINT NULL, credential_version INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS customer_roles (role_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, role_name VARCHAR(50) NOT NULL, label VARCHAR(160) NOT NULL, is_system INTEGER NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(customer_id,role_name))",
            "CREATE TABLE IF NOT EXISTS customer_role_capabilities (role_id VARCHAR(64) NOT NULL, capability_code VARCHAR(120) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(role_id,capability_code))",
            "CREATE TABLE IF NOT EXISTS customer_user_roles (customer_user_id VARCHAR(64) NOT NULL, role_id VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(customer_user_id,role_id))",
            "CREATE TABLE IF NOT EXISTS customer_role_resource_scopes (role_id VARCHAR(64) PRIMARY KEY, scope_mode VARCHAR(20) NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS customer_role_projects (role_id VARCHAR(64) NOT NULL, project_id VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(role_id,project_id))",
            "CREATE TABLE IF NOT EXISTS customer_role_dataforms (role_id VARCHAR(64) NOT NULL, dataform_uuid VARCHAR(36) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(role_id,dataform_uuid))",
            "CREATE TABLE IF NOT EXISTS customer_user_resource_scopes (customer_user_id VARCHAR(64) PRIMARY KEY, scope_mode VARCHAR(20) NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS customer_user_projects (customer_user_id VARCHAR(64) NOT NULL, project_id VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(customer_user_id,project_id))",
            "CREATE TABLE IF NOT EXISTS customer_user_dataforms (customer_user_id VARCHAR(64) NOT NULL, dataform_uuid VARCHAR(36) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(customer_user_id,dataform_uuid))",
            "CREATE TABLE IF NOT EXISTS customer_access_requests (request_id VARCHAR(64) PRIMARY KEY, email VARCHAR(255) NOT NULL, company VARCHAR(255) NOT NULL, first_name VARCHAR(160) NOT NULL, last_name VARCHAR(160) NOT NULL, phone VARCHAR(80) NULL, requested_domain VARCHAR(255) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, requested_at BIGINT NOT NULL, reviewed_at BIGINT NULL, reviewed_by_admin_id VARCHAR(64) NULL, customer_id VARCHAR(64) NULL, customer_user_id VARCHAR(64) NULL, license_id VARCHAR(64) NULL, decision_note TEXT NULL, updated_at BIGINT NOT NULL, request_id_external VARCHAR(100) NULL, request_ip_hash VARCHAR(128) NULL)",
            "CREATE TABLE IF NOT EXISTS customer_license_requests (request_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, customer_user_id VARCHAR(64) NOT NULL, customer_key VARCHAR(255) NOT NULL, request_type VARCHAR(30) NOT NULL, requested_products TEXT NULL, requested_modules TEXT NULL, requested_capabilities TEXT NULL, max_installations INTEGER NOT NULL, max_dataforms INTEGER NULL, term_months INTEGER NULL, requested_domains TEXT NULL, requested_networks TEXT NULL, notes TEXT NULL, status VARCHAR(20) NOT NULL, requested_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, reviewed_at BIGINT NULL, reviewed_by_admin_id VARCHAR(64) NULL, decision_note TEXT NULL, resulting_license_id VARCHAR(64) NULL)",
            "CREATE TABLE IF NOT EXISTS product_catalog (product_code VARCHAR(100) PRIMARY KEY, name VARCHAR(160) NOT NULL, description TEXT NULL, loader_module VARCHAR(100) NOT NULL, package_key VARCHAR(160) NULL, default_modules TEXT NULL, default_capabilities TEXT NULL, status VARCHAR(20) NOT NULL, version VARCHAR(50) NULL, sort_order INTEGER NOT NULL DEFAULT 100, visible INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS project_product_requests (request_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, customer_user_id VARCHAR(64) NOT NULL, customer_key VARCHAR(255) NOT NULL, installation_id VARCHAR(100) NOT NULL, project_id VARCHAR(100) NOT NULL, project_name VARCHAR(255) NOT NULL, project_slug VARCHAR(255) NULL, product_code VARCHAR(100) NOT NULL, requested_modules TEXT NULL, requested_capabilities TEXT NULL, term_months INTEGER NULL, notes TEXT NULL, status VARCHAR(20) NOT NULL, requested_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, reviewed_at BIGINT NULL, reviewed_by_admin_id VARCHAR(64) NULL, decision_note TEXT NULL, resulting_license_id VARCHAR(64) NULL, entitlement_id VARCHAR(64) NULL)",
            "CREATE TABLE IF NOT EXISTS project_product_entitlements (entitlement_id VARCHAR(64) PRIMARY KEY, customer_id VARCHAR(64) NOT NULL, license_id VARCHAR(64) NOT NULL, installation_id VARCHAR(100) NOT NULL, project_id VARCHAR(100) NOT NULL, project_name VARCHAR(255) NOT NULL, project_slug VARCHAR(255) NULL, product_code VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, valid_from BIGINT NULL, valid_until BIGINT NULL, modules TEXT NULL, capabilities TEXT NULL, approved_request_id VARCHAR(64) NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(customer_id,installation_id,project_id,product_code))",
            "CREATE TABLE IF NOT EXISTS customer_account_sessions (session_id VARCHAR(64) PRIMARY KEY, customer_user_id VARCHAR(64) NOT NULL, token_hash VARCHAR(128) NOT NULL UNIQUE, status VARCHAR(20) NOT NULL, issued_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, last_seen_at BIGINT NULL, surface_host VARCHAR(255) NULL, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS installation_server_key_trust (installation_id VARCHAR(100) NOT NULL, key_id VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, first_seen_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL, PRIMARY KEY(installation_id,key_id))",
        ];
    }

    private function ensureCustomerColumns(): void
    {
        $cols=[
            'legal_name'=>'VARCHAR(255) NULL','contact_name'=>'VARCHAR(255) NULL','phone'=>'VARCHAR(80) NULL','mobile'=>'VARCHAR(80) NULL',
            'website'=>'VARCHAR(255) NULL','street'=>'VARCHAR(255) NULL','postal_code'=>'VARCHAR(40) NULL','city'=>'VARCHAR(160) NULL',
            'state_region'=>'VARCHAR(160) NULL','country_code'=>'VARCHAR(8) NULL','vat_id'=>'VARCHAR(80) NULL','billing_email'=>'VARCHAR(255) NULL',
            'technical_email'=>'VARCHAR(255) NULL','notes'=>'TEXT NULL','surface_domain_required'=>'INTEGER NOT NULL DEFAULT 1','surface_ip_required'=>'INTEGER NOT NULL DEFAULT 0','surface_self_service'=>'INTEGER NOT NULL DEFAULT 0',
        ];
        foreach($cols as $name=>$definition) $this->addColumnIfMissing('customers',$name,$definition);
        $this->addColumnIfMissing('customers','customer_key','VARCHAR(255) NULL');
        $this->addColumnIfMissing('customer_users','email','VARCHAR(255) NULL');
        $this->addColumnIfMissing('customer_users','license_id','VARCHAR(64) NULL');
        $this->addColumnIfMissing('customer_users','credential_version','INTEGER NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('runtime_sessions','surface_origin','VARCHAR(512) NULL');
        $this->addColumnIfMissing('runtime_sessions','source_ip','VARCHAR(64) NULL');
    }

    /** HF87: normalized e-mail is the authoritative business customer key. */
    private function ensureCustomerKeyModel(): void
    {
        $rows=$this->pdo->query('SELECT customer_id,customer_key,email FROM customers')->fetchAll();
        $userEmail=$this->pdo->prepare("SELECT COALESCE(email,username) e FROM customer_users WHERE customer_id=? ORDER BY CASE WHEN role='customer_admin' THEN 0 ELSE 1 END,created_at LIMIT 1");
        $update=$this->pdo->prepare('UPDATE customers SET customer_key=?,updated_at=? WHERE customer_id=?');
        foreach($rows as $row){
            $key=strtolower(trim((string)($row['customer_key']??'')));
            if($key==='')$key=strtolower(trim((string)($row['email']??'')));
            if($key===''){$userEmail->execute([(string)$row['customer_id']]);$key=strtolower(trim((string)($userEmail->fetchColumn()?:'')));}
            if($key!==''&&filter_var($key,FILTER_VALIDATE_EMAIL)&&$key!==(string)($row['customer_key']??''))$update->execute([$key,time(),$row['customer_id']]);
        }
        $dup=$this->pdo->query("SELECT customer_key,COUNT(*) c FROM customers WHERE customer_key IS NOT NULL AND customer_key<>'' GROUP BY customer_key HAVING COUNT(*)>1")->fetch();
        if($dup)throw new \RuntimeException('CUSTOMER_KEY_DUPLICATE');
        if(!$this->indexExists('customers','ux_customers_customer_key'))$this->pdo->exec('CREATE UNIQUE INDEX ux_customers_customer_key ON customers(customer_key)');
    }

    private function indexExists(string $table,string $index): bool
    {
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){$st=$this->pdo->prepare('SELECT COUNT(*) c FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$st->execute([$table,$index]);return(int)($st->fetch()['c']??0)>0;}
        if($driver==='pgsql'){$st=$this->pdo->prepare('SELECT COUNT(*) c FROM pg_indexes WHERE schemaname=current_schema() AND tablename=? AND indexname=?');$st->execute([$table,$index]);return(int)($st->fetch()['c']??0)>0;}
        if($driver==='sqlite'){foreach($this->pdo->query('PRAGMA index_list('.$table.')')->fetchAll() as $row)if((string)($row['name']??'')===$index)return true;return false;}
        return false;
    }

    /** HF85 Phase 4: durable deletion/release metadata for central DataForm identities. */
    private function ensureDataformRegistryColumns(): void
    {
        $this->addColumnIfMissing('license_dataforms','released_at','BIGINT NULL');
        $this->addColumnIfMissing('license_dataforms','release_request_id','VARCHAR(100) NULL');
        $this->addColumnIfMissing('license_dataforms','token_last_issued_key_id','VARCHAR(100) NULL');
        $this->addColumnIfMissing('license_dataforms','token_last_issued_at','BIGINT NULL');
    }

    private function addColumnIfMissing(string $table,string $column,string $definition): void
    {
        if($this->columnExists($table,$column)) return;
        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function columnExists(string $table,string $column): bool
    {
        $driver=(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $st=$this->pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
            $st->execute([$table,$column]);return (int)($st->fetch()['c']??0)>0;
        }
        if($driver==='pgsql'){
            $st=$this->pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?");
            $st->execute([$table,$column]);return (int)($st->fetch()['c']??0)>0;
        }
        if($driver==='sqlite'){
            $rows=$this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll();foreach($rows as $r)if((string)$r['name']===$column)return true;return false;
        }
        try{$this->pdo->query("SELECT {$column} FROM {$table} WHERE 1=0");return true;}catch(\Throwable){return false;}
    }

    private function seedModules(): void
    {
        $now=time();$stmt=$this->pdo->prepare('SELECT module_code FROM modules WHERE module_code=?');$ins=$this->pdo->prepare('INSERT INTO modules (module_code,name,requires_runtime,active,created_at,updated_at) VALUES (?,?,?,?,?,?)');
        foreach ([['dataform','DataForm',1],['dialog','Dialog',1],['nachhilfe','Nachhilfe',1],['csv-engine','CSV-Engine',0],['calendar','easyIT Calendar',1],['db-engine','DB-Engine',1],['designer','DataForm Designer',1],['export','Export',1],['api','API',1],['themes','Themes',0]] as [$code,$name,$runtime]) {$stmt->execute([$code]);if(!$stmt->fetch())$ins->execute([$code,$name,$runtime,1,$now,$now]);}
    }

    private function seedProductCatalog(): void
    {
        $now=time();
        $rows=[
            ['dataform','DataForm','Datengetriebene Formulare, Tabellen, Relationen und Anwendungen.','dataform','dataform',['dataform'],['dataset.read','dataset.create','dataset.update','dataset.delete','dataform.search','dataform.filter','dataform.pagination','relation.read','relation.createChild'],'available','5-dev',10],
            ['dialog','Dialog','Dialog- und Prozessoberflächen als nachladbares easyIT-Produktmodul.','dialog','dialog',['dialog'],[],'available',null,20],
            ['nachhilfe','Nachhilfe','Lern- und Unterrichtsmodul für strukturierte Aufgaben, Inhalte und Auswertungen.','nachhilfe','nachhilfe',['nachhilfe'],[],'available',null,30],
            ['csv-engine','CSV-Engine','Dateibasierter Datenbank-/Importbaustein für portable Projekte und Datenaustausch.','csv-engine','csv-engine',['csv-engine'],[],'available','RC1.1',40],
            ['calendar','easyIT Calendar','Projektbezogenes Kalenderprodukt für Termine, Ressourcen und Zuordnungen mit konfigurierbarer Datenquelle.','calendar','calendar',['calendar'],[],'available','1.0',25],
            ['db-engine','DB-Engine','Exportierbare projektspezifische Datenbank-Engine für lokale Kundendaten. Unterstützt CSV, SQLite und alle freigegebenen PDO-Treiber.','db-engine','db-engine',['db-engine'],['db-engine.export','db-engine.runtime'],'available','1.0',15],
        ];
        $find=$this->pdo->prepare('SELECT product_code FROM product_catalog WHERE product_code=?');
        $ins=$this->pdo->prepare('INSERT INTO product_catalog (product_code,name,description,loader_module,package_key,default_modules,default_capabilities,status,version,sort_order,visible,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $up=$this->pdo->prepare('UPDATE product_catalog SET name=?,description=?,loader_module=?,package_key=?,default_modules=?,default_capabilities=?,status=?,version=?,sort_order=?,visible=1,updated_at=? WHERE product_code=?');
        foreach($rows as [$code,$name,$description,$loader,$package,$modules,$caps,$status,$version,$sort]){
            $mods=json_encode($modules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$capJson=json_encode($caps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $find->execute([$code]);
            if($find->fetch())$up->execute([$name,$description,$loader,$package,$mods,$capJson,$status,$version,$sort,$now,$code]);
            else $ins->execute([$code,$name,$description,$loader,$package,$mods,$capJson,$status,$version,$sort,1,$now,$now]);
        }
    }

    private function seedCapabilities(): void
    {
        $now=time();$stmt=$this->pdo->prepare('SELECT capability_code FROM capability_registry WHERE capability_code=?');$ins=$this->pdo->prepare('INSERT INTO capability_registry (capability_code,name,category,active,created_at,updated_at) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['dataset.read','Datensätze lesen','crud'],['dataset.create','Datensätze anlegen','crud'],['dataset.update','Datensätze bearbeiten','crud'],['dataset.delete','Datensätze löschen','crud'],
            ['dataform.search','Volltextsuche','dataform'],['dataform.filter','Filter','dataform'],['dataform.pagination','Paginierung','dataform'],
            ['relation.read','Beziehungen anzeigen','relations'],['relation.createChild','Kinddatensätze anlegen','relations'],['relation.attach','Beziehungen zuordnen','relations'],['relation.detach','Beziehungen lösen','relations'],
            ['dataform.events','DataForm-/DS-Events','scripting'],['dataform.custom_css','Zusätzliches CSS','scripting'],['dataform.custom_javascript','Zusätzliches JavaScript','scripting'],
            ['designer.validate','Designer validieren','designer'],['designer.compile','Designer kompilieren','designer'],['designer.publish','Designer veröffentlichen','designer'],['export.execute','Export','modules'],['api.access','API-Zugriff','modules'],
            ['db-engine.export','DB-Engine exportieren','db-engine'],['db-engine.runtime','DB-Engine zur DataForm-Laufzeit verwenden','db-engine'],
        ] as [$code,$name,$category]) {$stmt->execute([$code]);if(!$stmt->fetch())$ins->execute([$code,$name,$category,1,$now,$now]);}
    }
}
