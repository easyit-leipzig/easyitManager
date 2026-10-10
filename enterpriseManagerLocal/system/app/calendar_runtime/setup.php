<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
$example=__DIR__.'/config.php.example';$target=__DIR__.'/config.php';$error='';$ok='';
$needsConfig=!is_file($target);
if($needsConfig&&!is_file($example)){http_response_code(500);exit('config.php.example fehlt.');}
$base=$needsConfig?(require $example):(require $target);if(!is_array($base))exit('Ungültige Konfiguration.');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $phase=(string)($_POST['phase']??'');
  if($phase==='datasource'){
   $d=(string)($base['db_driver']??'mysql');$base['db_host']=trim((string)($_POST['host']??'127.0.0.1'));$base['db_port']=(int)($_POST['port']??3306);$base['db_name']=trim((string)($_POST['database']??$base['db_name']??''));$base['db_schema']=trim((string)($_POST['schema']??$base['db_schema']??'public'));$base['db_user']=trim((string)($_POST['user']??''));$base['db_password']=(string)($_POST['password']??'');$php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($base,true).";\n";if(file_put_contents($target,$php,LOCK_EX)===false)throw new RuntimeException('config.php konnte nicht gespeichert werden.');header('Location: setup.php');exit;
  }
  if($phase==='admin'){
   cal_auth_add_first_admin((string)($_POST['username']??''),(string)($_POST['email']??''),(string)($_POST['password']??''));header('Location: login.php');exit;
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
if(!$needsConfig&&cal_auth_ready()){cal_auth_require('admin');header('Location: admin.php');exit;}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>easyITCalendar Setup</title><style>body{font:16px system-ui;max-width:760px;margin:3rem auto;padding:1rem}label{display:block;margin:1rem 0}input{width:100%;padding:.6rem;box-sizing:border-box}.err{color:#991b1b}.note{background:#eef5fb;padding:.8rem;border-left:4px solid #0b5ca5}button{padding:.7rem 1rem}</style></head><body><h1>easyITCalendar – Ersteinrichtung</h1><?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?><?php if($needsConfig):?><p class="note">Zuerst die Datenquelle des exportierten Calendar-Pakets konfigurieren.</p><form method="post"><input type="hidden" name="phase" value="datasource"><label>Host<input name="host" value="127.0.0.1"></label><label>Port<input name="port" value="<?=htmlspecialchars((string)($base['db_port']??3306))?>"></label><label>Datenbank<input name="database" value="<?=htmlspecialchars((string)($base['db_name']??''))?>"></label><label>Schema<input name="schema" value="<?=htmlspecialchars((string)($base['db_schema']??'public'))?>"></label><label>Benutzer<input name="user"></label><label>Kennwort<input type="password" name="password"></label><button>Datenquelle speichern</button></form><?php else:?><p class="note">Nun den ersten lokalen Calendar-Administrator anlegen. Dieser Zugang ist unabhängig vom Enterprise-Manager.</p><form method="post"><input type="hidden" name="phase" value="admin"><label>Benutzername<input name="username" required></label><label>E-Mail<input type="email" name="email"></label><label>Kennwort<input type="password" name="password" required></label><p>Mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie mindestens eine Ziffer.</p><button>Administrator anlegen</button></form><?php endif;?></body></html>