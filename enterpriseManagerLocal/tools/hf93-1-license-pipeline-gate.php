<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/system/licensing/LicenseConfigStore.php';
$checks=[];
$login=file_get_contents($root.'/login.php');
$remote=file_get_contents($root.'/app/licensing/remote.php');
$checks['login uses store']=str_contains($login,'EnterpriseLicenseConfigStore::load(__DIR__)');
$checks['remote uses store']=str_contains($remote,'EnterpriseLicenseConfigStore::load($root)')&&str_contains($remote,'EnterpriseLicenseConfigStore::save($root,$newCfg)');
$runtimeFiles=['products/db-engine/export.php','system/licensing/EnterpriseDesignerBridge.php','system/licensing/EnterpriseDataFormRegistryBridge.php','system/licensing/EnterpriseDataFormPolicyBridge.php','system/licensing/EnterpriseRuntimeBridge.php'];
foreach($runtimeFiles as $f){$s=file_get_contents($root.'/'.$f);$checks[$f.' no direct config read']=!preg_match("~require\\s+[^;]*config/licensing\\.php~",$s);}
$tmp=sys_get_temp_dir().'/easyit-hf931-'.bin2hex(random_bytes(4));mkdir($tmp.'/config',0777,true);mkdir($tmp.'/storage/system/config',0777,true);file_put_contents($tmp.'/config/licensing.php',"<?php return ['enabled'=>true,'server_url'=>'https://legacy.test','trusted_server_keys'=>['K'=>'V']];");$a=EnterpriseLicenseConfigStore::load($tmp);$checks['legacy migrates']=(($a['server_url']??'')==='https://legacy.test'&&is_file($tmp.'/storage/system/config/licensing.php'));EnterpriseLicenseConfigStore::save($tmp,['enabled'=>true,'server_url'=>'https://persistent.test']);$b=EnterpriseLicenseConfigStore::load($tmp);$checks['persistent wins']=(($b['server_url']??'')==='https://persistent.test');
foreach($checks as $n=>$ok){echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$failed=true;}
if(!empty($failed)){exit(1);}echo "HF93.1 License Pipeline Gate: PASS\n";
