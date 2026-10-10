<?php
declare(strict_types=1);
$checks=[];
function ck(bool $ok,string $label):void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc(string $p):string{$s=@file_get_contents(__DIR__.'/../'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$remote=fc('app/licensing/remote.php');
$login=fc('login.php');
$version=trim(fc('VERSION'));
ck(str_contains($version,'HF111-LICENSE-SERVER-TRUST-REPAIR')||str_contains($version,'HF112-INSTALLER-ADMIN-SCHEMA-GATE')||str_contains($version,'HF117-AUDIT-ACTOR-FK-REPAIR')||str_contains($version,'HF118-CSV-RELATIONS-EXPRESSION-REPAIR')||str_contains($version,'HF119-CALENDAR-EMBEDDED-DATAFORM-CSV-WILDCARD')||str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION'),'HF111 Buildkennung');
ck(str_contains($remote,'sodium_crypto_sign_verify_detached($signature,$raw,$decoded)'),'Health-Antwort wird gegen publizierten Ed25519-Key geprüft');
ck(str_contains($remote,"'probe_trust'")&&str_contains($remote,"'replace_trust'"),'Explizite Prüfen-/Neu-Koppeln-Aktionen vorhanden');
ck(str_contains($remote,'NEU KOPPELN'),'Explizite Admin-Bestätigung erforderlich');
ck(str_contains($remote,'expected_fingerprint')&&str_contains($remote,'hash_equals($expected'),'Fingerprint wird vor Austausch erneut gebunden geprüft');
ck(str_contains($remote,'anderen Vertrauensschlüssel')&&str_contains($remote,'nicht automatisch ersetzt'),'Stiller Trust-Austausch blockiert');
ck(str_contains($remote,'server_trust_repair_history'),'Neu-Kopplung wird lokal protokolliert');
ck(str_contains($remote,'dynamic_server_keys')&&str_contains($remote,'SERVER_TRUST_REPAIRED'),'Dynamischer Trust-Cache wird kontrolliert zurückgesetzt');
ck(str_contains($login,'license_server_trust')&&str_contains($login,'SERVER_SIGNATURE_UNTRUSTED'),'Login verweist bei Trust-Fehler auf lokale Administration');
ck(str_contains($remote,"cfg['storage_dir']")&&str_contains($remote,'InstallationIdentity'),'Bestehende lokale Lizenz-Storage-Identität wird weiterverwendet');
$fail=array_filter($checks,static fn(array $r):bool=>!$r[0]);
exit($fail?1:0);
