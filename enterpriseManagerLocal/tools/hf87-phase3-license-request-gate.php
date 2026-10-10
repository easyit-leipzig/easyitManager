<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
$check=function(string $name,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$file=function(string $rel)use($root):string{$v=@file_get_contents($root.'/'.$rel);return$v===false?'':$v;};
$v=trim($file('VERSION'));$account=$file('app/account.php');$client=$file('system/licensing/LicenseApiClient.php');$dashboard=$file('app/dashboard.php');
$check('version HF87 phase3',$v==='RC1.8-FC1-HF87-PHASE3-DIFFERENTIATED-LICENSE-REQUEST'||str_starts_with($v,'RC1.8-FC1-HF87-PHASE4-')||str_starts_with($v,'RC1.8-FC1-HF87-PHASE5-')||str_starts_with($v,'RC1.8-FC1-HF108-')||str_starts_with($v,'RC1.8-FC1-HF111-'));
$check('account differentiated request form',(str_contains($account,'Differenzierte Lizenzanfrage')||str_contains($account,'Lizenz erweitern'))&&str_contains($account,'name="products[]"')&&str_contains($account,'name="modules[]"')&&str_contains($account,'name="capabilities[]"'));
$check('request dimensions',str_contains($account,'max_installations')&&str_contains($account,'max_dataforms')&&str_contains($account,'term_months')&&str_contains($account,'desired_domains')&&str_contains($account,'desired_networks'));
$check('demo may submit request',str_contains($account,"'submit_license_request','cancel_license_request'"));
$check('api client endpoints',str_contains($client,'submitAccountLicenseRequest')&&str_contains($client,'cancelAccountLicenseRequest'));
$check('request history visible',str_contains($account,'Bisherige Lizenzanfragen')&&str_contains($account,'Zurückziehen'));
$check('demo dashboard entry',(str_contains($dashboard,'Differenzierte Lizenzanfrage stellen')||str_contains($dashboard,'Gesamtlizenz differenziert anfragen')));
exit($fail===0?0:1);
