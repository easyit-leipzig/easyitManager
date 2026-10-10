<?php
declare(strict_types=1);

/**
 * PDO-compatible control-plane adapter backed by the DataForm CSV engine.
 *
 * It intentionally implements the SQL subset used by easyIT Enterprise's
 * administration/control-plane code. Persistent data remains plain UTF-8 CSV
 * (delimiter "|") in ADMIN_DB_CSV_BASE_PATH/ADMIN_DB_DATABASE.
 */
final class EnterpriseCsvPdo extends PDO
{
    private \DataForm\Database\Csv\CsvAdapter $db;
    private string $databaseName;
    private int $lastId = 0;
    private bool $transaction = false;

    /** @var array<string,array{columns:list<string>,defaults:array<string,mixed>,unique:list<list<string>>}> */
    private const SCHEMA = [
        'migrations'=>[
            'columns'=>['migration','checksum','executed_at'],
            'defaults'=>['executed_at'=>'@now'],
            'unique'=>[['migration']],
        ],
        'roles'=>[
            'columns'=>['name','label','role_key','created_at'],
            'defaults'=>['role_key'=>'','created_at'=>'@now'],
            'unique'=>[['name']],
        ],
        'users'=>[
            'columns'=>['username','email','password_hash','is_active','created_at','updated_at'],
            'defaults'=>['email'=>'','is_active'=>'1','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[['username'],['email']],
        ],
        'user_roles'=>[
            'columns'=>['user_id','role_id'],
            'defaults'=>[],
            'unique'=>[['user_id','role_id']],
        ],
        'projects'=>[
            'columns'=>['name','slug','storage_key','product_type','database_driver','database_name','description','status','created_at','updated_at'],
            'defaults'=>['storage_key'=>'','product_type'=>'dataform','database_driver'=>'mysql','description'=>'','status'=>'active','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[['slug']],
        ],
        'audit_log'=>[
            'columns'=>['user_id','action','object_type','object_id','context_json','created_at'],
            'defaults'=>['user_id'=>'','object_type'=>'','object_id'=>'','context_json'=>'','created_at'=>'@now'],
            'unique'=>[],
        ],
        'installed_products'=>[
            'columns'=>['product_key','label','status','version','created_at'],
            'defaults'=>['status'=>'available','version'=>'','created_at'=>'@now'],
            'unique'=>[['product_key']],
        ],
        'enterprise_module_settings'=>[
            'columns'=>['module_name','scope_type','scope_id','config_key','config_value_json','updated_at'],
            'defaults'=>['scope_type'=>'enterprise','scope_id'=>'global','config_value_json'=>'','updated_at'=>'@now'],
            'unique'=>[['module_name','scope_type','scope_id','config_key']],
        ],
        'enterprise_module_secrets'=>[
            'columns'=>['module_name','scope_type','scope_id','config_key','ciphertext','nonce','tag','updated_at'],
            'defaults'=>['scope_type'=>'enterprise','scope_id'=>'global','updated_at'=>'@now'],
            'unique'=>[['module_name','scope_type','scope_id','config_key']],
        ],
        'capabilities'=>[
            'columns'=>['name','module_name','label','created_at'],
            'defaults'=>['module_name'=>'','created_at'=>'@now'],
            'unique'=>[['name']],
        ],
        'role_capabilities'=>[
            'columns'=>['role_id','capability_id'],
            'defaults'=>[],
            'unique'=>[['role_id','capability_id']],
        ],
        'enterprise_module_migrations'=>[
            'columns'=>['module_name','migration_version','checksum','batch','applied_at'],
            'defaults'=>['batch'=>'1','applied_at'=>'@now'],
            'unique'=>[['module_name','migration_version']],
        ],
        'enterprise_licenses'=>[
            'columns'=>['license_key','customer_key','holder','edition','capabilities_json','products_json','valid_from','expires_at','grace_days','enabled','is_primary','metadata_json','created_at','updated_at'],
            'defaults'=>['edition'=>'community','capabilities_json'=>'','products_json'=>'','valid_from'=>'','expires_at'=>'','grace_days'=>'0','enabled'=>'1','is_primary'=>'0','metadata_json'=>'','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[['license_key']],
        ],
        // RC1.1 Phase 1: CSV kann auch als physischer Projektspeicher dienen.
        'dataforms'=>[
            'columns'=>['name','slug','description','status','df_uuid','license_status','license_registered_at','license_token','license_token_key_id','license_token_issued_at','table_save_mode','show_save_success','created_at','updated_at','view_mode','default_per_page','show_search','show_filter','show_pagination','allow_create','allow_edit','allow_delete','dialog_size','css_class','additional_css','event_handlers_json'],
            'defaults'=>['description'=>'','status'=>'draft','df_uuid'=>'','license_status'=>'unlicensed','license_registered_at'=>'','license_token'=>'','license_token_key_id'=>'','license_token_issued_at'=>'','table_save_mode'=>'manual','show_save_success'=>'1','created_at'=>'@now','updated_at'=>'@now','view_mode'=>'table','default_per_page'=>'20','show_search'=>'1','show_filter'=>'1','show_pagination'=>'1','allow_create'=>'1','allow_edit'=>'1','allow_delete'=>'1','dialog_size'=>'large','css_class'=>'','additional_css'=>'','event_handlers_json'=>''],
            'unique'=>[['slug'],['df_uuid']],
        ],
        'dataform_recordset_event_handlers'=>[
            'columns'=>['dataform_id','recordset_key','event_key','handler_code','updated_at'],
            'defaults'=>['recordset_key'=>'main','handler_code'=>'','updated_at'=>'@now'],
            'unique'=>[['dataform_id','recordset_key','event_key']],
        ],
        'dataform_fields'=>[
            'columns'=>['dataform_id','name','label','field_type','position','is_required','configuration_json','created_at'],
            'defaults'=>['position'=>'0','is_required'=>'0','configuration_json'=>'','created_at'=>'@now'],
            'unique'=>[['dataform_id','name']],
        ],
        'data_sources'=>[
            'columns'=>['name','driver','config_json','secret_ciphertext','secret_nonce','secret_tag','is_enabled','last_test_status','last_test_message','last_test_at','created_at','updated_at'],
            'defaults'=>['secret_ciphertext'=>'','secret_nonce'=>'','secret_tag'=>'','is_enabled'=>'1','last_test_status'=>'unknown','last_test_message'=>'','last_test_at'=>'','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[['name']],
        ],
        'dataform_managed_tables'=>[
            'columns'=>['table_name','created_at'],
            'defaults'=>['created_at'=>'@now'],
            'unique'=>[['table_name']],
        ],
        // HF91 Phase 10: easyITCalendar project data for CSV runtime.
        'events'=>[
            'columns'=>['start','end','all_day','location','resource_id','category_id','activ','thema','description','event_status','version_no','created_by','updated_by','created_at','updated_at'],
            'defaults'=>['all_day'=>'0','location'=>'','resource_id'=>'','category_id'=>'','activ'=>'1','thema'=>'','description'=>'','event_status'=>'planned','version_no'=>'1','created_by'=>'1','updated_by'=>'1','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[],
        ],
        'persons'=>[
            'columns'=>['display_name','email','activ','created_at','updated_at'],
            'defaults'=>['email'=>'','activ'=>'1','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[],
        ],
        'roles'=>[
            'columns'=>['name','label','role_key','created_at'],
            'defaults'=>['label'=>'','role_key'=>'','created_at'=>'@now'],
            'unique'=>[['role_key']],
        ],
        'event_participants'=>[
            'columns'=>['event_id','person_id','role_id','participation_status','is_primary'],
            'defaults'=>['participation_status'=>'accepted','is_primary'=>'1'],
            'unique'=>[],
        ],
        'event_attachments'=>[
            'columns'=>['event_id','original_name','stored_name','storage_path','activ','created_at'],
            'defaults'=>['activ'=>'1','created_at'=>'@now'],
            'unique'=>[],
        ],
        'resources'=>[
            'columns'=>['name','activ','created_at','updated_at'],
            'defaults'=>['activ'=>'1','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[],
        ],
        'categories'=>[
            'columns'=>['name','activ','created_at','updated_at'],
            'defaults'=>['activ'=>'1','created_at'=>'@now','updated_at'=>'@now'],
            'unique'=>[],
        ],
        'dataform_table_bindings'=>[
            'columns'=>['dataform_id','source_kind','source_id','table_name','binding_mode','created_at'],
            'defaults'=>['source_kind'=>'system','source_id'=>'0','binding_mode'=>'schema','created_at'=>'@now'],
            'unique'=>[['dataform_id'],['source_kind','source_id','table_name']],
        ],
    ];

    public function __construct(string $basePath, string $database)
    {
        require_once dirname(__DIR__, 2) . '/DataForm5-Core/system/database/autoload.php';
        $this->databaseName = $database;
        $this->db = new \DataForm\Database\Csv\CsvAdapter([
            'driver'=>'csv',
            'base_path'=>$basePath,
            'database'=>$database,
            'auto_connect'=>true,
        ]);
        $this->db->connect();
    }

    public function storagePath(): string { return $this->db->databasePath(); }
    public function tableExists(string $table): bool { return $this->db->tableExists($this->id($table)); }
    /** @return list<string> */
    public function tableNames(): array { return $this->db->listTables(); }
    /** HF99: schema administration for product modules such as easyIT Calendar. */
    public function columnNames(string $table): array { return $this->db->columns($this->id($table)); }
    public function createDataTable(string $table,array $columns=['id']): void { $this->db->createTable($this->id($table),$columns); }
    public function dropDataTable(string $table): void { $this->db->dropTable($this->id($table),true); }
    public function addDataColumn(string $table,string $column): void { $this->db->addColumn($this->id($table),$this->id($column),''); }
    public function dropDataColumn(string $table,string $column): void { $this->db->dropColumn($this->id($table),$this->id($column)); }

    public function ensureAdminSchema(): void
    {
        foreach (self::SCHEMA as $table=>$def) $this->ensureTable($table,$def['columns']);
        $this->seedCore();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new EnterpriseCsvPdoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new EnterpriseCsvPdoStatement($this, $query);
        $stmt->execute();
        if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $result=$this->executeSql($statement,[]);
        return $result['rowCount'];
    }

    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }

    public function beginTransaction(): bool
    {
        if ($this->transaction) return false;
        $this->db->beginTransaction();
        $this->transaction=true;
        return true;
    }
    public function commit(): bool
    {
        if (!$this->transaction) return false;
        $this->db->commit();
        $this->transaction=false;
        return true;
    }
    public function rollBack(): bool
    {
        if (!$this->transaction) return false;
        $this->db->rollBack();
        $this->transaction=false;
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'".str_replace("'","''",$string)."'"; }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    public function executeSql(string $sql, array $params): array
    {
        $raw=trim($sql);
        $sql=$this->normalizeSql($raw);
        if ($sql==='') return ['rows'=>[],'rowCount'=>0];

        // MySQL-only transaction/session statements are harmless for CSV.
        if (preg_match('/^(SET\s+FOREIGN_KEY_CHECKS|USE\s+)/i',$sql)) return ['rows'=>[],'rowCount'=>0];

        if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s*\((.*)\)(?:\s+ENGINE=.*)?$/is',$raw,$m)) {
            $table=$this->id($m[1]);
            $cols=$this->parseCreateColumns($m[2]);
            $this->ensureTable($table,$cols);
            return ['rows'=>[],'rowCount'=>0];
        }
        if (preg_match('/^ALTER\s+TABLE\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s+ADD(?:\s+COLUMN)?\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i',$sql,$m)) {
            $table=$this->id($m[1]);$column=$this->id($m[2]);
            if (!$this->db->tableExists($table)) $this->ensureTable($table,[$column]);
            elseif (!in_array($column,$this->db->columns($table),true) && $column!=='id') $this->db->addColumn($table,$column,$this->defaultFor($table,$column));
            return ['rows'=>[],'rowCount'=>0];
        }
        if (preg_match('/^SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?(?:\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"])?/i',$sql,$m)) {
            $table=$this->id($m[1]);$like=$m[2]??null;$rows=[];
            if ($this->db->tableExists($table)) foreach($this->db->columns($table) as $c) if($like===null||$c===$like)$rows[]=['Field'=>$c,'Type'=>'text','Null'=>'YES','Key'=>$c==='id'?'PRI':'','Default'=>null,'Extra'=>$c==='id'?'auto_increment':''];
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }
        if (preg_match('/^SHOW\s+TABLES/i',$sql)) {
            $rows=array_map(fn($t)=>['Tables_in_csv'=>$t],$this->db->listTables());
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }

        // PDO/health checks without a physical SQL server.
        if (preg_match('/^SELECT\s+1(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i',$sql,$m)) {
            $key=$m[1]??'1';
            return ['rows'=>[[$key=>1]],'rowCount'=>1];
        }

        // Installer / schema introspection.
        if (str_contains(strtoupper($sql),'INFORMATION_SCHEMA.SCHEMATA')) {
            $requested=(string)($params[0]??'');
            $rows=($requested==='' || $requested===$this->databaseName) ? [['SCHEMA_NAME'=>$this->databaseName]] : [];
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }
        if (str_contains(strtoupper($sql),'INFORMATION_SCHEMA.COLUMNS')) {
            $table=''; $column='';
            if (preg_match('/TABLE_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/i',$sql,$m)) $table=$m[1];
            elseif (preg_match('/TABLE_NAME\s*=\s*\?/i',$sql)) $table=(string)($params[0]??'');
            if (preg_match('/COLUMN_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/i',$sql,$m)) $column=$m[1];
            elseif (preg_match('/COLUMN_NAME\s*=\s*\?/i',$sql)) $column=(string)($params[array_key_last($params)]??'');
            $count=0;
            if ($table!=='' && $column!=='' && $this->db->tableExists($this->id($table))) {
                $count=in_array($this->id($column),$this->db->columns($this->id($table)),true)?1:0;
            }
            return ['rows'=>[['COUNT(*)'=>$count]],'rowCount'=>1];
        }
        if (str_contains(strtoupper($sql),'INFORMATION_SCHEMA.TABLES')) {
            return $this->selectInformationSchemaTables($sql,$params);
        }

        if (preg_match('/^SELECT\s+/i',$sql)) return $this->select($sql,$params);
        if (preg_match('/^INSERT\s+/i',$sql)) return $this->insertSql($sql,$params);
        if (preg_match('/^UPDATE\s+/i',$sql)) return $this->updateSql($sql,$params);
        if (preg_match('/^DELETE\s+/i',$sql)) return $this->deleteSql($sql,$params);

        // DDL not needed by the current control plane should fail loudly.
        throw new RuntimeException('CSV-Adminspeicher: SQL wird nicht unterstützt: '.$this->shortSql($sql));
    }

    /**
     * Emulates the information_schema.tables subset used by the DataForm and
     * Calendar workspaces. CSV tables are always BASE TABLEs.
     *
     * @return array{rows:list<array<string,mixed>>,rowCount:int}
     */
    private function selectInformationSchemaTables(string $sql,array $params): array
    {
        $upper=strtoupper($sql);
        $table='';
        if (preg_match('/TABLE_NAME\s*=\s*\?/i',$sql)) {
            $table=(string)($params[array_key_last($params)]??'');
        } elseif (preg_match('/TABLE_NAME\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i',$sql,$m)) {
            $table=(string)$m[1];
        }

        $names=$this->db->listTables();
        sort($names,SORT_NATURAL|SORT_FLAG_CASE);
        if($table!==''){
            // A malformed identifier is not a physical CSV table. Do not let an
            // introspection query turn a value such as "0" into an id() error.
            if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$table)!==1){
                $names=[];
            } else {
                $names=array_values(array_filter($names,static fn(string $n):bool=>$n===$table));
            }
        }

        if(str_contains($upper,'COUNT(*)')){
            $count=count($names);
            return ['rows'=>[['COUNT(*)'=>$count]],'rowCount'=>1];
        }

        if(preg_match('/SELECT\s+TABLE_TYPE\s+FROM/i',$sql)){
            $rows=$names!==[]?[['table_type'=>'BASE TABLE','TABLE_TYPE'=>'BASE TABLE']]:[];
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }

        $wantRows=str_contains($upper,'TABLE_ROWS');
        $rows=[];
        foreach($names as $name){
            $row=[
                'TABLE_NAME'=>$name,
                'table_name'=>$name,
                'TABLE_TYPE'=>'BASE TABLE',
                'table_type'=>'BASE TABLE',
            ];
            if($wantRows){
                $row['TABLE_ROWS']=count($this->db->all($name));
                $row['table_rows']=$row['TABLE_ROWS'];
            }
            // Common aliases used by workspace queries.
            if(preg_match('/TABLE_NAME\s+AS\s+NAME/i',$sql))$row['name']=$name;
            if(preg_match('/TABLE_TYPE\s+AS\s+KIND/i',$sql))$row['kind']='BASE TABLE';
            $rows[]=$row;
        }
        return ['rows'=>$rows,'rowCount'=>count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function select(string $sql,array $params): array
    {
        $upper=strtoupper($sql);

        // Login + role aggregation.
        if (str_contains($upper,'FROM USERS U LEFT JOIN USER_ROLES UR') && str_contains($upper,'GROUP_CONCAT(R.NAME)')) {
            $needle1=(string)($params[0]??'');$needle2=(string)($params[1]??'');
            foreach($this->rows('users') as $u){
                if((string)$u['username']!==$needle1 && (string)$u['email']!==$needle2)continue;
                $roles=$this->roleNamesForUser((int)$u['id']);
                $u['roles']=implode(',',$roles);
                return ['rows'=>[$this->projectRow($u,['id','username','email','password_hash','is_active','roles'])],'rowCount'=>1];
            }
            return ['rows'=>[],'rowCount'=>0];
        }

        // Permissions query.
        if (str_contains($upper,'FROM CAPABILITIES C JOIN ROLE_CAPABILITIES RC') && str_contains($upper,'JOIN USER_ROLES UR')) {
            $uid=(int)($params[0]??0);$roleIds=[];
            foreach($this->rows('user_roles') as $ur) if((int)$ur['user_id']===$uid)$roleIds[]=(int)$ur['role_id'];
            $capIds=[];foreach($this->rows('role_capabilities') as $rc) if(in_array((int)$rc['role_id'],$roleIds,true))$capIds[]=(int)$rc['capability_id'];
            $names=[];foreach($this->rows('capabilities') as $c) if(in_array((int)$c['id'],$capIds,true))$names[]=(string)$c['name'];
            $names=array_values(array_unique($names));sort($names,SORT_NATURAL|SORT_FLAG_CASE);
            return ['rows'=>array_map(fn($n)=>['name'=>$n],$names),'rowCount'=>count($names)];
        }

        // Count users with protected administrative roles / role-specific checks.
        if ((str_contains($upper,'JOIN USER_ROLES UR') || str_contains($upper,'FROM USER_ROLES UR'))
            && str_contains($upper,'JOIN ROLES R')
            && preg_match('/R\.NAME\s*=\s*[\'"](ADMIN|SUPERADMIN)[\'"]/i',$sql,$roleMatch)) {
            $roleName=strtolower((string)$roleMatch[1]);
            $paramIndex=0;
            $uid=null;
            if(preg_match('/UR\.USER_ID\s*=\s*\?/i',$sql)) $uid=(int)($params[$paramIndex++]??0);
            $exclude=null;
            if(preg_match('/U\.ID\s*<>\s*\?/i',$sql)) $exclude=(int)($params[$paramIndex++]??0);
            $activeOnly=(bool)preg_match('/U\.IS_ACTIVE\s*=\s*1/i',$sql);
            $seen=[];
            foreach($this->rows('user_roles') as $ur){
                $userId=(int)$ur['user_id'];
                if($uid!==null && $userId!==$uid)continue;
                if($exclude!==null && $userId===$exclude)continue;
                $role=$this->findById('roles',(int)$ur['role_id']);
                if(!$role || strtolower((string)$role['name'])!==$roleName)continue;
                if($activeOnly){$u=$this->findById('users',$userId);if(!$u||(int)($u['is_active']??0)!==1)continue;}
                $seen[(string)$userId]=true;
            }
            $count=count($seen);
            return ['rows'=>[['COUNT(*)'=>$count,'COUNT(DISTINCT u.id)'=>$count]],'rowCount'=>1];
        }

        // Users list with roles.
        if (str_contains($upper,'FROM USERS U LEFT JOIN USER_ROLES UR') && str_contains($upper,'ROLE_NAMES')) {
            $rows=[];foreach($this->rows('users') as $u){
                $roleIds=[];$names=[];
                foreach($this->rows('user_roles') as $ur) if((int)$ur['user_id']===(int)$u['id']){$roleIds[]=(int)$ur['role_id'];$r=$this->findById('roles',(int)$ur['role_id']);if($r)$names[]=(string)$r['name'];}
                natcasesort($names);sort($roleIds,SORT_NUMERIC);
                $rows[]=['id'=>$u['id'],'username'=>$u['username'],'email'=>$u['email'],'is_active'=>$u['is_active'],'created_at'=>$u['created_at'],'role_names'=>implode(', ',$names),'role_ids'=>implode(',',$roleIds)];
            }
            usort($rows,fn($a,$b)=>strnatcasecmp((string)$a['username'],(string)$b['username']));
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }

        // Roles with user count.
        if (str_contains($upper,'FROM ROLES R LEFT JOIN USER_ROLES UR') && str_contains($upper,'USER_COUNT')) {
            $rows=[];foreach($this->rows('roles') as $r){$n=0;$seen=[];foreach($this->rows('user_roles') as $ur)if((int)$ur['role_id']===(int)$r['id'])$seen[(string)$ur['user_id']]=true;$r['user_count']=count($seen);$rows[]=$r;}
            usort($rows,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }

        // role_capabilities + capability name.
        if (str_contains($upper,'FROM ROLE_CAPABILITIES RC JOIN CAPABILITIES C')) {
            $rows=[];foreach($this->rows('role_capabilities') as $rc){$c=$this->findById('capabilities',(int)$rc['capability_id']);if($c)$rows[]=['role_id'=>$rc['role_id'],'name'=>$c['name']];}
            return ['rows'=>$rows,'rowCount'=>count($rows)];
        }

        // Audit list/count with optional user join and generated WHERE.
        if (str_contains($upper,'FROM AUDIT_LOG A LEFT JOIN USERS U')) return $this->selectAudit($sql,$params);

        // HF119: DataForm table-binding workspace join. The CSV backend has no
        // native JOIN support, so construct the joined rows explicitly. This
        // covers both DataFormManager::tableBinding() and the workspace catalog.
        if (str_contains($upper,'FROM DATAFORM_TABLE_BINDINGS B') && str_contains($upper,'JOIN DATAFORMS D')) {
            return $this->selectDataformTableBindings($sql,$params);
        }

        // Generic single-table SELECT.
        if (!preg_match('/^SELECT\s+(.+?)\s+FROM\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?(?:\s+(?:AS\s+)?(?!WHERE\b|ORDER\b|LIMIT\b|OFFSET\b|FOR\b)[A-Za-z_][A-Za-z0-9_]*)?(.*)$/is',$sql,$m))
            throw new RuntimeException('CSV-Adminspeicher: SELECT konnte nicht analysiert werden: '.$this->shortSql($sql));
        $fields=trim($m[1]);$table=$this->id($m[2]);$tail=trim($m[3]);
        $rows=$this->rows($table);

        [$where,$order,$limit,$offset]=$this->parseTail($tail);
        if($where!==''){$rows=array_values(array_filter($rows,function($r)use($where,$params){$pi=0;return $this->matches($r,$where,$params,$pi);}));}

        if (preg_match('/^COUNT\(\*\)$/i',$fields)) return ['rows'=>[['COUNT(*)'=>count($rows)]],'rowCount'=>1];
        // Aggregate position helpers used throughout DataForm. The CSV backend
        // must treat COALESCE/MAX and numeric literals as expressions, never as
        // SQL identifiers. Supports e.g. +1, +10 and arbitrary numeric defaults.
        if (preg_match('/^COALESCE\(\s*MAX\(\s*([A-Za-z0-9_.]+)\s*\)\s*,\s*(-?\d+(?:\.\d+)?)\s*\)\s*(?:\+\s*(-?\d+(?:\.\d+)?))?$/i',$fields,$mx)) {
            $col=$this->bareColumn($mx[1]);
            $default=(float)$mx[2];
            $increment=isset($mx[3]) && $mx[3]!=='' ? (float)$mx[3] : 0.0;
            $have=false;$max=$default;
            foreach($rows as $r){
                $v=$r[$col]??'';
                if($v===''||$v===null||!is_numeric($v))continue;
                $n=(float)$v;
                if(!$have||$n>$max){$max=$n;$have=true;}
            }
            if(!$have)$max=$default;
            $value=$max+$increment;
            if((float)(int)$value===$value)$value=(int)$value;
            return ['rows'=>[[$fields=>$value]],'rowCount'=>1];
        }
        if (preg_match('/^COUNT\(DISTINCT\s+([A-Za-z0-9_.]+)\)$/i',$fields,$cd)) {
            $col=$this->bareColumn($cd[1]);$vals=[];foreach($rows as $r)$vals[(string)($r[$col]??'')]=true;
            return ['rows'=>[['COUNT(DISTINCT '.$col.')'=>count($vals)]],'rowCount'=>1];
        }

        if($order!=='')$rows=$this->sortRows($rows,$order);
        if($offset>0||$limit!==null)$rows=array_slice($rows,$offset,$limit??null);
        if (preg_match('/^DISTINCT\s+(.+)$/i',$fields,$dm)) {
            $fields=trim($dm[1]);$projected=$this->projectRows($rows,$fields);$seen=[];$out=[];
            foreach($projected as $r){$k=json_encode($r);if(!isset($seen[$k])){$seen[$k]=1;$out[]=$r;}}
            return ['rows'=>$out,'rowCount'=>count($out)];
        }
        $rows=$this->projectRows($rows,$fields);
        return ['rows'=>$rows,'rowCount'=>count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function selectDataformTableBindings(string $sql,array $params): array
    {
        $rows=[];
        foreach($this->rows('dataform_table_bindings') as $binding){
            $dataform=$this->findById('dataforms',(int)($binding['dataform_id']??0));
            if(!$dataform) continue;
            $row=$binding;
            $row['dataform_name']=(string)($dataform['name']??'');
            $row['dataform_slug']=(string)($dataform['slug']??'');
            // Queries such as CalendarDataFormBridge select d.name/d.slug
            // without aliases; expose both the direct and workspace aliases.
            $row['name']=$row['dataform_name'];
            $row['slug']=$row['dataform_slug'];
            $sourceKind=(string)($binding['source_kind']??'system');
            $source=$sourceKind==='external'
                ? $this->findById('data_sources',(int)($binding['source_id']??0))
                : null;
            $row['source_name']=$sourceKind==='system'
                ? 'Projekt-Datenbank'
                : (string)($source['name']??'Externe Quelle');
            $row['source_driver']=$sourceKind==='system'
                ? ''
                : (string)($source['driver']??'');
            $rows[]=$row;
        }

        [$where,$order,$limit,$offset]=$this->parseTail($sql);
        if($where!==''){
            $rows=array_values(array_filter($rows,function(array $row)use($where,$params):bool{
                $pi=0;
                return $this->matches($row,$where,$params,$pi);
            }));
        }
        if($order!=='') $rows=$this->sortRows($rows,$order);
        if($offset>0||$limit!==null) $rows=array_slice($rows,$offset,$limit??null);
        return ['rows'=>$rows,'rowCount'=>count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function selectAudit(string $sql,array $params): array
    {
        $upper=strtoupper($sql);$rows=[];
        foreach($this->rows('audit_log') as $a){$u=((string)($a['user_id']??''))!==''?$this->findById('users',(int)$a['user_id']):null;$a['username']=$u['username']??'';$rows[]=$a;}
        $where='';if(preg_match('/\sWHERE\s+(.+?)(?:\sORDER\s+BY|\sLIMIT|$)/is',$sql,$m))$where=trim($m[1]);
        if($where!==''){$rows=array_values(array_filter($rows,function($r)use($where,$params){$pi=0;return $this->matches($r,$where,$params,$pi);}));}
        if(str_starts_with($upper,'SELECT COUNT(*)'))return ['rows'=>[['COUNT(*)'=>count($rows)]],'rowCount'=>1];
        if(preg_match('/ORDER\s+BY\s+A\.ID\s+DESC/i',$sql))usort($rows,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);
        $limit=null;$offset=0;if(preg_match('/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i',$sql,$lm)){$limit=(int)$lm[1];$offset=(int)($lm[2]??0);}
        if($limit!==null)$rows=array_slice($rows,$offset,$limit);
        return ['rows'=>$rows,'rowCount'=>count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function insertSql(string $sql,array $params): array
    {
        $ignore=(bool)preg_match('/^INSERT\s+IGNORE/i',$sql);

        // Core capability grant to protected system roles.
        if (preg_match('/^INSERT\s+IGNORE\s+INTO\s+ROLE_CAPABILITIES.*SELECT\s+R\.ID\s*,\s*C\.ID\s+FROM\s+ROLES\s+R\s+CROSS\s+JOIN\s+CAPABILITIES\s+C\s+WHERE\s+R\.NAME\s*=\s*[\'"](ADMIN|SUPERADMIN)[\'"]/i',$sql,$rm)) {
            $roleName=strtolower((string)$rm[1]);
            $role=null;foreach($this->rows('roles') as $r)if(strtolower((string)$r['name'])===$roleName){$role=$r;break;}
            $n=0;if($role)foreach($this->rows('capabilities') as $c){$row=['role_id'=>$role['id'],'capability_id'=>$c['id']];if(!$this->findDuplicate('role_capabilities',$row)){$this->insertRow('role_capabilities',$row);$n++;}}
            return ['rows'=>[],'rowCount'=>$n];
        }

        if(!preg_match('/^INSERT(?:\s+IGNORE)?\s+INTO\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s*\(([^)]+)\)\s*VALUES\s*(.+?)(?:\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+))?$/is',$sql,$m))
            throw new RuntimeException('CSV-Adminspeicher: INSERT konnte nicht analysiert werden: '.$this->shortSql($sql));
        $table=$this->id($m[1]);$columns=array_map(fn($x)=>$this->id(trim($x," `\"\t\n\r")),$this->splitTop($m[2]));$valuesPart=trim($m[3]);$updatePart=trim((string)($m[4]??''));
        $groups=$this->valueGroups($valuesPart);$paramIndex=0;$affected=0;
        foreach($groups as $g){
            $tokens=$this->splitTop($g);if(count($tokens)!==count($columns))throw new RuntimeException('CSV-Adminspeicher: INSERT-Spalten/Werte passen nicht zusammen.');
            $row=[];foreach($columns as $i=>$col)$row[$col]=$this->resolveValue($tokens[$i],$params,$paramIndex);
            $row=$this->withDefaults($table,$row);
            $dup=$this->findDuplicate($table,$row);
            if($dup){
                if($ignore){continue;}
                if($updatePart!==''){
                    $changes=$this->parseUpdateAssignments($table,$updatePart,$params,$paramIndex,$row);
                    if($changes!==[]) {$this->db->update($table,(int)$dup['id'],$changes);$affected++;}
                    $this->lastId=(int)$dup['id'];
                    continue;
                }
                throw new RuntimeException('CSV-Adminspeicher: Eindeutiger Schlüssel bereits vorhanden in '.$table.'.');
            }
            $explicitId=isset($row['id'])?(int)$row['id']:0;unset($row['id']);
            if($explicitId>0 && method_exists($this->db,'insertWithId'))$id=$this->db->insertWithId($table,$explicitId,$row); else $id=$this->db->insert($table,$row);
            $this->lastId=$id;$affected++;
        }
        return ['rows'=>[],'rowCount'=>$affected];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function updateSql(string $sql,array $params): array
    {
        if(!preg_match('/^UPDATE\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is',$sql,$m))throw new RuntimeException('CSV-Adminspeicher: UPDATE konnte nicht analysiert werden: '.$this->shortSql($sql));
        $table=$this->id($m[1]);$set=trim($m[2]);$where=trim((string)($m[3]??''));$assignments=$this->splitTop($set);$rows=$this->rows($table);$n=0;
        foreach($rows as $r){$setPi=0;$changes=[];
            // SQL parameters in SET occur before WHERE; resolve from beginning.
            foreach($assignments as $a){if(!preg_match('/^([A-Za-z0-9_`.]+)\s*=\s*(.+)$/is',$a,$am))continue;$col=$this->bareColumn($am[1]);$expr=trim($am[2]);
                if(preg_match('/^IF\(\s*'.preg_quote($col,'/').'\s*=\s*1\s*,\s*0\s*,\s*1\s*\)$/i',$expr))$val=((int)($r[$col]??0)===1)?'0':'1';
                elseif(strtoupper($expr)==='CURRENT_TIMESTAMP')$val=$this->now();
                elseif(preg_match('/^VALUES\(/i',$expr))continue;
                else $val=$this->resolveValue($expr,$params,$setPi);
                $changes[$col]=$val;
            }
            // WHERE params start after SET placeholders.
            $whereStart=$setPi;$wherePi=$whereStart;if($where!==''&&!$this->matches($r,$where,$params,$wherePi))continue;
            if(isset(self::SCHEMA[$table]['columns'])&&in_array('updated_at',self::SCHEMA[$table]['columns'],true)&&!isset($changes['updated_at']))$changes['updated_at']=$this->now();
            $this->assertUniqueUpdate($table,(int)$r['id'],$changes+$r);
            if($changes!==[]&&$this->db->update($table,(int)$r['id'],$changes))$n++;
        }
        return ['rows'=>[],'rowCount'=>$n];
    }

    /** @return array{rows:list<array<string,mixed>>,rowCount:int} */
    private function deleteSql(string $sql,array $params): array
    {
        if(!preg_match('/^DELETE\s+FROM\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?(?:\s+WHERE\s+(.+))?$/is',$sql,$m))throw new RuntimeException('CSV-Adminspeicher: DELETE konnte nicht analysiert werden: '.$this->shortSql($sql));
        $table=$this->id($m[1]);$where=trim((string)($m[2]??''));$rows=$this->rows($table);$ids=[];
        foreach($rows as $r){$local=0;if($where===''||$this->matches($r,$where,$params,$local))$ids[]=(int)$r['id'];}
        foreach($ids as $id){$this->cascadeBeforeDelete($table,$id);$this->db->delete($table,$id);}return ['rows'=>[],'rowCount'=>count($ids)];
    }

    private function ensureTable(string $table,array $columns): void
    {
        $table=$this->id($table);$schema=self::SCHEMA[$table]['columns']??[];$columns=array_values(array_unique(array_filter(array_merge($schema,$columns),fn($c)=>$c!==''&&$c!=='id')));
        if(!$this->db->tableExists($table)){$this->db->createTable($table,$columns);return;}
        $existing=$this->db->columns($table);foreach($columns as $c)if($c!=='id'&&!in_array($c,$existing,true))$this->db->addColumn($table,$c,$this->defaultFor($table,$c));
    }

    private function seedCore(): void
    {
        foreach([['superadmin','Superadministrator'],['admin','Administrator'],['editor','Bearbeiter'],['viewer','Leser']] as [$name,$label]) if(!$this->findBy('roles',['name'=>$name]))$this->insertRow('roles',['name'=>$name,'label'=>$label]);
        foreach([['dataform','DataForm','available','5-dev'],['calendar','easyITCalendar','available','2.0'],['dialog','Dialog','planned',''],['nachhilfe','Nachhilfe','planned',''],['csv-engine','CSV-Engine','available','RC1.1']] as $p) if(!$this->findBy('installed_products',['product_key'=>$p[0]]))$this->insertRow('installed_products',['product_key'=>$p[0],'label'=>$p[1],'status'=>$p[2],'version'=>$p[3]]);
    }

    private function rows(string $table): array { $table=$this->id($table);return $this->db->tableExists($table)?$this->db->all($table):[]; }
    private function findById(string $table,int $id): ?array { return $this->db->tableExists($table)?$this->db->find($table,$id):null; }
    private function findBy(string $table,array $criteria): ?array { foreach($this->rows($table) as $r){$ok=true;foreach($criteria as $k=>$v)if((string)($r[$k]??'')!==(string)$v){$ok=false;break;}if($ok)return$r;}return null; }
    private function roleNamesForUser(int $uid): array {$names=[];foreach($this->rows('user_roles') as $ur)if((int)$ur['user_id']===$uid){$r=$this->findById('roles',(int)$ur['role_id']);if($r)$names[]=(string)$r['name'];}return array_values(array_unique($names));}

    private function insertRow(string $table,array $row): int
    {
        $this->ensureTable($table,self::SCHEMA[$table]['columns']??array_keys($row));$row=$this->withDefaults($table,$row);$dup=$this->findDuplicate($table,$row);if($dup)return(int)$dup['id'];
        $id=$this->db->insert($table,$row);$this->lastId=$id;return$id;
    }

    private function withDefaults(string $table,array $row): array
    {
        $this->ensureTable($table,self::SCHEMA[$table]['columns']??array_keys($row));
        foreach((self::SCHEMA[$table]['defaults']??[]) as $k=>$v)if(!array_key_exists($k,$row))$row[$k]=$v==='@now'?$this->now():$v;
        foreach($this->db->columns($table) as $c)if($c!=='id'&&!array_key_exists($c,$row))$row[$c]='';
        return$row;
    }

    private function defaultFor(string $table,string $column): mixed {$v=self::SCHEMA[$table]['defaults'][$column]??'';return$v==='@now'?$this->now():$v;}
    private function now(): string { return date('Y-m-d H:i:s'); }

    private function findDuplicate(string $table,array $row): ?array
    {
        foreach(self::SCHEMA[$table]['unique']??[] as $cols){$skip=false;foreach($cols as $c)if(!array_key_exists($c,$row)||(in_array($c,['email','df_uuid'],true)&&trim((string)$row[$c])==='')){$skip=true;break;}if($skip)continue;foreach($this->rows($table) as $existing){$same=true;foreach($cols as $c)if((string)($existing[$c]??'')!==(string)$row[$c]){$same=false;break;}if($same)return$existing;}}
        return null;
    }
    private function assertUniqueUpdate(string $table,int $id,array $row): void { $dup=$this->findDuplicate($table,$row);if($dup&&(int)$dup['id']!==$id)throw new RuntimeException('CSV-Adminspeicher: Eindeutiger Schlüssel bereits vorhanden in '.$table.'.'); }

    private function cascadeBeforeDelete(string $table,int $id): void
    {
        if($table==='users'){
            foreach($this->rows('user_roles') as $r)if((int)$r['user_id']===$id)$this->db->delete('user_roles',(int)$r['id']);
            foreach($this->rows('audit_log') as $r)if((int)($r['user_id']??0)===$id)$this->db->update('audit_log',(int)$r['id'],['user_id'=>'']);
        }elseif($table==='roles'){
            foreach($this->rows('user_roles') as $r)if((int)$r['role_id']===$id)$this->db->delete('user_roles',(int)$r['id']);
            foreach($this->rows('role_capabilities') as $r)if((int)$r['role_id']===$id)$this->db->delete('role_capabilities',(int)$r['id']);
        }elseif($table==='capabilities'){
            foreach($this->rows('role_capabilities') as $r)if((int)$r['capability_id']===$id)$this->db->delete('role_capabilities',(int)$r['id']);
        }
    }

    private function parseCreateColumns(string $body): array
    {
        $cols=[];foreach($this->splitTop($body) as $part){$p=trim($part);if($p===''||preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i',$p))continue;if(preg_match('/^[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s+/',$p,$m)&&strtolower($m[1])!=='id')$cols[]=$this->id($m[1]);}return$cols;
    }

    /** @return list<string> */
    private function splitTop(string $s): array
    {
        $out=[];$buf='';$depth=0;$quote=null;$len=strlen($s);
        for($i=0;$i<$len;$i++){$ch=$s[$i];if($quote!==null){$buf.=$ch;if($ch===$quote){if($i+1<$len&&$s[$i+1]===$quote){$buf.=$s[++$i];}else$quote=null;}continue;}if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}if($ch==='('){$depth++;$buf.=$ch;continue;}if($ch===')'){$depth--;$buf.=$ch;continue;}if($ch===','&&$depth===0){$out[]=trim($buf);$buf='';continue;}$buf.=$ch;}if(trim($buf)!=='')$out[]=trim($buf);return$out;
    }

    /** @return list<string> */
    private function valueGroups(string $s): array
    {
        $groups=[];$depth=0;$quote=null;$start=null;$len=strlen($s);
        for($i=0;$i<$len;$i++){$ch=$s[$i];if($quote!==null){if($ch===$quote){if($i+1<$len&&$s[$i+1]===$quote){$i++;}else$quote=null;}continue;}if($ch==="'"||$ch==='"'){$quote=$ch;continue;}if($ch==='('){if($depth===0)$start=$i+1;$depth++;}elseif($ch===')'){$depth--;if($depth===0&&$start!==null){$groups[]=substr($s,$start,$i-$start);$start=null;}}}return$groups;
    }

    private function resolveValue(string $token,array $params,int &$pi): mixed
    {
        $t=trim($token);
        if($t==='?')return$params[$pi++]??null;
        if(preg_match('/^NULLIF\(\s*\?\s*,\s*[\'\"]\s*[\'\"]\s*\)$/i',$t)){ $v=$params[$pi++]??null;return trim((string)$v)===''?'':$v; }
        if(strtoupper($t)==='NULL')return'';
        if(strtoupper($t)==='CURRENT_TIMESTAMP')return$this->now();
        if(preg_match('/^-?\d+(?:\.\d+)?$/',$t))return$t;
        if((str_starts_with($t,"'")&&str_ends_with($t,"'"))||(str_starts_with($t,'"')&&str_ends_with($t,'"'))){$q=$t[0];$v=substr($t,1,-1);return str_replace($q.$q,$q,$v);}
        return trim($t,'`');
    }

    private function parseUpdateAssignments(string $table,string $part,array $params,int &$pi,array $inserted): array
    {
        $changes=[];foreach($this->splitTop($part) as $a){if(!preg_match('/^([A-Za-z0-9_`.]+)\s*=\s*(.+)$/is',$a,$m))continue;$c=$this->bareColumn($m[1]);$expr=trim($m[2]);if(preg_match('/^VALUES\(\s*([A-Za-z0-9_`]+)\s*\)$/i',$expr,$vm))$changes[$c]=$inserted[$this->id($vm[1])]??'';elseif(strtoupper($expr)==='CURRENT_TIMESTAMP')$changes[$c]=$this->now();elseif($expr===$c||$this->bareColumn($expr)===$c)continue;else$changes[$c]=$this->resolveValue($expr,$params,$pi);}return$changes;
    }

    /** @return array{0:string,1:string,2:?int,3:int} */
    private function parseTail(string $tail): array
    {
        $tail=preg_replace('/\s+FOR\s+UPDATE\s*$/i','',$tail)??$tail;$where='';$order='';$limit=null;$offset=0;
        if(preg_match('/\bWHERE\b\s+(.+?)(?=\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/is',$tail,$m))$where=trim($m[1]);
        if(preg_match('/\bORDER\s+BY\b\s+(.+?)(?=\s+LIMIT|\s+OFFSET|$)/is',$tail,$m))$order=trim($m[1]);
        if(preg_match('/\bLIMIT\s+(\d+)/i',$tail,$m))$limit=(int)$m[1];
        if(preg_match('/\bOFFSET\s+(\d+)/i',$tail,$m))$offset=(int)$m[1];
        return[$where,$order,$limit,$offset];
    }

    private function matches(array $row,string $where,array $params,int &$pi,bool $resetPerRow=false): bool
    {
        $start=$pi;$expr=trim($where);
        if($this->outerParensWrap($expr)) $expr=trim(substr($expr,1,-1));
        // OR has lower precedence. Split top-level only.
        $ors=$this->splitLogical($expr,'OR');
        if(count($ors)>1){
            $consumed=$this->countPlaceholders($expr);
            $offset=$start;
            foreach($ors as $part){$local=$offset;if($this->matches($row,$part,$params,$local,false)){if(!$resetPerRow)$pi=$start+$consumed;return true;}$offset += $this->countPlaceholders($part);}
            if(!$resetPerRow)$pi=$start+$consumed;return false;
        }
        $ands=$this->splitLogical($expr,'AND');
        if(count($ands)>1){$local=$start;foreach($ands as $part)if(!$this->matches($row,$part,$params,$local,false)){if(!$resetPerRow)$pi=$start+$this->countPlaceholders($expr);return false;}if(!$resetPerRow)$pi=$local;return true;}
        $c=trim($expr);
        if($this->outerParensWrap($c))$c=trim(substr($c,1,-1));
        if(preg_match('/^\?\s*<>\s*[\'\"]\s*[\'\"]$/',$c)){ $v=(string)($params[$pi++]??'');return$v!==''; }
        if(preg_match('/^([A-Za-z0-9_`.]+)\s+IS\s+NOT\s+NULL$/i',$c,$m))return(string)($row[$this->bareColumn($m[1])]??'')!=='';
        if(preg_match('/^([A-Za-z0-9_`.]+)\s+IS\s+NULL$/i',$c,$m))return(string)($row[$this->bareColumn($m[1])]??'')==='';
        if(preg_match('/^([A-Za-z0-9_`.]+)\s+IN\s*\(([^)]*)\)$/i',$c,$m)){$col=$this->bareColumn($m[1]);$tokens=$this->splitTop($m[2]);$vals=[];foreach($tokens as $t)$vals[]=(string)$this->resolveValue($t,$params,$pi);return in_array((string)($row[$col]??''),$vals,true);}
        if(preg_match('/^CAST\(\s*([A-Za-z0-9_`.]+)\s+AS\s+CHAR\s*\)\s+LIKE\s+(.+)$/i',$c,$m)){$col=$this->bareColumn($m[1]);$rv=(string)$this->resolveValue(trim($m[2]),$params,$pi);$re='/^'.str_replace(['%','_'],['.*','.'],preg_quote($rv,'/')).'$/iu';return preg_match($re,(string)($row[$col]??''))===1;}
        if(preg_match('/^([A-Za-z0-9_`.]+)\s*(>=|<=|>|<)\s*(.+)$/is',$c,$m)){$col=$this->bareColumn($m[1]);$op=$m[2];$rv=(string)$this->resolveValue(trim($m[3]),$params,$pi);$lv=(string)($row[$col]??'');$cmp=strcmp($lv,$rv);return $op==='>='?$cmp>=0:($op==='<='?$cmp<=0:($op==='>'?$cmp>0:$cmp<0));}
        if(preg_match('/^([A-Za-z0-9_`.]+)\s*(LIKE|=|<>|!=)\s*(.+)$/is',$c,$m)){$col=$this->bareColumn($m[1]);$op=strtoupper($m[2]);$right=$this->resolveValue(trim($m[3]),$params,$pi);$left=(string)($row[$col]??'');$rv=(string)$right;if($op==='=')return$left===$rv;if($op==='<>'||$op==='!=')return$left!==$rv;if($op==='LIKE'){ $re='/^'.str_replace(['%','_'],['.*','.'],preg_quote($rv,'/')).'$/iu';return preg_match($re,$left)===1;}}
        throw new RuntimeException('CSV-Adminspeicher: WHERE-Ausdruck nicht unterstützt: '.$c);
    }


    /** @return list<string> */
    private function splitLogical(string $expr,string $op): array
    {
        $out=[];$buf='';$depth=0;$quote=null;$i=0;$len=strlen($expr);$needle=' '.$op.' ';$nlen=strlen($needle);
        while($i<$len){$ch=$expr[$i];if($quote!==null){$buf.=$ch;if($ch===$quote){if($i+1<$len&&$expr[$i+1]===$quote){$buf.=$expr[++$i];}else$quote=null;}$i++;continue;}if($ch==="'"||$ch==='"'){$quote=$ch;$buf.=$ch;$i++;continue;}if($ch==='('){$depth++;$buf.=$ch;$i++;continue;}if($ch===')'){$depth--;$buf.=$ch;$i++;continue;}if($depth===0&&strtoupper(substr($expr,$i,$nlen))===$needle){$out[]=trim($buf);$buf='';$i+=$nlen;continue;}$buf.=$ch;$i++;}if(trim($buf)!=='')$out[]=trim($buf);return$out;
    }
    private function outerParensWrap(string $s): bool
    {
        $s=trim($s);$len=strlen($s);if($len<2||$s[0]!=='('||$s[$len-1]!==')')return false;$depth=0;$quote=null;
        for($i=0;$i<$len;$i++){$ch=$s[$i];if($quote!==null){if($ch===$quote){if($i+1<$len&&$s[$i+1]===$quote)$i++;else$quote=null;}continue;}if($ch==="'"||$ch==='"'){$quote=$ch;continue;}if($ch==='(')$depth++;elseif($ch===')'){$depth--;if($depth===0&&$i<$len-1)return false;}}
        return $depth===0;
    }
    private function countPlaceholders(string $s): int { return substr_count($s,'?'); }

    private function projectRows(array $rows,string $fields): array
    {
        if(trim($fields)==='*')return$rows;$specs=$this->splitTop($fields);$out=[];foreach($rows as $r){$x=[];foreach($specs as $spec){$spec=trim($spec);if($spec==='*'||preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.\*$/',$spec)){foreach($r as $k=>$v)$x[$k]=$v;continue;}if(preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?([A-Za-z_][A-Za-z0-9_]*)(?:\s+AS\s+|\s+)([A-Za-z_][A-Za-z0-9_]*)$/i',$spec,$m)){$x[$m[2]]=$r[$m[1]]??'';}else{$c=$this->bareColumn($spec);$x[$c]=$r[$c]??'';}}$out[]=$x;}return$out;
    }
    private function projectRow(array $row,array $fields): array {$o=[];foreach($fields as $f)$o[$f]=$row[$f]??'';return$o;}

    private function sortRows(array $rows,string $order): array
    {
        $terms=$this->splitTop($order);usort($rows,function($a,$b)use($terms){foreach($terms as $t){if(!preg_match('/^([A-Za-z0-9_`.]+)(?:\s+(ASC|DESC))?/i',trim($t),$m))continue;$c=$this->bareColumn($m[1]);$dir=strtoupper($m[2]??'ASC');$av=$a[$c]??'';$bv=$b[$c]??'';$cmp=(is_numeric($av)&&is_numeric($bv))?((float)$av<=>(float)$bv):strnatcasecmp((string)$av,(string)$bv);if($cmp!==0)return$dir==='DESC'?-$cmp:$cmp;}return 0;});return$rows;
    }

    private function normalizeSql(string $sql): string { return trim(preg_replace('/\s+/u',' ',$sql)??$sql," ;\t\n\r"); }
    private function shortSql(string $sql): string { $s=$this->normalizeSql($sql);return strlen($s)>220?substr($s,0,217).'...':$s; }
    private function bareColumn(string $s): string { $s=trim($s," `\"\t\n\r");if(str_contains($s,'.'))$s=substr($s,strrpos($s,'.')+1);return$this->id($s); }
    private function id(string $s): string { $s=trim($s," `\"\t\n\r");if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$s))throw new InvalidArgumentException('Ungültiger CSV-SQL-Bezeichner: '.$s);return$s; }
}

final class EnterpriseCsvPdoStatement extends PDOStatement
{
    private array $rows=[];
    private int $cursor=0;
    private int $affected=0;
    private int $fetchMode=PDO::FETCH_ASSOC;

    public function __construct(private EnterpriseCsvPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $result=$this->pdo->executeSql($this->sql,array_values($params??[]));
        $this->rows=$result['rows'];$this->affected=$result['rowCount'];$this->cursor=0;return true;
    }
    public function setFetchMode(int $mode, mixed ...$args): true { $this->fetchMode=$mode;return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if(!isset($this->rows[$this->cursor]))return false;$row=$this->rows[$this->cursor++];return$this->format($row,$mode===PDO::FETCH_DEFAULT?$this->fetchMode:$mode);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $mode=$mode===PDO::FETCH_DEFAULT?$this->fetchMode:$mode;$rows=array_slice($this->rows,$this->cursor);$this->cursor=count($this->rows);
        if($mode===PDO::FETCH_COLUMN){$column=(int)($args[0]??0);return array_map(fn($r)=>array_values($r)[$column]??false,$rows);}return array_map(fn($r)=>$this->format($r,$mode),$rows);
    }
    public function fetchColumn(int $column = 0): mixed { if(!isset($this->rows[$this->cursor]))return false;$row=$this->rows[$this->cursor++];return array_values($row)[$column]??false; }
    public function rowCount(): int { return $this->affected; }
    private function format(array $row,int $mode): mixed
    {
        if($mode===PDO::FETCH_NUM)return array_values($row);if($mode===PDO::FETCH_COLUMN)return array_values($row)[0]??false;if($mode===PDO::FETCH_BOTH){$both=$row;foreach(array_values($row) as $i=>$v)$both[$i]=$v;return$both;}return$row;
    }
}
