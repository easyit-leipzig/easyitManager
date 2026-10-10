<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$license=(string)@file_get_contents($root.'/src/Service/LicenseService.php');
$account=(string)@file_get_contents($root.'/src/Service/AccountService.php');
$fail=0;
$c=function(string $name,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('module list considers validity window',str_contains($license,'SELECT module_code,valid_from,valid_until')&&str_contains($license,'$now=time();$out=[]')&&substr_count($license,'valid_from')>=3&&substr_count($license,'valid_until')>=3);
$c('account profile exposes live module list',str_contains($account,"'modules'=>\$this->licenses->modules"));
$c('account profile exposes live policy summary',str_contains($account,"'license_policy'=>")&&str_contains($account,'$this->licenses->policySummary'));
$c('account profile carries license generation',substr_count($account,"'generation'=>(int)\$lic['generation']")>=2);
$c('account session revalidates license usability',str_contains($account,'resolveUsableLicense')&&str_contains($account,"status='active'")&&str_contains($account,'valid_until'));
echo $fail?"HF86_PHASE1_SERVER_GATE FAIL ($fail)\n":"HF86_PHASE1_SERVER_GATE PASS\n";
exit($fail?1:0);
