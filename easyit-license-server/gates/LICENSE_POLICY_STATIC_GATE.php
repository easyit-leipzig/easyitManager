<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'migration'=>file_get_contents($root.'/src/Database/MigrationRunner.php'),
 'license'=>file_get_contents($root.'/src/Service/LicenseService.php'),
 'runtime'=>file_get_contents($root.'/src/Service/RuntimeService.php'),
 'designer'=>file_get_contents($root.'/src/Service/DesignerService.php'),
 'admin'=>file_get_contents($root.'/bin/admin.php'),
];
$checks=[];$check=function(string $name,bool $ok)use(&$checks){$checks[]=[$name,$ok];};
$check('license_policies schema',str_contains($files['migration'],'license_policies'));
$check('license_capabilities schema',str_contains($files['migration'],'license_capabilities'));
$check('license_dataform_usage schema',str_contains($files['migration'],'license_dataform_usage'));
$check('per-dataform capability schema',str_contains($files['migration'],'license_dataform_capabilities'));
$check('CRUD capability registry',str_contains($files['migration'],'dataset.create')&&str_contains($files['migration'],'dataset.update')&&str_contains($files['migration'],'dataset.delete'));
$check('claimDataform implemented',str_contains($files['license'],'function claimDataform'));
$check('limit rejection implemented',str_contains($files['license'],'DATAFORM_LIMIT_REACHED'));
$check('effective capability intersection',str_contains($files['license'],'effectiveCapabilities')&&str_contains($files['license'],'allowedCapabilitiesForDataform'));
$phase3=is_file($root.'/src/Service/DataFormTokenService.php');
$check('runtime slot gate',($phase3&&!str_contains($files['runtime'],'claimDataform')&&str_contains($files['runtime'],'assertApproved'))||(!$phase3&&str_contains($files['runtime'],'claimDataform')));
$check('runtime requires read',str_contains($files['runtime'],"'dataset.read'"));
$check('designer slot gate',($phase3&&!str_contains($files['designer'],'claimDataform')&&str_contains($files['designer'],'assertApproved'))||(!$phase3&&substr_count($files['designer'],'claimDataform')>=1));
$check('designer capabilities enforced',str_contains($files['designer'],'designer.validate')&&str_contains($files['designer'],'designer.compile')&&str_contains($files['designer'],'designer.publish'));
$check('admin max-dataforms option',str_contains($files['admin'],'--max-dataforms'));
$check('admin capability controls',str_contains($files['admin'],'capability:set')&&str_contains($files['admin'],'dataform:capability:set'));
$check('admin dataform release',str_contains($files['admin'],'dataform:release'));
$fail=0;foreach($checks as[$n,$ok]){echo str_pad($n,48).($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;}echo 'FINAL STATUS: '.($fail?'FAILED':'PASSED').PHP_EOL;exit($fail?1:0);
