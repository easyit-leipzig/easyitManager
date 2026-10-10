<?php
declare(strict_types=1);
namespace DataForm\Database\SqlServer;
use DataForm\Database\Pdo\AbstractPdoAdapter;
use DataForm\Database\DatabaseException;
final class SqlServerAdapter extends AbstractPdoAdapter
{
    public function driver():string{return 'mssql';}
    protected function identifierQuote():string{return '[';}
    protected function qi(string $identifier):string{if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$identifier))throw new DatabaseException("Ungültiger SQL-Bezeichner: {$identifier}");return '['.str_replace(']',']]',$identifier).']';}
    protected function dsn():string{$host=$this->config['host']??'127.0.0.1';$port=(int)($this->config['port']??1433);$db=$this->config['database']??'';$enc=($this->config['encrypt']??true)?'true':'false';$trust=($this->config['trust_server_certificate']??false)?'true':'false';return "sqlsrv:Server={$host},{$port};Database={$db};Encrypt={$enc};TrustServerCertificate={$trust}";}
    public function tableExists(string $table):bool{$st=$this->connection()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:table");$st->execute(['table'=>$table]);return (int)$st->fetchColumn()>0;}
    public function columns(string $table):array{$st=$this->connection()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION");$st->execute(['table'=>$table]);return array_column($st->fetchAll(),'COLUMN_NAME');}
    public function createTable(string $table,array $columns):void{$defs=['[id] BIGINT IDENTITY(1,1) PRIMARY KEY'];foreach($columns as $c){if($c==='id')continue;$defs[]=$this->qi((string)$c).' NVARCHAR(MAX) NULL';}$this->connection()->exec('CREATE TABLE '.$this->qi($table).' ('.implode(', ',$defs).')');}
    public function insert(string $table,array $data):int{unset($data['id']);if(!$data)throw new DatabaseException('Keine Daten zum Einfügen angegeben.');$cols=array_keys($data);$sql='INSERT INTO '.$this->qi($table).' ('.implode(', ',array_map(fn($c)=>$this->qi((string)$c),$cols)).') OUTPUT INSERTED.[id] VALUES ('.implode(', ',array_map(fn($c)=>':'.$c,$cols)).')';$st=$this->connection()->prepare($sql);$st->execute($data);return (int)$st->fetchColumn();}
}
