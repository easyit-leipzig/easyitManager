<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
    'migration'=>(string)@file_get_contents($root.'/src/Database/MigrationRunner.php'),
    'service'=>(string)@file_get_contents($root.'/src/Service/DataFormRegistryService.php'),
    'app'=>(string)@file_get_contents($root.'/src/App/Application.php'),
    'runtime'=>(string)@file_get_contents($root.'/src/Service/RuntimeService.php'),
];
$checks=[];$c=function(string $name,bool $ok)use(&$checks){$checks[]=[$name,$ok];};
$c('license_dataforms registry schema',str_contains($files['migration'],'CREATE TABLE IF NOT EXISTS license_dataforms'));
$c('UUID is central primary key',str_contains($files['migration'],'dataform_uuid VARCHAR(36) PRIMARY KEY'));
$c('local mapping uniqueness',str_contains($files['migration'],'UNIQUE(license_id,project_id,local_dataform_id)'));
$c('registration endpoint exists',str_contains($files['app'],"/api/v1/dataforms/register"));
$registryPos=strpos($files['app'],'$registry=new DataFormRegistryService');
$verifyPos=strpos($files['app'],'$verifier->verify');
$c('registration requires signed installation',$registryPos!==false&&$verifyPos!==false&&$registryPos>$verifyPos);
$c('registration starts pending',str_contains($files['service'],"status='pending'")&&str_contains($files['service'],"'pending'"));
$phase3=is_file($root.'/src/Service/DataFormTokenService.php');
$c('new pending registration consumes no slot',str_contains($files['service'],"status='pending'")&&($phase3?str_contains($files['service'],"'consumes_license_slot'=>\$status==='approved'"):str_contains($files['service'],"'consumes_license_slot'=>false")));
$c('identity conflict protected',str_contains($files['service'],'DATAFORM_IDENTITY_CONFLICT'));
$c('surface policy enforced',str_contains($files['service'],'assertForLicense'));
$c('legacy runtime gate retained until phase3',($phase3&&!str_contains($files['runtime'],'claimDataform')&&str_contains($files['runtime'],'assertApproved'))||(!$phase3&&str_contains($files['runtime'],'claimDataform')));
$fail=0;foreach($checks as[$name,$ok]){echo str_pad($name,50).($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;}
echo 'FINAL STATUS: '.($fail?'FAILED':'PASSED').PHP_EOL;exit($fail?1:0);
