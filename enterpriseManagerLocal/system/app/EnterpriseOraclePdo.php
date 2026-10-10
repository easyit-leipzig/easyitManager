<?php
declare(strict_types=1);

/**
 * Oracle XE/PDO_OCI compatibility layer for easyIT Enterprise/DataForm5.
 *
 * Oracle uses schemas/users rather than one database per project. The configured
 * service (normally XEPDB1 for Oracle XE 21c) is the physical database; the
 * configured username owns the Enterprise/DataForm schema.
 */
final class EnterpriseOraclePdo extends PDO
{
    private string $serviceName;
    private ?string $lastInsertTable=null;

    public function __construct(string $host,int $port,string $service,string $username,string $password='')
    {
        if(!extension_loaded('pdo_oci')) throw new RuntimeException('Die PHP-Erweiterung pdo_oci ist nicht aktiv.');
        $host=trim($host);$service=trim($service);$username=trim($username);
        if($host===''||$service===''||$username==='') throw new RuntimeException('Oracle XE benötigt Host, Service und Benutzer.');
        if($port<1||$port>65535) throw new RuntimeException('Ungültiger Oracle-Port.');
        parent::__construct('oci:dbname=//'.$host.':'.$port.'/'.$service.';charset=AL32UTF8',$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $this->serviceName=$service;
    }
    public function serviceName(): string{return $this->serviceName;}

    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if(preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+["`]?([A-Za-z_][A-Za-z0-9_]*)/i',$query,$m))$this->lastInsertTable=strtoupper($m[1]);
        return parent::prepare($this->translate($query),$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false
    {
        $sql=$this->translate($query);return $fetchMode===null?parent::query($sql):parent::query($sql,$fetchMode,...$args);
    }
    public function exec(string $statement): int|false
    {
        $sql=$this->translate($statement);if(trim($sql)==='')return 0;
        if(preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*/i',$sql,$m)){
            if($this->tableExists($m[1]))return 0;$sql=preg_replace('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i','CREATE TABLE',$sql,1)??$sql;
        }
        if(preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i',$sql,$m)){
            if($this->indexExists($m[1]))return 0;$sql=preg_replace('/\s+IF\s+NOT\s+EXISTS\s+/i',' ', $sql,1)??$sql;
        }
        if(preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i',$sql,$m)){
            if(!$this->tableExists($m[1]))return 0;$sql=preg_replace('/\s+IF\s+EXISTS\s+/i',' ', $sql,1)??$sql;
        }
        if(preg_match('/^\s*INSERT\s+IGNORE\s+INTO\s+(.+)$/is',$statement,$m)){
            $plain=$this->translate('INSERT INTO '.$m[1]);
            try{return parent::exec($plain);}catch(PDOException $e){if(str_contains($e->getMessage(),'ORA-00001'))return 0;throw $e;}
        }
        return parent::exec($sql);
    }
    public function lastInsertId(?string $name=null): string|false
    {
        if($name!==null&&$name!==''){try{$v=parent::query('SELECT "'.str_replace('"','""',$name).'".CURRVAL FROM dual')->fetchColumn();return $v===false?false:(string)$v;}catch(Throwable){}}
        if($this->lastInsertTable){try{$v=parent::query('SELECT MAX("ID") FROM "'.str_replace('"','""',$this->lastInsertTable).'"')->fetchColumn();return $v===false?false:(string)$v;}catch(Throwable){}}
        return false;
    }
    private function tableExists(string $table):bool{$st=parent::prepare('SELECT COUNT(*) FROM user_tables WHERE table_name=UPPER(?)');$st->execute([$table]);return (int)$st->fetchColumn()>0;}
    private function indexExists(string $index):bool{$st=parent::prepare('SELECT COUNT(*) FROM user_indexes WHERE index_name=UPPER(?)');$st->execute([$index]);return (int)$st->fetchColumn()>0;}

    private function translate(string $sql):string
    {
        $sql=trim($sql);if($sql==='')return $sql;
        $insertIgnore=preg_match('/^INSERT\s+IGNORE\s+INTO/i',$sql)===1;
        if(preg_match('/^USE\s+/i',$sql)||preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i',$sql))return 'SELECT 1 FROM dual';
        // MySQL-backticks bezeichnen in easyIT kanonisch case-insensitive Bezeichner.
        // Oracle speichert unquoted Namen in Grossschrift; deshalb wird der Inhalt
        // beim Uebergang auf quoted Oracle-Identifier bewusst uppercased.
        $sql=preg_replace_callback('/`([^`]+)`/',static fn(array $m):string=>'\"'.strtoupper($m[1]).'\"',$sql)??$sql;
        $sql=preg_replace('/\bDATABASE\(\)/i','SYS_CONTEXT(\'USERENV\',\'CURRENT_SCHEMA\')',$sql)??$sql;
        $sql=preg_replace('/\bTABLE_SCHEMA\s*=\s*SYS_CONTEXT\([^)]*\)/i','OWNER = SYS_CONTEXT(\'USERENV\',\'CURRENT_SCHEMA\')',$sql)??$sql;
        $sql=preg_replace('/\bLIMIT\s+1\b/i','FETCH FIRST 1 ROWS ONLY',$sql)??$sql;
        $sql=preg_replace('/\bLIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i','OFFSET $2 ROWS FETCH NEXT $1 ROWS ONLY',$sql)??$sql;
        $sql=preg_replace('/\bLIMIT\s+(\d+)\b/i','FETCH FIRST $1 ROWS ONLY',$sql)??$sql;
        $sql=preg_replace('/NOW\(\)/i','CURRENT_TIMESTAMP',$sql)??$sql;
        $sql=preg_replace('/CURRENT_TIMESTAMP\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i','CURRENT_TIMESTAMP',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','NUMBER(19) GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','NUMBER(19) GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+UNSIGNED\b/i','NUMBER(10)',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\b/i','NUMBER(19)',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\b/i','NUMBER(19)',$sql)??$sql;
        $sql=preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i','NUMBER(1)',$sql)??$sql;
        $sql=preg_replace('/\bINT\b/i','NUMBER(10)',$sql)??$sql;
        $sql=preg_replace('/\bUNSIGNED\b/i','',$sql)??$sql;
        $sql=preg_replace('/\bZEROFILL\b/i','',$sql)??$sql;
        $sql=preg_replace('/\bAUTO_INCREMENT\b/i','GENERATED BY DEFAULT AS IDENTITY',$sql)??$sql;
        $sql=preg_replace('/\bLONGTEXT\b/i','VARCHAR2(4000)',$sql)??$sql;
        $sql=preg_replace('/\bTEXT\b/i','VARCHAR2(4000)',$sql)??$sql;
        $sql=preg_replace('/\bVARCHAR\s*\((\d+)\)/i','VARCHAR2($1)',$sql)??$sql;
        $sql=preg_replace('/\bCHAR\s*\((\d+)\)/i','VARCHAR2($1)',$sql)??$sql;
        $sql=preg_replace('/\bDOUBLE\b/i','BINARY_DOUBLE',$sql)??$sql;
        $sql=preg_replace('/\bDATETIME\b/i','TIMESTAMP',$sql)??$sql;
        $sql=preg_replace('/\s+ENGINE\s*=\s*InnoDB\b/i','',$sql)??$sql;
        $sql=preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s*=\s*\w+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s+\w+/i','',$sql)??$sql;
        // Normale MySQL KEY-Klauseln sind nicht UNIQUE. Sie werden beim portablen
        // Tabellenschema weggelassen; verwaltete Sekundaerindizes werden separat angelegt.
        $sql=preg_replace('/,\s*KEY\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]+)\)/i','',$sql)??$sql;
        $sql=preg_replace('/,\s*INDEX\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]+)\)/i','',$sql)??$sql;
        $sql=preg_replace('/,\s*UNIQUE\s+KEY\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]+)\)/i',', CONSTRAINT $1 UNIQUE ($2)',$sql)??$sql;
        $sql=preg_replace('/\bUNIQUE\s+KEY\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]+)\)/i','CONSTRAINT $1 UNIQUE ($2)',$sql)??$sql;
        $sql=preg_replace('/\bAFTER\s+"?[A-Za-z_][A-Za-z0-9_]*"?/i','',$sql)??$sql;
        if(preg_match('/^SHOW\s+TABLES$/i',$sql))return 'SELECT table_name AS "Tables_in_oracle" FROM user_tables ORDER BY table_name';
        if(preg_match('/^SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+"?([A-Za-z_][A-Za-z0-9_]*)"?(?:\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"])?$/i',$sql,$m)){
            $table=strtoupper($m[1]);$extra=isset($m[2])?" AND column_name='".strtoupper(str_replace("'","''",$m[2]))."'":'';
            return "SELECT column_name AS \"Field\",data_type AS \"Type\",nullable AS \"Null\",data_default AS \"Default\",CASE WHEN identity_column='YES' THEN 'auto_increment' ELSE '' END AS \"Extra\" FROM user_tab_columns WHERE table_name='".$table."'".$extra." ORDER BY column_id";
        }
        if(stripos($sql,'information_schema.COLUMNS')!==false||stripos($sql,'information_schema.columns')!==false){
            if(preg_match('/TABLE_NAME\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i',$sql,$m)){ $t=strtoupper($m[1]); return "SELECT COUNT(*) FROM user_tab_columns WHERE table_name='".$t."'"; }
            return 'SELECT COUNT(*) FROM user_tab_columns WHERE table_name=UPPER(?) AND column_name=UPPER(?)';
        }
        if(stripos($sql,'information_schema.TABLES')!==false||stripos($sql,'INFORMATION_SCHEMA.TABLES')!==false)return 'SELECT COUNT(*) FROM user_tables WHERE table_name=UPPER(?)';
        // Oracle has no INSERT IGNORE/ON DUPLICATE. For literal INSERT IGNORE,
        // exec() catches ORA-00001. Prepared INSERT IGNORE is normalized to INSERT.
        $sql=preg_replace('/^INSERT\s+IGNORE\s+INTO/i','INSERT INTO',$sql)??$sql;
        $sql=preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+$/is','',$sql)??$sql;
        // Prepared INSERT IGNORE wird als PL/SQL-Block ausgeführt. DUP_VAL_ON_INDEX
        // entspricht dabei exakt der beabsichtigten Ignore-Semantik.
        if($insertIgnore)return 'BEGIN '.$sql.'; EXCEPTION WHEN DUP_VAL_ON_INDEX THEN NULL; END;';
        return $sql;
    }
}
