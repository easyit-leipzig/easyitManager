<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$src=(string)file_get_contents($root.'/src/Database/Connection.php');
$install=(string)file_get_contents($root.'/INSTALL_LOCAL_FRESH.md');
$admin=(string)file_get_contents($root.'/src/Admin/AdminApp.php');
$checks=[
 ['missing DB detector',str_contains($src,'1049')&&str_contains($src,'unknown database')],
 ['mysql bootstrap',str_contains($src,'CREATE DATABASE IF NOT EXISTS')],
 ['utf8mb4 collation',str_contains($src,'utf8mb4_unicode_ci')],
 ['postgres bootstrap',str_contains($src,"'CREATE DATABASE '.")],
 ['no drop database',preg_match('/DROP\\s+DATABASE/i',$src)!==1],
 ['retry normal connection',substr_count($src,'self::connect($dsn,$user,$password)')>=2],
 ['clear permission error',str_contains($src,'DATABASE_BOOTSTRAP_FAILED')],
 ['admin catches bootstrap errors',str_contains($admin,'renderBootstrapFailure')&&str_contains($admin,'Lizenzserver-Datenquelle nicht verfügbar')],
 ['installation doc auto bootstrap',str_contains($install,'automatisch')&&str_contains($install,'easyit_license')],
];
$fail=0;
foreach($checks as [$name,$ok]){echo ($ok?'PASS':'FAIL')." - {$name}\n";if(!$ok)$fail++;}
if($fail){exit(1);} echo "HF109_DATABASE_BOOTSTRAP_GATE_OK\n";
