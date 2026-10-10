<?php
declare(strict_types=1);
require_once __DIR__.'/EnterpriseMssqlAdapter.php';
final class EnterpriseMssqlPdo extends PDO
{
    private string $databaseName;
    public function __construct(string $host,int $port,string $database,string $username,string $password='',bool $encrypt=true,bool $trustServerCertificate=false)
    {
        if(!in_array('sqlsrv',PDO::getAvailableDrivers(),true)) throw new RuntimeException('Die PHP-Erweiterung pdo_sqlsrv ist nicht aktiv.');
        if(trim($host)===''||trim($database)===''||trim($username)==='') throw new RuntimeException('MSSQL benötigt Host, Datenbank und Benutzer.');
        $cfg=['host'=>$host,'port'=>$port,'database'=>$database,'username'=>$username,'password'=>$password,'encrypt'=>$encrypt,'trust_server_certificate'=>$trustServerCertificate];
        parent::__construct(EnterpriseMssqlAdapter::buildDsn($cfg),$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->databaseName=$database;
    }
    public function databaseName():string{return $this->databaseName;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare($this->translate($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$q=$this->translate($query);return $fetchMode===null?parent::query($q):parent::query($q,$fetchMode,...$args);}
    public function exec(string $statement):int|false{$q=$this->translate($statement);if(trim($q)==='')return 0;return parent::exec($q);}
    public function insertReturningId(string $table,array $data,string $identityColumn='id'): int|string|null
    {
        if($data===[])throw new InvalidArgumentException('INSERT benötigt mindestens ein Feld.');
        $qt=EnterpriseMssqlAdapter::quoteIdentifier($table);
        $qi=EnterpriseMssqlAdapter::quoteIdentifier($identityColumn);
        $cols=array_map([EnterpriseMssqlAdapter::class,'quoteIdentifier'],array_keys($data));
        $sql='INSERT INTO '.$qt.' ('.implode(',',$cols).') OUTPUT INSERTED.'.$qi.' VALUES ('.implode(',',array_fill(0,count($data),'?')).')';
        $stmt=parent::prepare($sql);$stmt->execute(array_values($data));$v=$stmt->fetchColumn();
        if($v===false||$v===null)return null;
        return is_numeric($v)?(int)$v:(string)$v;
    }
    public function lastInsertId(?string $name=null):string|false{try{$v=parent::query('SELECT CAST(@@IDENTITY AS bigint)')->fetchColumn();return $v===false||$v===null?false:(string)$v;}catch(Throwable){return false;}}
    private function translate(string $sql):string
    {
        $sql=trim($sql);if($sql==='')return $sql;
        if(preg_match('/^USE\s+/i',$sql)||preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i',$sql))return 'SELECT 1';
        $sql=preg_replace_callback('/`([^`]+)`/',static fn($m)=>'['.str_replace(']',']]',$m[1]).']',$sql)??$sql;
        $sql=preg_replace('/\bTABLE_SCHEMA\s*=\s*DATABASE\(\)/i',"TABLE_SCHEMA='dbo'",$sql)??$sql;
        $sql=preg_replace('/\bDATABASE\(\)/i','DB_NAME()',$sql)??$sql;
        $sql=preg_replace('/\bNOW\(\)/i','CURRENT_TIMESTAMP',$sql)??$sql;
        $sql=preg_replace('/CURRENT_TIMESTAMP\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i','CURRENT_TIMESTAMP',$sql)??$sql;
        if(preg_match('/^SELECT\s+(.+)\s+LIMIT\s+1$/is',$sql,$m))$sql='SELECT TOP 1 '.$m[1];
        $sql=preg_replace('/\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)\s*$/i',' ORDER BY (SELECT 0) OFFSET $2 ROWS FETCH NEXT $1 ROWS ONLY',$sql)??$sql;
        $sql=preg_replace('/\s+LIMIT\s+(\d+)\s*$/i',' ORDER BY (SELECT 0) OFFSET 0 ROWS FETCH NEXT $1 ROWS ONLY',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','BIGINT IDENTITY(1,1) PRIMARY KEY',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','BIGINT IDENTITY(1,1) PRIMARY KEY',$sql)??$sql;
        $sql=preg_replace('/\bAUTO_INCREMENT\b/i','IDENTITY(1,1)',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\b/i','BIGINT',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+UNSIGNED\b/i','INT',$sql)??$sql;
        $sql=preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i','BIT',$sql)??$sql;
        $sql=preg_replace('/\bLONGTEXT\b/i','NVARCHAR(MAX)',$sql)??$sql;
        $sql=preg_replace('/\bTEXT\b/i','NVARCHAR(MAX)',$sql)??$sql;
        $sql=preg_replace('/\bDATETIME\b/i','DATETIME2',$sql)??$sql;
        $sql=preg_replace('/\s+ENGINE\s*=\s*InnoDB\b/i','',$sql)??$sql;
        $sql=preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+CHARSET\s*=\s*\w+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s*=\s*\w+/i','',$sql)??$sql;
        $sql=preg_replace('/\s+COLLATE\s+\w+/i','',$sql)??$sql;
        $sql=preg_replace('/,\s*(?:KEY|INDEX)\s+[A-Za-z_][A-Za-z0-9_]*\s*\([^)]+\)/i','',$sql)??$sql;
        $sql=preg_replace('/,\s*UNIQUE\s+KEY\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]+)\)/i',', CONSTRAINT $1 UNIQUE ($2)',$sql)??$sql;
        $sql=preg_replace('/\bAFTER\s+\[?[A-Za-z_][A-Za-z0-9_]*\]?/i','',$sql)??$sql;
        $sql=preg_replace('/^INSERT\s+IGNORE\s+INTO/i','INSERT INTO',$sql)??$sql;
        $sql=preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+$/is','',$sql)??$sql;
        if(preg_match('/^SHOW\s+TABLES$/i',$sql))return "SELECT TABLE_NAME AS Tables_in_mssql FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' ORDER BY TABLE_NAME";
        if(preg_match('/^SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+\[?([A-Za-z_][A-Za-z0-9_]*)\]?(?:\s+LIKE\s+[\'\"]([^\'\"]+)[\'\"])?$/i',$sql,$m)){ $t=str_replace("'","''",$m[1]);$x=isset($m[2])?" AND COLUMN_NAME='".str_replace("'","''",$m[2])."'":'';return "SELECT COLUMN_NAME AS Field,DATA_TYPE AS Type,IS_NULLABLE AS [Null],COLUMN_DEFAULT AS [Default],CASE WHEN COLUMNPROPERTY(OBJECT_ID(TABLE_SCHEMA+'.'+TABLE_NAME),COLUMN_NAME,'IsIdentity')=1 THEN 'auto_increment' ELSE '' END AS Extra FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='{$t}'{$x} ORDER BY ORDINAL_POSITION"; }
        if(stripos($sql,'information_schema.statistics')!==false)return "SELECT i.name AS index_name,CASE WHEN i.is_unique=1 THEN 0 ELSE 1 END AS non_unique,ic.key_ordinal AS seq_in_index,c.name AS column_name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id JOIN sys.tables t ON t.object_id=i.object_id WHERE t.name=? AND c.name=?";
        return $sql;
    }
}
