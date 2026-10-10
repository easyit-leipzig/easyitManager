<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function hf137(bool $ok,string $label):void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function hf137f(string $p):string{global $root;$s=@file_get_contents($root.'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$setup=hf137f('setup/index.php');$ds=hf137f('src/Setup/DataSourceSetup.php');$auth=hf137f('src/Admin/AdminAuth.php');$app=hf137f('src/App/Application.php');$shortcut=hf137f('setup.php');
hf137(str_contains($setup,'Lizenzserver vollständig einrichten'),'Setup ist als zentrale Ein-Seiten-Einrichtung beschriftet');
hf137(str_contains($setup,'existing_database_action')&&str_contains($setup,'value="keep"')&&str_contains($setup,'value="rebuild"'),'Vorhandene Datenbank erzwingt explizite Entscheidung beibehalten/neu aufbauen');
hf137(str_contains($setup,'confirm_rebuild')&&str_contains($setup,'REBUILD_CONFIRMATION_REQUIRED'),'Destruktiver Neuaufbau besitzt separate Sicherheitsbestätigung');
hf137(str_contains($setup,'admin_username')&&str_contains($setup,'admin_password')&&str_contains($setup,'admin_password_confirm'),'Superadministrator wird auf derselben Seite eingerichtet');
hf137(str_contains($auth,'setupTrustedLocal')&&str_contains($auth,"'local_setup'"),'Lokaler Ein-Seiten-Installer besitzt dedizierten Erstadmin-Pfad');
hf137(str_contains($ds,'bool $rebuild=true')&&str_contains($ds,'$rebuild?$runner->rebuild():$runner->migrate()'),'Datenquelle unterstützt destruktiven Neuaufbau und nicht-destruktives Beibehalten');
hf137(str_contains($setup,'$targetExists')&&str_contains($setup,'EXISTING_DATABASE_ACTION_REQUIRED'),'Existenz der Zieldatenbank wird vor Initialisierung geprüft');
hf137(str_contains($setup,"\$status['ready']")&&str_contains($setup,"'READY':'SETUP_REQUIRED'"),'Setup zeigt eindeutigen READY/SETUP_REQUIRED-Status');
hf137(str_contains($app,"'status'=>\$setupReady?'ready':'setup_required'")&&str_contains($app,"'ready'=>\$setupReady"),'Health-Endpunkt veröffentlicht READY-Status');
hf137(str_contains($shortcut,"'/setup/'"),'Root-setup.php führt auf die einzige Setup-Seite');
$fails=array_filter($checks,fn($c)=>!$c[0]);echo 'HF137_CHECKS='.count($checks)."\n";if($fails){echo "HF137_ONE_PAGE_SETUP_FAILED\n";exit(1);}echo "HF137_ONE_PAGE_SETUP_OK\n";
