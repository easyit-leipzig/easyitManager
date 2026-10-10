<?php
declare(strict_types=1);

/**
 * SQLite PDO compatibility layer for the Enterprise/DataForm application.
 *
 * The historic code base contains a limited set of MySQL idioms. This class
 * keeps application SQL portable while the storage itself is a native SQLite
 * database. Schema creation is handled explicitly per driver; runtime SQL is
 * translated only where the two dialects differ syntactically.
 */
final class EnterpriseSqlitePdo extends PDO
{
    private string $databasePath;

    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite ist nicht aktiv.');
        }
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') throw new RuntimeException('SQLite-Dateipfad fehlt.');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('SQLite-Verzeichnis konnte nicht angelegt werden: '.$dir);
        }
        if (!is_writable($dir)) throw new RuntimeException('SQLite-Verzeichnis ist nicht beschreibbar: '.$dir);

        parent::__construct('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        $this->databasePath = $path;
        parent::exec('PRAGMA foreign_keys = ON');
        parent::exec('PRAGMA busy_timeout = 5000');
        // WAL is robust for local desktop/server use. SQLite may fall back when
        // a filesystem does not support it; that is acceptable.
        try { parent::exec('PRAGMA journal_mode = WAL'); } catch (Throwable) {}

        // Compatibility functions for a few legacy runtime expressions. The
        // storage remains native SQLite; these only keep existing read/write
        // statements portable while the remaining SQL is driver-neutralized.
        if (method_exists($this, 'sqliteCreateFunction')) {
            $dbName = pathinfo($path, PATHINFO_FILENAME);
            $this->sqliteCreateFunction('DATABASE', static fn(): string => $dbName, 0);
            $this->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
            $this->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map(static fn($v): string => $v === null ? '' : (string)$v, $parts)), -1);
        }
    }

    public function storagePath(): string { return $this->databasePath; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = $this->translate($query);
        if ($fetchMode === null) return parent::query($sql);
        return parent::query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $sql = $this->translate($statement);
        if (trim($sql)==='') return 0;
        return parent::exec($sql);
    }

    private function translate(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') return $sql;

        // Session/database-selection statements have no SQLite equivalent.
        if (preg_match('/^(USE\s+|SET\s+FOREIGN_KEY_CHECKS\b)/i', $sql)) return 'SELECT 1';

        // SQLite does not support row-level SELECT ... FOR UPDATE. A write
        // transaction still serializes the destructive project operations.
        $sql = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql) ?? $sql;

        // Portable form of the one-minute API rate-limit expression.
        $sql = preg_replace('/\(NOW\(\)\s*-\s*INTERVAL\s+1\s+MINUTE\)/i', "datetime(CURRENT_TIMESTAMP,'-1 minute')", $sql) ?? $sql;

        // MySQL-specific GROUP_CONCAT ordering/separator syntax used by the
        // user administration. SQLite supports the same aggregate with an
        // explicit separator but not this inline ORDER BY form on all builds.
        $sql = preg_replace('/GROUP_CONCAT\(r\.name\s+ORDER\s+BY\s+r\.name\s+SEPARATOR\s+[\'"]([^\'"]*)[\'"]\)/i', "GROUP_CONCAT(r.name, '$1')", $sql) ?? $sql;
        $sql = preg_replace('/GROUP_CONCAT\(r\.id\s+ORDER\s+BY\s+r\.id\s+SEPARATOR\s+[\'"]([^\'"]*)[\'"]\)/i', "GROUP_CONCAT(r.id, '$1')", $sql) ?? $sql;

        if (preg_match('/^CREATE\s+TABLE\b/i',$sql)) return $this->convertCreateTable($sql);
        if (preg_match('/^ALTER\s+TABLE\s+([`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?)\s+ADD(?:\s+COLUMN)?\s+(.+)$/is',$sql,$m)) {
            return 'ALTER TABLE '.$m[1].' ADD COLUMN '.$this->convertColumnDefinition($m[2]);
        }

        // MySQL INSERT IGNORE -> SQLite INSERT OR IGNORE.
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql) ?? $sql;

        // MySQL upsert syntax -> SQLite UPSERT syntax. The application only
        // uses VALUES(column) references and no conflict target is required.
        if (preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is', $sql, $m)) {
            $assignments = preg_replace('/\bVALUES\s*\(\s*([`"A-Za-z_][`"A-Za-z0-9_]*)\s*\)/i', 'excluded.$1', trim($m[1])) ?? trim($m[1]);
            $sql = preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+$/is', ' ON CONFLICT DO UPDATE SET '.$assignments, $sql) ?? $sql;
        }

        // Empty-column INSERT used for auto-id tables.
        $sql = preg_replace('/INSERT\s+INTO\s+([`"A-Za-z_][`"A-Za-z0-9_]*)\s*\(\s*\)\s*VALUES\s*\(\s*\)/i', 'INSERT INTO $1 DEFAULT VALUES', $sql) ?? $sql;

        // SHOW COLUMNS / SHOW FULL COLUMNS. SQLite's pragma_table_info() can
        // be queried like a table and gives us a MySQL-compatible result shape.
        if (preg_match('/^SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?(?:\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"])?$/i', $sql, $m)) {
            $table = str_replace("'", "''", $m[1]);
            $where = isset($m[2]) ? " WHERE name='".str_replace("'", "''", $m[2])."'" : '';
            return "SELECT name AS Field, type AS Type, CASE WHEN [notnull]=0 THEN 'YES' ELSE 'NO' END AS [Null], CASE WHEN pk>0 THEN 'PRI' ELSE '' END AS [Key], dflt_value AS [Default], CASE WHEN pk>0 AND upper(type)='INTEGER' THEN 'auto_increment' ELSE '' END AS Extra FROM pragma_table_info('{$table}'){$where} ORDER BY cid";
        }

        if (preg_match('/^SHOW\s+CREATE\s+TABLE\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?$/i', $sql, $m)) {
            $table = str_replace("'", "''", $m[1]);
            return "SELECT name AS [Table], sql AS [Create Table] FROM sqlite_master WHERE type='table' AND name='{$table}' LIMIT 1";
        }

        if (preg_match('/^SHOW\s+CREATE\s+VIEW\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?$/i', $sql, $m)) {
            $view = str_replace("'", "''", $m[1]);
            return "SELECT name AS [View], sql AS [Create View] FROM sqlite_master WHERE type='view' AND name='{$view}' LIMIT 1";
        }

        if (preg_match('/^SHOW\s+TABLES$/i', $sql)) {
            return "SELECT name AS Tables_in_sqlite FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        }

        if (preg_match('/^SHOW\s+INDEX(?:ES)?\s+FROM\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?/i', $sql, $m)) {
            $table=str_replace("'","''",$m[1]);
            return "SELECT il.name AS Key_name, CASE WHEN il.[unique]=1 THEN 0 ELSE 1 END AS Non_unique, ii.name AS Column_name, ii.seqno+1 AS Seq_in_index FROM pragma_index_list('{$table}') il JOIN pragma_index_info(il.name) ii ORDER BY il.seq,ii.seqno";
        }

        // Project package statistics: SQLite does not expose TABLE_ROWS.
        if (preg_match('/SELECT\s+TABLE_NAME\s*,\s*TABLE_ROWS\s+FROM\s+information_schema\.TABLES\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_TYPE\s*=\s*[\'"]BASE TABLE[\'"]\s+ORDER\s+BY\s+TABLE_NAME/is', $sql)) {
            return "SELECT name AS TABLE_NAME, 0 AS TABLE_ROWS FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        }

        // Common INFORMATION_SCHEMA.TABLES patterns.
        if (stripos($sql, 'information_schema.tables') !== false) {
            if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s*$/is', $sql)) {
                return "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
            }
            if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*(\?|[\'\"][^\'\"]+[\'\"])/is', $sql, $m)) {
                return "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$m[1];
            }
            if (preg_match('/SELECT\s+table_type\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*(\?|[\'\"][^\'\"]+[\'\"])/is', $sql, $m)) {
                return "SELECT CASE type WHEN 'view' THEN 'VIEW' ELSE 'BASE TABLE' END AS table_type FROM sqlite_master WHERE name=".$m[1]." AND type IN ('table','view')";
            }
            // Any remaining simple table-name query used by the managers.
            $sql = preg_replace('/information_schema\.tables/i', 'sqlite_master', $sql) ?? $sql;
            $sql = preg_replace('/\btable_schema\s*=\s*DATABASE\(\)\s*(?:AND\s*)?/i', '', $sql) ?? $sql;
            $sql = preg_replace('/\btable_name\b/i', 'name', $sql) ?? $sql;
            $sql = preg_replace('/\btable_type\b/i', "CASE type WHEN 'view' THEN 'VIEW' ELSE 'BASE TABLE' END", $sql) ?? $sql;
            if (stripos($sql,'type=')===false && stripos($sql,"type IN")===false) {
                // Do not force this for arbitrary selects; WHERE name is enough.
            }
            return $sql;
        }

        // INFORMATION_SCHEMA.COLUMNS compatibility.
        if (stripos($sql, 'information_schema.columns') !== false) {
            if (preg_match('/SELECT\s+COLUMN_TYPE\s*,\s*IS_NULLABLE\s*,\s*COLUMN_DEFAULT\s*,\s*EXTRA\s+FROM\s+information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*\?\s+AND\s+COLUMN_NAME\s*=\s*\?\s+LIMIT\s+1/is', $sql)) {
                return "SELECT type AS COLUMN_TYPE, CASE WHEN [notnull]=0 THEN 'YES' ELSE 'NO' END AS IS_NULLABLE, dflt_value AS COLUMN_DEFAULT, CASE WHEN pk>0 AND upper(type)='INTEGER' THEN 'auto_increment' ELSE '' END AS EXTRA FROM pragma_table_info(?) WHERE name=? LIMIT 1";
            }
            if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"]\s+AND\s+column_name\s*=\s*(\?|[\'\"][^\'\"]+[\'\"])/is', $sql, $m)) {
                $table=str_replace("'","''",$m[1]);
                return "SELECT COUNT(*) FROM pragma_table_info('{$table}') WHERE name=".$m[2];
            }
            if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+column_name\s*=\s*\?/is', $sql)) {
                return "SELECT COUNT(*) FROM pragma_table_info(?) WHERE name=?";
            }
            if (preg_match('/SELECT\s+column_name\s*,\s*data_type\s*,\s*column_type\s*,\s*is_nullable\s*,\s*column_key\s*,\s*extra\s+FROM\s+information_schema\.columns.*table_name\s*=\s*\?.*ORDER\s+BY\s+ordinal_position/is',$sql)) {
                return "SELECT name AS column_name,lower(type) AS data_type,type AS column_type,CASE WHEN [notnull]=0 THEN 'YES' ELSE 'NO' END AS is_nullable,CASE WHEN pk>0 THEN 'PRI' ELSE '' END AS column_key,CASE WHEN pk>0 AND upper(type)='INTEGER' THEN 'auto_increment' ELSE '' END AS extra FROM pragma_table_info(?) ORDER BY cid";
            }
            if (preg_match('/SELECT\s+column_type\s+FROM\s+information_schema\.columns.*table_name\s*=\s*\?.*column_name\s*=\s*\?/is',$sql)) {
                return "SELECT type AS column_type FROM pragma_table_info(?) WHERE name=? LIMIT 1";
            }
            if (preg_match('/SELECT\s+column_name\s*,\s*ordinal_position\s+FROM\s+information_schema\.columns.*table_name\s*=\s*\?.*ORDER\s+BY\s+ordinal_position/is',$sql)) {
                return "SELECT name AS column_name,cid+1 AS ordinal_position FROM pragma_table_info(?) ORDER BY cid";
            }
            if (preg_match('/SELECT\s+column_name\s*,\s*column_type\s*,\s*is_nullable\s*,\s*column_default\s*,\s*extra\s+FROM\s+information_schema\.columns.*table_name\s*=\s*\?.*column_name\s*=\s*\?/is',$sql)) {
                return "SELECT name AS column_name,type AS column_type,CASE WHEN [notnull]=0 THEN 'YES' ELSE 'NO' END AS is_nullable,dflt_value AS column_default,CASE WHEN pk>0 AND upper(type)='INTEGER' THEN 'auto_increment' ELSE '' END AS extra FROM pragma_table_info(?) WHERE name=? LIMIT 1";
            }
            if (preg_match('/SELECT\s+column_name\s+FROM\s+information_schema\.columns.*table_name\s*=\s*\?.*LOWER\(extra\).*auto_increment.*\(\?\s+IS\s+NULL\s+OR\s+column_name<>\?\)/is',$sql)) {
                return "SELECT name AS column_name FROM pragma_table_info(?) WHERE pk>0 AND upper(type)='INTEGER' AND (? IS NULL OR name<>?) LIMIT 1";
            }
        }

        if (stripos($sql,'information_schema.statistics')!==false) {
            if (preg_match('/SELECT\s+index_name\s*,\s*non_unique\s*,\s*seq_in_index\s+FROM\s+information_schema\.statistics.*table_name\s*=\s*\?.*column_name\s*=\s*\?/is',$sql)) {
                return "SELECT il.name AS index_name,CASE WHEN il.[unique]=1 THEN 0 ELSE 1 END AS non_unique,ii.seqno+1 AS seq_in_index FROM pragma_index_list(?) il JOIN pragma_index_info(il.name) ii WHERE ii.name=? AND il.origin<>'pk' ORDER BY il.name,ii.seqno";
            }
        }

        // SQLite accepts most MySQL type names but not column-order clauses.
        $sql = preg_replace('/\s+AFTER\s+[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\s+FIRST\b/i', '', $sql) ?? $sql;

        return $sql;
    }

    private function convertCreateTable(string $sql): string
    {
        $sql=preg_replace('/\)\s*ENGINE\s*=.*$/is',')',$sql)??$sql;
        $sql=preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[^\s]+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s*=\s*[^\s]+/i','',$sql)??$sql;
        if(!preg_match('/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?\s*)\((.*)\)\s*$/is',$sql,$m)) return $sql;
        $parts=$this->splitDefinitions($m[2]);$out=[];
        foreach($parts as $part){$part=trim($part);if($part==='')continue;
            if(preg_match('/^(?:KEY|INDEX)\s+[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?\s*\(/i',$part))continue;
            if(preg_match('/^UNIQUE\s+KEY\s+[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?\s*(\(.+\))$/is',$part,$u)){$out[]='UNIQUE '.$u[1];continue;}
            if(preg_match('/^PRIMARY\s+KEY\b|^UNIQUE\b|^CONSTRAINT\b|^FOREIGN\s+KEY\b|^CHECK\b/i',$part)){$out[]=$part;continue;}
            $out[]=$this->convertColumnDefinition($part);
        }
        return $m[1].'('.implode(', ',$out).')';
    }

    private function convertColumnDefinition(string $def): string
    {
        $def=preg_replace('/\s+AFTER\s+[`"]?[A-Za-z_][A-Za-z0-9_]*[`"]?/i','',$def)??$def;
        $def=preg_replace('/\s+FIRST\b/i','',$def)??$def;
        $def=preg_replace('/\s+COMMENT\s+(?:\'[^\']*\'|"[^"]*")/i','',$def)??$def;
        $def=preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?/i','',$def)??$def;
        $def=preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','INTEGER PRIMARY KEY AUTOINCREMENT',$def)??$def;
        $def=preg_replace('/\b(?:BIGINT|INT|INTEGER|SMALLINT|TINYINT)(?:\s*\(\d+\))?\s+UNSIGNED\b/i','INTEGER',$def)??$def;
        $def=preg_replace('/\bBIGINT(?:\s*\(\d+\))?\b/i','INTEGER',$def)??$def;
        $def=preg_replace('/\b(?:INT|SMALLINT|TINYINT)(?:\s*\(\d+\))?\b/i','INTEGER',$def)??$def;
        $def=preg_replace('/\bAUTO_INCREMENT\b/i','',$def)??$def;
        $def=preg_replace('/\b(?:LONGTEXT|MEDIUMTEXT|TINYTEXT|VARCHAR\s*\(\d+\)|CHAR\s*\(\d+\)|ENUM\s*\([^)]*\))\b/i','TEXT',$def)??$def;
        $def=preg_replace('/\b(?:DATETIME|TIMESTAMP|DATE|TIME)\b/i','TEXT',$def)??$def;
        $def=preg_replace('/\bDECIMAL\s*\([^)]*\)|\bNUMERIC\s*\([^)]*\)/i','NUMERIC',$def)??$def;
        $def=preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i','',$def)??$def;
        return trim(preg_replace('/\s{2,}/',' ',$def)??$def);
    }

    /** @return list<string> */
    private function splitDefinitions(string $body): array
    {
        $parts=[];$buf='';$depth=0;$quote=null;$len=strlen($body);
        for($i=0;$i<$len;$i++){$c=$body[$i];
            if($quote!==null){$buf.=$c;if($c===$quote && ($i===0||$body[$i-1]!=='\\'))$quote=null;continue;}
            if($c==="'"||$c==='"'||$c==='`'){$quote=$c;$buf.=$c;continue;}
            if($c==='('){$depth++;$buf.=$c;continue;}if($c===')'){$depth--; $buf.=$c;continue;}
            if($c===','&&$depth===0){$parts[]=$buf;$buf='';continue;}$buf.=$c;
        }
        if(trim($buf)!=='')$parts[]=$buf;return $parts;
    }

}
