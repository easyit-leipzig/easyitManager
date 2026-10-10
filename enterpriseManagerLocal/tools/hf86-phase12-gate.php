<?php
declare(strict_types=1);
$root=dirname(__DIR__);$ok=true;$c=function(string $name,bool $pass)use(&$ok){echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;if(!$pass)$ok=false;};
$doc=$root.'/docs/HF86_PHASE12.md';$final=$root.'/tools/hf86-final-gate.php';$version=trim((string)@file_get_contents($root.'/VERSION'));
$c('phase12 documentation exists',is_file($doc));
$c('enterprise final gate exists',is_file($final));
$c('Phase12 final baseline retained',$version==='RC1.8-FC1-HF86-PHASE12-FINAL-RC'||str_starts_with($version,'RC1.8-FC1-HF87-'));
$text=is_file($doc)?(string)file_get_contents($doc):'';
$c('docs identify server real-DB gate',str_contains($text,'HF86_PHASE12_DB_GATE.php'));
$c('docs require both MariaDB and PostgreSQL',str_contains($text,'MariaDB')&&str_contains($text,'PostgreSQL'));
$c('docs preserve no-false-pass rule',str_contains($text,'DB-Endgate')&&str_contains($text,'nicht erfolgreich'));
echo 'HF86_PHASE12_ENTERPRISE_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
