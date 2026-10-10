<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class PostgreSqlDataSourceAdapter extends AbstractPdoAdapter implements DataSourceAdapterInterface
{
    public function getDriver(): string { return 'pgsql'; }
    public function getLabel(): string { return 'PostgreSQL'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        if (!$this->pdoDriverAvailable('pgsql')) {
            return $this->unavailable('pgsql', 'PostgreSQL/PDO');
        }
        try {
            $pdo=$this->connect($runtimeConfig);
            $row=$pdo->query("SELECT version() AS version,current_database() AS database,current_user AS authenticated_user,current_schema() AS schema")->fetch() ?: [];
            return ConnectionTestResult::success('PostgreSQL-Verbindung erfolgreich.', [
                'serverVersion'=>(string)($row['version']??''),
                'database'=>(string)($row['database']??''),
                'authenticatedUser'=>(string)($row['authenticated_user']??''),
                'schema'=>(string)($row['schema']??''),
            ]);
        } catch (\Throwable $e) {
            return ConnectionTestResult::failure('PostgreSQL-Verbindung fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function discover(array $runtimeConfig): array
    {
        $pdo=$this->connect($runtimeConfig);
        $stmt=$pdo->query("SELECT table_name,table_type FROM information_schema.tables WHERE table_schema=current_schema() ORDER BY table_name");
        $out=[];
        foreach($stmt->fetchAll() as $row){
            $raw=strtoupper((string)($row['table_type']??''));
            $type=str_contains($raw,'VIEW')?'view':'table';
            $name=(string)($row['table_name']??'');
            if($name==='')continue;
            $out[]=['name'=>$name,'type'=>$type,'label'=>$name.' ('.($type==='view'?'View':'Tabelle').')'];
        }
        return $out;
    }

    private function connect(array $c): \PDO
    {
        if (!$this->pdoDriverAvailable('pgsql')) throw new \RuntimeException('PDO-PostgreSQL ist nicht verfügbar.');
        $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??5432),(string)($c['database']??''));
        $pdo=new \PDO($dsn,(string)($c['username']??''),(string)($c['password']??''),$this->options());
        $pdo->exec("SET client_encoding TO 'UTF8'");
        $schema=trim((string)($c['schema']??'public')) ?: 'public';
        if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1)throw new \RuntimeException('Ungültiger PostgreSQL-Schemaname.');
        $pdo->exec('SET search_path TO "'.$schema.'"');
        return $pdo;
    }
}
