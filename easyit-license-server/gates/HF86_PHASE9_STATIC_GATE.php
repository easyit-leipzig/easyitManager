<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$account=(string)@file_get_contents($root.'/src/Service/AccountService.php');
$fail=0;
$c=function(string $name,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('account profile exposes installation inventory',str_contains($account,"'installations'=>\$installations")&&str_contains($account,'FROM installations WHERE license_id=?'));
$c('account profile exposes installation quota',str_contains($account,"'installation_usage'=>\$installationUsage")&&str_contains($account,"'used_installations'")&&str_contains($account,"'max_installations'"));
$c('account profile exposes registered dataforms',str_contains($account,"'dataforms'=>\$dataforms")&&str_contains($account,'FROM license_dataforms d'));
$c('dataform inventory includes slot and installation state',str_contains($account,"'consumes_license_slot'")&&str_contains($account,"'token_status'")&&str_contains($account,'usage_status')&&str_contains($account,'installation_status'));
$c('dataform inventory honors resource scope',str_contains($account,'resource_scope')&&str_contains($account,'project_ids')&&str_contains($account,'dataform_uuids')&&str_contains($account,'if(!$unrestricted'));
$c('inventory queries are license/customer scoped',str_contains($account,'WHERE d.license_id=? AND d.customer_id=?')&&str_contains($account,'$dfst->execute([$licenseId,$customerId])'));
$c('account inventory does not select installation secrets',!str_contains($account,"SELECT installation_id,status,generation,product_code,product_version,environment_hash")&&!str_contains($account,'installation_public_key'));
echo $fail?"HF86_PHASE9_STATIC_GATE FAIL ($fail)\n":"HF86_PHASE9_STATIC_GATE PASS\n";
exit($fail?1:0);
