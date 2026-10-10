<?php
declare(strict_types=1);
final class EasyItDbEngine {
 public static function connect(array $c): PDO {
  $d=strtolower(trim((string)($c['driver']??'sqlite')));if(in_array($d,['postgres','postgresql'],true))$d='pgsql';if($d==='mariadb')$d='mysql';
  if($d==='csv')return new EnterpriseCsvPdo((string)($c['csv_path']??(__DIR__.'/../data/csv')),(string)($c['database']??'project'));
  if($d==='sqlite')return new EnterpriseSqlitePdo((string)($c['sqlite_path']??(__DIR__.'/../data/project.sqlite')));
  if($d==='pgsql')return new EnterprisePgsqlPdo((string)($c['host']??'127.0.0.1'),(int)($c['port']??5432),(string)($c['database']??''),(string)($c['user']??''),(string)($c['password']??''),(string)($c['schema']??'public'));
  if($d==='oracle')return new EnterpriseOraclePdo((string)($c['host']??'127.0.0.1'),(int)($c['port']??1521),(string)($c['service']??'XEPDB1'),(string)($c['user']??''),(string)($c['password']??''));
  if($d==='mssql')return new EnterpriseMssqlPdo((string)($c['host']??'127.0.0.1'),(int)($c['port']??1433),(string)($c['database']??''),(string)($c['user']??''),(string)($c['password']??''),(bool)($c['encrypt']??true),(bool)($c['trust_server_certificate']??false));
  if($d==='mysql'){if(!extension_loaded('pdo_mysql'))throw new RuntimeException('pdo_mysql fehlt.');return new PDO('mysql:host='.(string)($c['host']??'127.0.0.1').';port='.(int)($c['port']??3306).';dbname='.(string)($c['database']??'').';charset=utf8mb4',(string)($c['user']??''),(string)($c['password']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
  throw new RuntimeException('Nicht unterstützter DB-Engine-Treiber: '.$d);
 }
}
