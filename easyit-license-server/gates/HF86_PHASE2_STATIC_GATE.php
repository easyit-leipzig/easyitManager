<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
    'app'=>$get('src/App/Application.php'),
    'license'=>$get('src/Service/LicenseService.php'),
    'registry'=>$get('src/Service/DataFormRegistryService.php'),
    'admin'=>$get('src/Admin/AdminApp.php'),
];
$fail=0;
$c=function(string $name,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('signed dataform policy endpoint exists',str_contains($f['app'],"/api/v1/dataforms/policy")&&str_contains($f['app'],'$registry->policy($inst,$data)'));
$c('server resolves effective per-DF policy',str_contains($f['license'],'function resolvedDataformPolicy')&&str_contains($f['license'],'allowedCapabilitiesForDataform')&&str_contains($f['license'],'has_dataform_overrides'));
$c('per-DF policy cannot widen global deny',str_contains($f['license'],'Explicit true override cannot widen a global deny')&&str_contains($f['license'],'if(!$enabled) continue'));
$c('policy endpoint requires approved signed DF token',str_contains($f['registry'],'function policy(array $installation, array $payload)')&&str_contains($f['registry'],'tokens->assertApproved')&&str_contains($f['registry'],'resolvedDataformPolicy'));
$c('policy endpoint still applies surface policy',str_contains($f['registry'],'surfacePolicy->assertForLicense($licenseId,$payload)'));
$c('admin policy editing requires approved registry identity',str_contains($f['admin'],'DATAFORM_POLICY_REQUIRES_APPROVED')&&str_contains($f['admin'],"status']!=='approved"));
$c('admin exposes DF rules from registry',str_contains($f['admin'],'DF-Regeln')&&str_contains($f['admin'],'dataform_policy'));
$c('admin stores only reducing per-DF overrides',str_contains($f['admin'],'globallyAllowed')&&str_contains($f['admin'],'!isset($selected[$code])')&&str_contains($f['admin'],'license_dataform_capabilities'));
$c('policy update increments license generation',str_contains($f['admin'],'DATAFORM_CAPABILITY_POLICY_UPDATED')&&str_contains($f['admin'],'SET generation=generation+1'));
echo $fail?"HF86_PHASE2_SERVER_GATE FAIL ($fail)\n":"HF86_PHASE2_SERVER_GATE PASS\n";
exit($fail?1:0);
