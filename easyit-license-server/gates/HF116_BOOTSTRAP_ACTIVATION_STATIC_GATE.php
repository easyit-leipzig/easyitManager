<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc(string $p): string {$s=@file_get_contents($p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$svc=fc($root.'/src/Service/BootstrapActivationService.php');
$act=fc($root.'/src/Service/ActivationService.php');
$acct=fc($root.'/src/Service/AccountService.php');
$admin=fc($root.'/src/Admin/AdminApp.php');
ck(str_contains($svc,"LICENSE_ID='LICID_BOOTSTRAP_MANAGER'")&&str_contains($svc,"LICENSE_NUMBER='BOOTSTRAP-MANAGER'"),'Technische Bootstrap-Lizenz ist eindeutig definiert');
ck(str_contains($svc,"CUSTOMER_ID='__SYSTEM_BOOTSTRAP__'")&&str_contains($svc,'one-time bootstrap activation'),'Bootstrap benötigt kein Kundenkonto');
ck(str_contains($svc,'BOOTSTRAP_ACTIVATION_KEY_ISSUED')&&str_contains($svc,'BOOTSTRAP_ACTIVATION_KEY_CONSUMED'),'Ausgabe und Verbrauch werden auditiert');
ck(str_contains($svc,"activation_secret_hash=?")&&str_contains($svc,'function consume'),'Bootstrap-Schlüssel wird nach erfolgreicher Aktivierung entwertet');
ck(str_contains($act,'activation_mode')&&str_contains($act,'$isBootstrap')&&str_contains($act,'BootstrapActivationService::isBootstrapLicense'),'ActivationService erkennt Bootstrap-Modus');
ck(str_contains($act,'$isBootstrap?[]:$this->licenses->modules'),'Bootstrap vergibt keine Module');
ck(str_contains($acct,'bindInstallation')&&str_contains($acct,"installation_binding"),'Kundenlogin kann Bootstrap-Installation an echte Lizenz binden');
ck(str_contains($svc,'$current!==self::LICENSE_ID')&&str_contains($svc,"'different_license'"),'Bestehende echte Lizenz wird nicht automatisch überschrieben');
ck(str_contains($svc,'max_installations')&&str_contains($svc,'installation_limit_reached'),'Bindung respektiert Installationskontingent');
ck(str_contains($admin,'create_bootstrap_activation')&&str_contains($admin,'Bootstrap-Aktivierungsschlüssel erzeugen'),'Superadmin kann Bootstrap-Schlüssel direkt erzeugen');
ck(str_contains($admin,'Der Superadmin benötigt dafür <strong>kein Kundenkonto</strong>'),'Admin-Oberfläche erklärt die Kontentrennung');
ck(str_contains($admin,'LEFT JOIN customers')&&str_contains($admin,'noch nicht gebunden'),'Bootstrap-Installationen bleiben im Admin sichtbar');
$fail=array_filter($checks,fn($r)=>!$r[0]);
if($fail){fwrite(STDERR,"HF116 gate failed: ".count($fail)." check(s)\n");exit(1);}echo "HF116_BOOTSTRAP_ACTIVATION_GATE_OK ".count($checks)."/".count($checks)."\n";
