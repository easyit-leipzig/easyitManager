<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$boot=(string)file_get_contents($root.'/system/app/bootstrap.php');
$fail=0;
$check=static function(string $label,bool $ok)use(&$fail):void{echo ($ok?'PASS':'FAIL')." - {$label}\n";if(!$ok)$fail++;};
$createPos=strpos($boot,'CREATE TABLE IF NOT EXISTS enterprise_licenses');
$upgradePos=strpos($boot,'enterprise_upgrade_license_customer_key($pdo);',$createPos===false?0:$createPos);
$indexPos=strpos($boot,"CREATE INDEX IF NOT EXISTS idx_license_customer_key ON enterprise_licenses(customer_key)");
$check('enterprise_licenses table still contains customer_key column',str_contains($boot,'customer_key VARCHAR(320) NULL')||str_contains($boot,'customer_key TEXT NULL'));
$check('legacy MySQL/PostgreSQL CREATE TABLE no longer contains inline customer_key index',!str_contains($boot,'INDEX idx_license_customer_key(customer_key)'));
$check('SQLite pre-upgrade index list no longer contains customer_key index',substr_count($boot,'CREATE INDEX IF NOT EXISTS idx_license_customer_key ON enterprise_licenses(customer_key)')===1);
$check('customer_key upgrade runs after table ensure',$createPos!==false&&$upgradePos!==false&&$upgradePos>$createPos);
$check('customer_key index is owned by upgrade routine',$indexPos!==false&&$upgradePos!==false&&$indexPos>$upgradePos);
$check('upgrade backfills only valid legacy email holders',str_contains($boot,'FILTER_VALIDATE_EMAIL')&&str_contains($boot,'$meta[\'customer_key\']=$legacy') );
exit($fail===0?0:1);
