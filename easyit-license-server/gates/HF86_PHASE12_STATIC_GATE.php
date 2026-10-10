<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'db'=>$root.'/gates/HF86_PHASE12_DB_GATE.php',
 'final'=>$root.'/bin/hf86-final-gate.php',
 'ps1'=>$root.'/bin/HF86_PHASE12_WINDOWS_ENDGATE.ps1',
 'doc'=>$root.'/docs/HF86_PHASE12.md',
 'version'=>$root.'/VERSION',
];
$ok=true;$c=function(string $name,bool $pass)use(&$ok){echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;if(!$pass)$ok=false;};
foreach($files as $name=>$path)$c($name.' file exists',is_file($path));
$db=is_file($files['db'])?(string)file_get_contents($files['db']):'';
$final=is_file($files['final'])?(string)file_get_contents($files['final']):'';
$doc=is_file($files['doc'])?(string)file_get_contents($files['doc']):'';
$version=is_file($files['version'])?trim((string)file_get_contents($files['version'])):'';
$c('DB gate only supports mysql/pgsql',str_contains($db,"['mysql','pgsql']"));
$c('DB gate creates disposable namespace',str_contains($db,'CREATE DATABASE')&&str_contains($db,'CREATE SCHEMA'));
$c('DB gate cleans disposable namespace',str_contains($db,'DROP DATABASE IF EXISTS')&&str_contains($db,'DROP SCHEMA IF EXISTS'));
$c('DB gate runs MigrationRunner twice',substr_count($db,'$runner->migrate()')>=2);
$c('DB gate verifies utf8mb4_unicode_ci',str_contains($db,'utf8mb4_unicode_ci'));
$c('DB gate exercises DataForm limit',str_contains($db,'DATAFORM_LIMIT_REACHED'));
$c('DB gate exercises installation limit',str_contains($db,'INSTALLATION_LIMIT_REACHED'));
$c('DB gate exercises terminal install lifecycle',str_contains($db,'INSTALLATION_TERMINAL'));
$c('DB gate exercises domain lockout',str_contains($db,'SURFACE_DOMAIN_CURRENT_REQUIRED')&&str_contains($db,'SURFACE_DOMAIN_LAST_REQUIRED'));
$c('DB gate exercises network lockout',str_contains($db,'SURFACE_IP_CURRENT_REQUIRED')&&str_contains($db,'SURFACE_IP_LAST_REQUIRED'));
$c('DB gate exercises credential version/session revoke',str_contains($db,'credential version increment')&&str_contains($db,'account session revoked'));
$c('DB gate exercises rotation readiness',str_contains($db,'rotation installation readiness')&&str_contains($db,'rotation dataform readiness'));
$c('DB gate uses independent PDO lock connections',str_contains($db,'FOR UPDATE blocks competing writer')&&str_contains($db,'$pdo2'));
$c('final gate requires MariaDB and PostgreSQL DSNs',str_contains($final,'HF86_MARIADB_DSN')&&str_contains($final,'HF86_PGSQL_DSN'));
$c('final gate does not silently pass without DB',str_contains($final,'FAILED/BLOCKED'));
$c('documentation forbids static-as-real-DB pass',str_contains($doc,'darf nicht als realer DB-PASS bezeichnet werden'));
$c('Phase12 final baseline retained',$version==='RC1.8-FC1-HF86-PHASE12-FINAL-RC'||str_starts_with($version,'RC1.8-FC1-HF87-'));
echo 'HF86_PHASE12_SERVER_STATIC_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
