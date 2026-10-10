<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=(string)@file_get_contents($root.'/app/account.php');
$fail=0;
$c=function(string $name,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('account shows installation quota',str_contains($f,'used_installations')&&str_contains($f,'max_installations')&&str_contains($f,'<span>Installationen</span>'));
$c('account shows installation inventory',str_contains($f,'<h2>Installationen</h2>')&&str_contains($f,'product_code')&&str_contains($f,'activated_at')&&str_contains($f,'last_seen_at'));
$c('account shows dataform inventory',str_contains($f,'<h2>Registrierte DataForms</h2>')&&str_contains($f,'dataform_uuid')&&str_contains($f,'local_dataform_id'));
$c('account shows license slot state',str_contains($f,'Lizenzplatz')&&str_contains($f,'consumes_license_slot')&&str_contains($f,'usage_status'));
$c('account shows token state without token value',str_contains($f,'Tokenstatus')&&str_contains($f,'token_status')&&!str_contains($f,'df_license_token'));
$c('phase 9 inventory remains present after lifecycle extension',str_contains($f,'<h2>Installationen</h2>')&&str_contains($f,'<h2>Registrierte DataForms</h2>')&&!str_contains($f,"action==='release_dataform'"));
echo $fail?"HF86_PHASE9_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE9_ENTERPRISE_GATE PASS\n";
exit($fail?1:0);
