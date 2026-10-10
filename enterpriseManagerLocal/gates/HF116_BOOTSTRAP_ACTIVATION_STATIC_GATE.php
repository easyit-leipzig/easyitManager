<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/system/licensing/LicenseApiClient.php');
$remote=(string)file_get_contents($root.'/app/licensing/remote.php');
$checks=[
 ['installation_id beim Account-Login',str_contains($client,"'installation_id'=>".'$installationId')],
 ['Bindingstatus wird ausgewertet',str_contains($client,"installation_binding")&&str_contains($client,"['bound','already_bound']")],
 ['Lokaler Lizenzzustand wird aktualisiert',str_contains($client,"activation_mode'=>'customer_license'")&&str_contains($client,"license_policy")],
 ['UI erklaert Bootstrap-Aktivierung',str_contains($remote,'Bootstrap-Aktivierungsschlüssel ohne Kundenkonto')],
];
$fail=0;foreach($checks as [$label,$ok]){echo ($ok?'PASS':'FAIL')." - {$label}\n";if(!$ok)$fail++;}
if($fail){fwrite(STDERR,"HF116 manager gate failed: {$fail}\n");exit(1);}echo 'HF116_MANAGER_BOOTSTRAP_GATE_OK '.count($checks).'/'.count($checks)."\n";
