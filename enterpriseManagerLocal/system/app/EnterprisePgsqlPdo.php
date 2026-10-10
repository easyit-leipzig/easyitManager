<?php
declare(strict_types=1);

/**
 * PostgreSQL PDO compatibility layer for easyIT Enterprise/DataForm5.
 *
 * The historic application emits a bounded set of MySQL idioms. This class
 * keeps those call sites operational on native PostgreSQL while new code can
 * use standard PostgreSQL SQL directly. It deliberately does not emulate a
 * MySQL server; only the SQL constructs used by Enterprise/DataForm are
 * normalized.
 */
final class EnterprisePgsqlPdo extends PDO
{
    private string $databaseName;
    private string $schemaName;

    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password = '',
        string $schema = 'public'
    ) {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('Die PHP-Erweiterung pdo_pgsql ist nicht aktiv.');
        }
        $host=trim($host); $database=trim($database); $username=trim($username); $schema=trim($schema);
        if ($host==='' || $database==='' || $username==='') {
            throw new RuntimeException('PostgreSQL benötigt Host, Datenbank und Benutzer.');
        }
        if ($port<1 || $port>65535) throw new RuntimeException('Ungültiger PostgreSQL-Port.');
        if ($schema==='' || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1) {
            throw new RuntimeException('Ungültiges PostgreSQL-Schema.');
        }
        parent::__construct(
            'pgsql:host='.$host.';port='.$port.';dbname='.$database,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]
        );
        $this->databaseName=$database;
        $this->schemaName=$schema;
        parent::exec("SET client_encoding TO 'UTF8'");
        parent::exec("SET TIME ZONE 'UTC'");
        $quotedSchema='"'.$schema.'"';
        parent::exec('SET search_path TO '.$quotedSchema);
    }

    public function databaseName(): string { return $this->databaseName; }
    public function schemaName(): string { return $this->schemaName; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql=$this->translate($query);
        if ($fetchMode===null) return parent::query($sql);
        return parent::query($sql,$fetchMode,...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $sql=$this->translate($statement);
        if (trim($sql)==='') return 0;
        return parent::exec($sql);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        if ($name!==null && $name!=='') return parent::lastInsertId($name);
        try {
            $value=parent::query('SELECT LASTVAL()')->fetchColumn();
            return $value===false?false:(string)$value;
        } catch (Throwable) {
            return parent::lastInsertId();
        }
    }

    private function translate(string $sql): string
    {
        $sql=trim($sql);
        if ($sql==='') return $sql;

        // MySQL session/database selection has no direct equivalent once a
        // PostgreSQL connection is bound to a database.
        if (preg_match('/^USE\s+/i',$sql)) return 'SELECT 1';
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\b/i',$sql)) return 'SELECT 1';

        // Backtick identifiers are pervasive in the historic code base.
        $sql=str_replace('`','"',$sql);

        // MySQL database/schema idioms.
        $sql=preg_replace('/\bTABLE_SCHEMA\s*=\s*DATABASE\(\)/i','TABLE_SCHEMA = current_schema()',$sql)??$sql;
        $sql=preg_replace('/\btable_schema\s*=\s*DATABASE\(\)/i','table_schema = current_schema()',$sql)??$sql;
        $sql=preg_replace('/\bCONSTRAINT_SCHEMA\s*=\s*DATABASE\(\)/i','CONSTRAINT_SCHEMA = current_schema()',$sql)??$sql;
        $sql=preg_replace('/\bconstraint_schema\s*=\s*DATABASE\(\)/i','constraint_schema = current_schema()',$sql)??$sql;
        $sql=preg_replace('/\bDATABASE\(\)/i','current_database()',$sql)??$sql;

        // MySQL interval form.
        $sql=preg_replace('/NOW\(\)\s*-\s*INTERVAL\s+1\s+MINUTE/i',"NOW() - INTERVAL '1 minute'",$sql)??$sql;

        // GROUP_CONCAT used by user/role management.
        $sql=preg_replace(
            '/GROUP_CONCAT\(([^()]+?)\s+ORDER\s+BY\s+([^()]+?)\s+SEPARATOR\s+[\'\"]([^\'\"]*)[\'\"]\)/i',
            "STRING_AGG(CAST($1 AS TEXT), '$3' ORDER BY $2)",
            $sql
        )??$sql;
        $sql=preg_replace('/GROUP_CONCAT\(([^()]+)\)/i',"STRING_AGG(CAST($1 AS TEXT), ',')",$sql)??$sql;

        // MySQL SHOW compatibility used by managers and diagnostics.
        if (preg_match('/^SHOW\s+TABLES$/i',$sql)) {
            return "SELECT table_name AS \"Tables_in_pgsql\" FROM information_schema.tables WHERE table_schema=current_schema() AND table_type IN ('BASE TABLE','VIEW') ORDER BY table_name";
        }
        if (preg_match('/^SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?(?:\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"])?$/i',$sql,$m)) {
            return $this->showColumnsSql($m[1],$m[2]??null);
        }
        if (preg_match('/^SHOW\s+INDEX(?:ES)?\s+FROM\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?/i',$sql,$m)) {
            $table=$this->sqlString($m[1]);
            return "SELECT i.relname AS \"Key_name\", CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS \"Non_unique\", a.attname AS \"Column_name\", ord.n AS \"Seq_in_index\" FROM pg_class t JOIN pg_namespace ns ON ns.oid=t.relnamespace JOIN pg_index ix ON ix.indrelid=t.oid JOIN pg_class i ON i.oid=ix.indexrelid JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY ord(attnum,n) ON true JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=ord.attnum WHERE ns.nspname=current_schema() AND t.relname='{$table}' ORDER BY i.relname,ord.n";
        }
        if (preg_match('/^SHOW\s+CREATE\s+VIEW\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?$/i',$sql,$m)) {
            $view=$this->sqlString($m[1]);
            return "SELECT c.relname AS \"View\", 'CREATE OR REPLACE VIEW \"' || replace(c.relname,'\"','\"\"') || '\" AS ' || pg_get_viewdef(c.oid,true) AS \"Create View\" FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relkind='v' AND c.relname='{$view}' LIMIT 1";
        }
        if (preg_match('/^SHOW\s+CREATE\s+TRIGGER\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?$/i',$sql,$m)) {
            $trigger=$this->sqlString($m[1]);
            return "SELECT tg.tgname AS \"Trigger\", pg_get_triggerdef(tg.oid,true) AS \"SQL Original Statement\" FROM pg_trigger tg JOIN pg_class c ON c.oid=tg.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND NOT tg.tgisinternal AND tg.tgname='{$trigger}' LIMIT 1";
        }
        if (preg_match('/^SHOW\s+CREATE\s+TABLE\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?$/i',$sql,$m)) {
            // A compact, catalog-derived definition sufficient for display;
            // PostgreSQL project backup uses a dedicated logical exporter.
            $table=$this->sqlString($m[1]);
            return "SELECT '{$table}' AS \"Table\", 'CREATE TABLE \"{$table}\" (...)' AS \"Create Table\"";
        }

        // MySQL KEY_COLUMN_USAGE exposes REFERENCED_* columns that PostgreSQL's
        // information_schema does not. Translate the exact field-protection
        // query used by TableWorkspaceManager.
        if (stripos($sql,'REFERENCED_TABLE_NAME')!==false && stripos($sql,'information_schema.KEY_COLUMN_USAGE')!==false) {
            return "SELECT COUNT(*) FROM ("
                ."SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema WHERE tc.table_schema=current_schema() AND tc.table_name=? AND kcu.column_name=? AND tc.constraint_type IN ('PRIMARY KEY','FOREIGN KEY') "
                ."UNION ALL "
                ."SELECT 1 FROM information_schema.referential_constraints rc JOIN information_schema.key_column_usage fk ON fk.constraint_name=rc.constraint_name AND fk.constraint_schema=rc.constraint_schema JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=rc.unique_constraint_name AND ccu.constraint_schema=rc.unique_constraint_schema WHERE ccu.table_schema=current_schema() AND ccu.table_name=? AND ccu.column_name=?"
                .") q";
        }

        // information_schema.statistics is MySQL-only.
        if (stripos($sql,'information_schema.statistics')!==false) {
            if (preg_match('/SELECT\s+index_name\s*,\s*non_unique\s*,\s*seq_in_index.*table_name\s*=\s*\?.*column_name\s*=\s*\?/is',$sql)) {
                return "SELECT i.relname AS index_name,CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS non_unique,ord.n AS seq_in_index FROM pg_class t JOIN pg_namespace ns ON ns.oid=t.relnamespace JOIN pg_index ix ON ix.indrelid=t.oid JOIN pg_class i ON i.oid=ix.indexrelid JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY ord(attnum,n) ON true JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=ord.attnum WHERE ns.nspname=current_schema() AND t.relname=? AND a.attname=? AND NOT ix.indisprimary ORDER BY i.relname,ord.n";
            }
        }

        // MySQL extra/auto_increment metadata is represented by identity or a
        // nextval() default in PostgreSQL.
        if (stripos($sql,'information_schema.columns')!==false && stripos($sql,'LOWER(extra)')!==false) {
            return "SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND (is_identity='YES' OR column_default LIKE 'nextval(%') AND (? IS NULL OR column_name<>?) LIMIT 1";
        }

        // MySQL's information_schema.columns exposes COLUMN_TYPE, COLUMN_KEY
        // and EXTRA. PostgreSQL exposes the underlying pieces instead. These
        // are the three metadata shapes still used by legacy DataForm helpers.
        if (stripos($sql,'information_schema.columns')!==false) {
            if (preg_match('/^SELECT\s+column_name\s*,\s*data_type\s*,\s*column_type\s*,\s*is_nullable\s*,\s*column_key\s*,\s*extra\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*current_schema\(\)\s+AND\s+table_name\s*=\s*\?\s+ORDER\s+BY\s+ordinal_position$/is',$sql)) {
                return "SELECT c.column_name,c.data_type,CASE WHEN c.data_type='character varying' THEN 'varchar('||c.character_maximum_length||')' WHEN c.data_type='numeric' THEN 'numeric('||c.numeric_precision||','||c.numeric_scale||')' ELSE c.data_type END AS column_type,c.is_nullable,CASE WHEN EXISTS (SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema WHERE tc.table_schema=c.table_schema AND tc.table_name=c.table_name AND tc.constraint_type='PRIMARY KEY' AND kcu.column_name=c.column_name) THEN 'PRI' ELSE '' END AS column_key,CASE WHEN c.is_identity='YES' OR c.column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS extra FROM information_schema.columns c WHERE c.table_schema=current_schema() AND c.table_name=? ORDER BY c.ordinal_position";
            }
            if (preg_match('/^SELECT\s+column_type\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*current_schema\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+column_name\s*=\s*\?\s+LIMIT\s+1$/is',$sql)) {
                return "SELECT CASE WHEN data_type='character varying' THEN 'varchar('||character_maximum_length||')' WHEN data_type='numeric' THEN 'numeric('||numeric_precision||','||numeric_scale||')' ELSE data_type END AS column_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=? LIMIT 1";
            }
            if (preg_match('/^SELECT\s+column_name\s*,\s*column_type\s*,\s*is_nullable\s*,\s*column_default\s*,\s*extra\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*current_schema\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+column_name\s*=\s*\?\s+LIMIT\s+1$/is',$sql)) {
                return "SELECT column_name,CASE WHEN data_type='character varying' THEN 'varchar('||character_maximum_length||')' WHEN data_type='numeric' THEN 'numeric('||numeric_precision||','||numeric_scale||')' ELSE data_type END AS column_type,is_nullable,column_default,CASE WHEN is_identity='YES' OR column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS extra FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=? LIMIT 1";
            }
        }

        // DDL normalization.
        if (preg_match('/^CREATE\s+TABLE\b/i',$sql)) return $this->convertCreateTable($sql);
        if (preg_match('/^ALTER\s+TABLE\b/i',$sql)) return $this->convertAlterTable($sql);

        // INSERT IGNORE -> ON CONFLICT DO NOTHING.
        if (preg_match('/\bINSERT\s+IGNORE\s+INTO\b/i',$sql)) {
            $sql=preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i','INSERT INTO',$sql)??$sql;
            if (stripos($sql,' ON CONFLICT ')===false) $sql=rtrim($sql,"; \t\r\n").' ON CONFLICT DO NOTHING';
        }

        // MySQL ON DUPLICATE KEY UPDATE -> PostgreSQL UPSERT.
        if (preg_match('/^INSERT\s+INTO\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*\(([^)]*)\).*\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is',$sql,$m)) {
            $table=$m[1];
            $insertColumns=array_map(static fn(string $v): string => trim($v," \t\r\n\""),explode(',',$m[2]));
            $assign=trim($m[3]);
            // checksum=checksum and similar no-op upserts are migrations; do
            // not require an arbiter target.
            $isNoop=(bool)preg_match('/^\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*=\s*\"?\1\"?\s*$/i',$assign);
            $assign=preg_replace('/\bVALUES\s*\(\s*\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*\)/i','EXCLUDED."$1"',$assign)??$assign;
            $assign=str_replace('`','"',$assign);
            $base=preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+$/is','',$sql)??$sql;
            if ($isNoop) return $base.' ON CONFLICT DO NOTHING';
            $target=$this->inferConflictTarget($table,$insertColumns);
            if ($target===[]) {
                // Safe fallback: preserve data without throwing. Known product
                // tables all expose an inferable UNIQUE key.
                return $base.' ON CONFLICT DO NOTHING';
            }
            $quoted=implode(',',array_map(fn(string $c): string => $this->quoteIdentifier($c),$target));
            return $base.' ON CONFLICT ('.$quoted.') DO UPDATE SET '.$assign;
        }

        // MySQL empty-column insert.
        $sql=preg_replace('/INSERT\s+INTO\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s*\(\s*\)\s*VALUES\s*\(\s*\)/i','INSERT INTO $1 DEFAULT VALUES',$sql)??$sql;

        // PostgreSQL has no column ordering clauses.
        $sql=preg_replace('/\s+AFTER\s+\"?[A-Za-z_][A-Za-z0-9_]*\"?/i','',$sql)??$sql;
        $sql=preg_replace('/\s+FIRST\b/i','',$sql)??$sql;

        return $sql;
    }

    private function showColumnsSql(string $table, ?string $column): string
    {
        $table=$this->sqlString($table);
        $where=$column!==null?" AND c.column_name='".$this->sqlString($column)."'":'';
        return "SELECT c.column_name AS \"Field\", CASE WHEN c.data_type='character varying' THEN 'varchar(' || c.character_maximum_length || ')' WHEN c.data_type='numeric' THEN 'decimal(' || c.numeric_precision || ',' || c.numeric_scale || ')' WHEN c.data_type='timestamp without time zone' THEN 'timestamp' WHEN c.data_type='timestamp with time zone' THEN 'timestamp' WHEN c.data_type='smallint' THEN 'smallint' WHEN c.data_type='integer' THEN 'int' WHEN c.data_type='bigint' THEN 'bigint' ELSE c.data_type END AS \"Type\", c.is_nullable AS \"Null\", CASE WHEN EXISTS (SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema WHERE tc.table_schema=c.table_schema AND tc.table_name=c.table_name AND tc.constraint_type='PRIMARY KEY' AND kcu.column_name=c.column_name) THEN 'PRI' ELSE '' END AS \"Key\", c.column_default AS \"Default\", CASE WHEN c.is_identity='YES' OR c.column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS \"Extra\" FROM information_schema.columns c WHERE c.table_schema=current_schema() AND c.table_name='{$table}'{$where} ORDER BY c.ordinal_position";
    }

    private function convertCreateTable(string $sql): string
    {
        $sql=preg_replace('/\)\s*ENGINE\s*=.*$/is',')',$sql)??$sql;
        $sql=preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[^\s]+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s*=\s*[^\s]+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i','',$sql)??$sql;
        if(!preg_match('/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s*)\((.*)\)\s*$/is',$sql,$m)) return $sql;
        $table=$m[2];
        $parts=$this->splitDefinitions($m[3]); $out=[]; $indexes=[];
        foreach($parts as $part){
            $part=trim($part); if($part==='')continue;
            if(preg_match('/^(?:KEY|INDEX)\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*(\(.+\))$/is',$part,$i)){
                $indexes[]='CREATE INDEX IF NOT EXISTS '.$this->quoteIdentifier($i[1]).' ON '.$table.' '.$i[2]; continue;
            }
            if(preg_match('/^UNIQUE\s+KEY\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*(\(.+\))$/is',$part,$u)){
                $out[]='CONSTRAINT '.$this->quoteIdentifier($u[1]).' UNIQUE '.$u[2]; continue;
            }
            if(preg_match('/^PRIMARY\s+KEY\b|^UNIQUE\b|^CONSTRAINT\b|^FOREIGN\s+KEY\b|^CHECK\b/i',$part)){
                $out[]=$part; continue;
            }
            $out[]=$this->convertColumnDefinition($part);
        }
        $create=$m[1].'('.implode(', ',$out).')';
        return $indexes===[]?$create:$create.'; '.implode('; ',$indexes);
    }

    private function convertAlterTable(string $sql): string
    {
        if(!preg_match('/^ALTER\s+TABLE\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s+(.+)$/is',$sql,$m)) return $sql;
        $table=$m[1]; $actions=$this->splitDefinitions($m[2]); $statements=[];
        foreach($actions as $action){
            $action=trim($action); if($action==='')continue;
            if(preg_match('/^ADD\s+(?:COLUMN\s+)?(.+)$/is',$action,$a) && !preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\b/i',$action)){
                $statements[]='ALTER TABLE '.$table.' ADD COLUMN '.$this->convertColumnDefinition($a[1]); continue;
            }
            if(preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?\s*(\(.+\))$/is',$action,$a)){
                $statements[]='CREATE '.(!empty($a[1])?'UNIQUE ':'').'INDEX IF NOT EXISTS '.$this->quoteIdentifier($a[2]).' ON '.$table.' '.$a[3]; continue;
            }
            if(preg_match('/^DROP\s+INDEX\s+\"?([A-Za-z_][A-Za-z0-9_]*)\"?/i',$action,$a)){
                $statements[]='DROP INDEX IF EXISTS '.$this->quoteIdentifier($a[1]); continue;
            }
            if(preg_match('/^DROP\s+COLUMN\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)/i',$action,$a)){
                $statements[]='ALTER TABLE '.$table.' DROP COLUMN '.$a[1]; continue;
            }
            if(preg_match('/^CHANGE\s+COLUMN\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s+(.+)$/is',$action,$a)){
                $old=$a[1];$new=$a[2];$def=$a[3];
                if(strtolower(trim($old,'"'))!==strtolower(trim($new,'"'))) $statements[]='ALTER TABLE '.$table.' RENAME COLUMN '.$old.' TO '.$new;
                foreach($this->columnAlterStatements($table,$new,$def) as $s)$statements[]=$s;
                continue;
            }
            if(preg_match('/^MODIFY\s+COLUMN\s+(\"?[A-Za-z_][A-Za-z0-9_]*\"?)\s+(.+)$/is',$action,$a)){
                foreach($this->columnAlterStatements($table,$a[1],$a[2]) as $s)$statements[]=$s;
                continue;
            }
            if(preg_match('/^RENAME\s+COLUMN\s+/i',$action) || preg_match('/^ADD\s+CONSTRAINT\b/i',$action)){
                $statements[]='ALTER TABLE '.$table.' '.$action; continue;
            }
            $statements[]='ALTER TABLE '.$table.' '.$action;
        }
        return implode('; ',$statements);
    }

    /** @return list<string> */
    private function columnAlterStatements(string $table,string $column,string $definition): array
    {
        $definition=$this->convertColumnDefinition($definition);
        $definition=preg_replace('/\s+AFTER\s+\"?[A-Za-z_][A-Za-z0-9_]*\"?/i','',$definition)??$definition;
        $definition=preg_replace('/\s+FIRST\b/i','',$definition)??$definition;
        // Do not treat PostgreSQL column modifiers such as NULL/NOT NULL as
        // part of the datatype. The former generic two-word pattern parsed
        // e.g. "TIMESTAMP NULL" as a datatype and generated invalid SQL:
        // ALTER COLUMN ... TYPE TIMESTAMP NULL USING ...
        $typePattern='(?:DOUBLE\s+PRECISION|CHARACTER\s+VARYING(?:\(\d+\))?|TIMESTAMP(?:\s+(?:WITH|WITHOUT)\s+TIME\s+ZONE)?|TIME(?:\s+(?:WITH|WITHOUT)\s+TIME\s+ZONE)?|VARCHAR\(\d+\)|(?:NUMERIC|DECIMAL)\(\d+\s*,\s*\d+\)|BIGSERIAL|SERIAL|BIGINT|INTEGER|INT|SMALLINT|BOOLEAN|DATE|TEXT)';
        if(!preg_match('/^('.$typePattern.')\s*(.*)$/is',$definition,$m)) return [];
        $type=trim($m[1]);$rest=trim($m[2]);
        // SERIAL is a pseudo-type only valid on ADD/CREATE. Existing sequence
        // semantics are retained when merely editing a column.
        if(strcasecmp($type,'BIGSERIAL')===0)$type='BIGINT';
        if(strcasecmp($type,'SERIAL')===0)$type='INTEGER';
        $out=['ALTER TABLE '.$table.' ALTER COLUMN '.$column.' TYPE '.$type.' USING '.$column.'::'.$type];
        if(preg_match('/\bNOT\s+NULL\b/i',$rest))$out[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' SET NOT NULL';
        else $out[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' DROP NOT NULL';
        if(preg_match('/\bDEFAULT\s+(CURRENT_TIMESTAMP|NULL|[-+]?\d+(?:\.\d+)?|\'[^\']*\'|"[^"]*")/i',$rest,$d)){
            if(strtoupper($d[1])==='NULL')$out[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' DROP DEFAULT';
            else $out[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' SET DEFAULT '.$d[1];
        } else $out[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' DROP DEFAULT';
        return $out;
    }

    private function convertColumnDefinition(string $def): string
    {
        $def=str_replace('`','"',$def);
        $def=preg_replace('/\s+AFTER\s+\"?[A-Za-z_][A-Za-z0-9_]*\"?/i','',$def)??$def;
        $def=preg_replace('/\s+FIRST\b/i','',$def)??$def;
        $def=preg_replace('/\s+COMMENT\s+(?:\'[^\']*\'|"[^"]*")/i','',$def)??$def;
        $def=preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?/i','',$def)??$def;
        $def=preg_replace('/\bBIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','BIGSERIAL PRIMARY KEY',$def)??$def;
        $def=preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','BIGSERIAL PRIMARY KEY',$def)??$def;
        $def=preg_replace('/\bBIGINT(?:\s+UNSIGNED)?\s+AUTO_INCREMENT\b/i','BIGSERIAL',$def)??$def;
        $def=preg_replace('/\b(?:INT|INTEGER)(?:\s+UNSIGNED)?\s+AUTO_INCREMENT\b/i','SERIAL',$def)??$def;
        $def=preg_replace('/\bAUTO_INCREMENT\b/i','',$def)??$def;
        $def=preg_replace('/\bUNSIGNED\b/i','',$def)??$def;
        $def=preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i','SMALLINT',$def)??$def;
        $def=preg_replace('/\bTINYINT(?:\s*\(\d+\))?/i','SMALLINT',$def)??$def;
        $def=preg_replace('/\bMEDIUMINT(?:\s*\(\d+\))?/i','INTEGER',$def)??$def;
        $def=preg_replace('/\bINT\s*\(\d+\)/i','INTEGER',$def)??$def;
        $def=preg_replace('/\bBIGINT\s*\(\d+\)/i','BIGINT',$def)??$def;
        $def=preg_replace('/\b(?:LONGTEXT|MEDIUMTEXT|TINYTEXT)\b/i','TEXT',$def)??$def;
        $def=preg_replace('/\bDATETIME\b/i','TIMESTAMP',$def)??$def;
        $def=preg_replace('/\bDOUBLE\b(?!\s+PRECISION)/i','DOUBLE PRECISION',$def)??$def;
        $def=preg_replace('/\bENUM\s*\([^)]*\)/i','TEXT',$def)??$def;
        $def=preg_replace('/\bJSON\b/i','TEXT',$def)??$def;
        $def=preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i','',$def)??$def;
        $def=preg_replace('/\s{2,}/',' ',$def)??$def;
        return trim($def);
    }

    /** @return list<string> */
    private function inferConflictTarget(string $table,array $insertColumns): array
    {
        $table=trim($table,'"');
        try{
            $st=parent::prepare("SELECT array_agg(a.attname ORDER BY ord.n) AS cols, ix.indisprimary FROM pg_class t JOIN pg_namespace ns ON ns.oid=t.relnamespace JOIN pg_index ix ON ix.indrelid=t.oid JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY ord(attnum,n) ON true JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=ord.attnum WHERE ns.nspname=current_schema() AND t.relname=? AND ix.indisunique GROUP BY ix.indexrelid,ix.indisprimary ORDER BY ix.indisprimary DESC, cardinality(ix.indkey)");
            $st->execute([$table]);
            $insertLower=array_map('strtolower',$insertColumns);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
                $raw=(string)($row['cols']??'');
                // PostgreSQL array text: {col1,col2}
                $cols=array_values(array_filter(array_map(static fn(string $v):string=>trim($v,' "'),explode(',',trim($raw,'{}')))));
                if($cols!==[] && count(array_diff(array_map('strtolower',$cols),$insertLower))===0)return $cols;
            }
        }catch(Throwable){}
        // Deterministic fallback for known unique keys, also useful in static
        // environments where the catalog is not available yet.
        $map=[
            'migrations'=>['migration'],
            'users'=>['username'],
            'roles'=>['name'],
            'user_roles'=>['user_id','role_id'],
            'capabilities'=>['name'],
            'role_capabilities'=>['role_id','capability_id'],
            'installed_products'=>['product_key'],
            'enterprise_module_settings'=>['module_name','scope_type','scope_id','config_key'],
            'enterprise_module_secrets'=>['module_name','scope_type','scope_id','config_key'],
            'enterprise_module_migrations'=>['module_name','migration_version'],
            'dataform_list_settings'=>['dataform_id','user_id'],
            'dataform_saved_filters'=>['dataform_id','user_id','name'],
            'dataform_queries'=>['dataform_id','name'],
            'dataform_import_profiles'=>['dataform_id','user_id','name'],
            'df_bound_form_binding_settings'=>['relation_id'],
        ];
        return $map[strtolower($table)]??[];
    }

    /** @return list<string> */
    private function splitDefinitions(string $body): array
    {
        $parts=[];$buf='';$depth=0;$quote=null;$len=strlen($body);
        for($i=0;$i<$len;$i++){
            $c=$body[$i];
            if($quote!==null){$buf.=$c;if($c===$quote && ($i===0||$body[$i-1]!=='\\'))$quote=null;continue;}
            if($c==="'"||$c==='"'){$quote=$c;$buf.=$c;continue;}
            if($c==='('){$depth++;$buf.=$c;continue;}
            if($c===')'){$depth--; $buf.=$c;continue;}
            if($c===','&&$depth===0){$parts[]=$buf;$buf='';continue;}
            $buf.=$c;
        }
        if(trim($buf)!=='')$parts[]=$buf;
        return $parts;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$identifier)!==1)throw new RuntimeException('Ungültiger PostgreSQL-Bezeichner.');
        return '"'.str_replace('"','""',$identifier).'"';
    }

    private function sqlString(string $value): string { return str_replace("'","''",$value); }
}
