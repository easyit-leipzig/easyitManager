<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class MssqlDataSourceAdapter extends AbstractPdoAdapter implements DataSourceAdapterInterface
{
    public function getDriver(): string { return 'mssql'; }
    public function getLabel(): string { return 'Microsoft SQL Server'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        if (!$this->pdoDriverAvailable('sqlsrv')) {
            return $this->unavailable('sqlsrv', 'Microsoft SQL Server/PDO_SQLSRV');
        }
        try {
            $pdo=$this->connect($runtimeConfig);
            $row=$pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS server_version, DB_NAME() AS database_name, SUSER_SNAME() AS authenticated_user")->fetch() ?: [];
            return ConnectionTestResult::success('Microsoft-SQL-Server-Verbindung erfolgreich.', [
                'serverVersion'=>(string)($row['server_version']??''),
                'database'=>(string)($row['database_name']??''),
                'authenticatedUser'=>(string)($row['authenticated_user']??''),
            ]);
        } catch (\Throwable $e) {
            return ConnectionTestResult::failure('Microsoft-SQL-Server-Verbindung fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function discover(array $runtimeConfig): array
    {
        $pdo=$this->connect($runtimeConfig);
        $stmt=$pdo->query("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA NOT IN ('sys','INFORMATION_SCHEMA') ORDER BY TABLE_SCHEMA,TABLE_NAME");
        $out=[];
        foreach($stmt->fetchAll() as $row){
            $raw=strtoupper((string)($row['TABLE_TYPE']??$row['table_type']??''));
            $type=str_contains($raw,'VIEW')?'view':'table';
            $schema=(string)($row['TABLE_SCHEMA']??$row['table_schema']??'dbo');
            $name=(string)($row['TABLE_NAME']??$row['table_name']??'');
            if($name==='')continue;
            $qualified=$schema!==''?$schema.'.'.$name:$name;
            $out[]=['name'=>$qualified,'type'=>$type,'label'=>$qualified.' ('.($type==='view'?'View':'Tabelle').')','meta'=>['schema'=>$schema,'table'=>$name]];
        }
        return $out;
    }

    private function connect(array $c): \PDO
    {
        if (!$this->pdoDriverAvailable('sqlsrv')) throw new \RuntimeException('PDO_SQLSRV ist nicht verfügbar.');
        $host=(string)($c['host']??'127.0.0.1');
        $instance=trim((string)($c['instance']??''));
        $server=$host.($instance!==''?'\\\\'.$instance:','.((int)($c['port']??1433)));
        $parts=['Server='.$server];
        $database=trim((string)($c['database']??''));
        if($database!=='')$parts[]='Database='.$database;
        $parts[]='Encrypt='.($this->boolValue($c['encrypt']??true)?'true':'false');
        $parts[]='TrustServerCertificate='.($this->boolValue($c['trustServerCertificate']??$c['trust_server_certificate']??false)?'true':'false');
        $dsn='sqlsrv:'.implode(';',$parts);
        return new \PDO($dsn,(string)($c['username']??''),(string)($c['password']??''),$this->options());
    }

    private function boolValue(mixed $value): bool
    {
        if(is_bool($value))return $value;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on','ja'],true);
    }
}
