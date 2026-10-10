<?php
declare(strict_types=1);
$f=file_get_contents(__DIR__.'/../src/Admin/AdminApp.php');
$checks=[
 'rotate action wired'=>"action==='rotate_key'",
 'one time secret store'=>"putSecretOnce('license_key_'",
 'one time secret read'=>"takeSecretOnce('license_key_'",
 'license list action'=>'Schlüssel erzeugen',
 'detail action'=>'Aktivierungsschlüssel erzeugen',
 'warning'=>'bisherige Aktivierungsschlüssel wird sofort ungültig',
 'hash only'=>'password_hash($secret,PASSWORD_DEFAULT)',
];
$ok=true;
foreach($checks as $n=>$needle){$pass=str_contains($f,$needle);echo ($pass?'PASS ':'FAIL ').$n.PHP_EOL;$ok=$ok&&$pass;}
echo 'HF95 Activation Key Admin Gate: '.($ok?'PASS':'FAIL').PHP_EOL;
exit($ok?0:1);
