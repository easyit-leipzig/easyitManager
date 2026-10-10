<?php
declare(strict_types=1);
session_start();
$root=__DIR__;
foreach ([$root.'/lib/EnterprisePgsqlPdo.php', dirname($root).'/EnterprisePgsqlPdo.php'] as $compat) { if (is_file($compat)) { require_once $compat; break; } }
foreach ([$root.'/lib/EnterpriseOraclePdo.php', dirname($root).'/EnterpriseOraclePdo.php'] as $compat) { if (is_file($compat)) { require_once $compat; break; } }
foreach ([$root.'/lib/EnterpriseMssqlAdapter.php', dirname($root).'/EnterpriseMssqlAdapter.php'] as $compat) { if (is_file($compat)) { require_once $compat; break; } }
foreach ([$root.'/lib/EnterpriseMssqlPdo.php', dirname($root).'/EnterpriseMssqlPdo.php'] as $compat) { if (is_file($compat)) { require_once $compat; break; } }

function importMysqlSqlScript(PDO $pdo,string $sql): void {
    $delimiter=';'; $buffer='';
    foreach(preg_split('/\R/',$sql)?:[] as $line){
        $trim=trim($line);
        if($trim==='' || str_starts_with($trim,'-- ')) continue;
        if(preg_match('/^DELIMITER\s+(.+)$/i',$trim,$m)){ if(trim($buffer)!==''){$pdo->exec($buffer);$buffer='';} $delimiter=trim($m[1]); continue; }
        $buffer.=$line."\n";
        $rtrim=rtrim($buffer);
        if($delimiter!=='' && str_ends_with($rtrim,$delimiter)){
            $statement=substr($rtrim,0,-strlen($delimiter));
            if(trim($statement)!=='')$pdo->exec($statement);
            $buffer='';
        }
    }
    if(trim($buffer)!=='')$pdo->exec($buffer);
}

/** @return list<string> */
function splitPostgreSqlScript(string $sql): array {
    $out=[];$buf='';$len=strlen($sql);$single=false;$double=false;$lineComment=false;$blockComment=false;$dollar=null;
    for($i=0;$i<$len;$i++){
        $c=$sql[$i];$n=$i+1<$len?$sql[$i+1]:'';
        if($lineComment){$buf.=$c;if($c==="\n")$lineComment=false;continue;}
        if($blockComment){$buf.=$c;if($c==='*'&&$n==='/'){$buf.='/';$i++;$blockComment=false;}continue;}
        if($dollar!==null){
            $tag=$dollar;$tagLen=strlen($tag);
            if(substr($sql,$i,$tagLen)===$tag){$buf.=$tag;$i+=$tagLen-1;$dollar=null;continue;}
            $buf.=$c;continue;
        }
        if($single){$buf.=$c;if($c==="'" && $n==="'"){$buf.=$n;$i++;continue;}if($c==="'")$single=false;continue;}
        if($double){$buf.=$c;if($c==='"' && $n==='"'){$buf.=$n;$i++;continue;}if($c==='"')$double=false;continue;}
        if($c==='-'&&$n==='-'){$buf.='--';$i++;$lineComment=true;continue;}
        if($c==='/'&&$n==='*'){$buf.='/*';$i++;$blockComment=true;continue;}
        if($c==="'"){$single=true;$buf.=$c;continue;}
        if($c==='"'){$double=true;$buf.=$c;continue;}
        if($c==='$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/A',substr($sql,$i),$m)){$dollar=$m[0];$buf.=$dollar;$i+=strlen($dollar)-1;continue;}
        if($c===';'){$stmt=trim($buf);if($stmt!=='')$out[]=$stmt;$buf='';continue;}
        $buf.=$c;
    }
    $stmt=trim($buf);if($stmt!=='')$out[]=$stmt;
    return $out;
}
function importPostgreSqlScript(PDO $pdo,string $sql): void { foreach(splitPostgreSqlScript($sql) as $stmt)$pdo->exec($stmt); }
/** @return list<string> */
function splitOracleSqlScript(string $sql): array { $out=[];$buf='';$single=false;$double=false;for($i=0,$n=strlen($sql);$i<$n;$i++){ $c=$sql[$i];$nx=$i+1<$n?$sql[$i+1]:''; if($single){$buf.=$c;if($c==="'"&&$nx==="'"){$buf.=$nx;$i++;continue;}if($c==="'")$single=false;continue;} if($double){$buf.=$c;if($c==='"'&&$nx==='"'){$buf.=$nx;$i++;continue;}if($c==='"')$double=false;continue;} if($c==="'"){$single=true;$buf.=$c;continue;}if($c==='"'){$double=true;$buf.=$c;continue;}if($c===';'){$x=trim($buf);if($x!==''&&!str_starts_with($x,'--'))$out[]=$x;$buf='';continue;}$buf.=$c;} $x=trim($buf);if($x!=='')$out[]=$x;return $out;}
function importOracleSqlScript(PDO $pdo,string $sql): void { foreach(splitOracleSqlScript($sql) as $stmt)$pdo->exec($stmt); }
function importMssqlSqlScript(PDO $pdo,string $sql): void { foreach(preg_split('/^\s*GO\s*$/mi',$sql)?:[] as $batch){$batch=trim($batch);if($batch!=='')$pdo->exec($batch);} }

function setupDriver(array $manifest): string {
    $driver=strtolower(trim((string)($manifest['project']['database_driver']??'mysql')));
    if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';
    if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true))throw new RuntimeException('Dieses Anwenderpaket unterstützt den enthaltenen Datenbanktreiber nicht: '.$driver);
    return $driver;
}
function quotePgIdentifier(string $name): string { if(preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$name)!==1)throw new RuntimeException('Ungültiger Datenbankname.');return '"'.str_replace('"','""',$name).'"'; }
function quotePgSchemaIdentifier(string $name): string { if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$name)!==1)throw new RuntimeException('Ungültiger PostgreSQL-Schemaname.');return '"'.$name.'"'; }

$manifest=json_decode((string)@file_get_contents($root.'/install/manifest.json'),true)?:[];$project=$manifest['project']??[];$msg='';$err='';
try{$driver=setupDriver($manifest);}catch(Throwable $e){$driver='mysql';$err=$e->getMessage();}
if(is_file($root.'/config.php')){header('Location: index.php');exit;}
if(empty($_SESSION['setup_csrf']))$_SESSION['setup_csrf']=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'&&$err==='')try{
 if(!hash_equals((string)$_SESSION['setup_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sicherheitsprüfung fehlgeschlagen.');
 $host=trim((string)$_POST['host']);$port=max(1,(int)$_POST['port']);$db=trim((string)$_POST['database']);$user=trim((string)$_POST['user']);$pass=(string)$_POST['password'];$dbEnginePath=trim((string)($_POST['db_engine_path']??''));if($dbEnginePath!==''&&!is_file(rtrim(str_replace('\\','/',$dbEnginePath),'/').'/bootstrap.php'))throw new RuntimeException('Der angegebene DB-Engine-Pfad enthält keine bootstrap.php.');if(preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$db)!==1)throw new RuntimeException('Ungültiger Datenbankname.');
 if($driver==='oracle'){
   if(!extension_loaded('pdo_oci'))throw new RuntimeException('PHP-Erweiterung pdo_oci ist nicht aktiv.');
   $service=trim((string)($_POST['service']??'XEPDB1'))?:'XEPDB1';
   if(!class_exists('EnterpriseOraclePdo'))throw new RuntimeException('Oracle-XE-Kompatibilitätsklasse fehlt im Anwenderpaket.');
   $pdo=new EnterpriseOraclePdo($host,$port,$service,$user,$pass);
   $sql=(string)file_get_contents($root.'/install/database.sql');importOracleSqlScript($pdo,$sql);
 }elseif($driver==='mssql'){
   if(!extension_loaded('pdo_sqlsrv'))throw new RuntimeException('PHP-Erweiterung pdo_sqlsrv ist nicht aktiv.');
   if(!class_exists('EnterpriseMssqlPdo'))throw new RuntimeException('MSSQL-Kompatibilitätsklasse fehlt im Anwenderpaket.');
   $server=new EnterpriseMssqlPdo($host,$port,'master',$user,$pass,true,false);
   if(!empty($_POST['create_db'])){$st=$server->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');$st->execute([$db]);if((int)$st->fetchColumn()===0)$server->exec('CREATE DATABASE ['.str_replace(']',']]', $db).']');}
   $pdo=new EnterpriseMssqlPdo($host,$port,$db,$user,$pass,true,false);
   $sql=(string)file_get_contents($root.'/install/database.sql');importMssqlSqlScript($pdo,$sql);
 }elseif($driver==='pgsql'){
   if(!extension_loaded('pdo_pgsql'))throw new RuntimeException('PHP-Erweiterung pdo_pgsql ist nicht aktiv.');
   $maintenance=trim((string)($_POST['maintenance_database']??'postgres'))?:'postgres';
   $schema=trim((string)($_POST['schema']??($project['database_schema']??'public')))?:'public';
   quotePgSchemaIdentifier($schema);
   $server=new PDO('pgsql:host='.$host.';port='.$port.';dbname='.$maintenance,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
   $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');$st->execute([$db]);
   if($st->fetchColumn()===false){
       if(empty($_POST['create_db']))throw new RuntimeException('PostgreSQL-Datenbank existiert nicht: '.$db);
       $server->exec('CREATE DATABASE '.quotePgIdentifier($db)." ENCODING 'UTF8' TEMPLATE template0");
       $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');$st->execute([$db]);if($st->fetchColumn()===false)throw new RuntimeException('PostgreSQL-Datenbank wurde nach CREATE DATABASE nicht gefunden: '.$db);
   }
   if(!class_exists('EnterprisePgsqlPdo'))throw new RuntimeException('PostgreSQL-Kompatibilitätsklasse fehlt im Anwenderpaket.');
   $schemaAdmin=new EnterprisePgsqlPdo($host,$port,$db,$user,$pass,'public');
   $schemaAdmin->exec('CREATE SCHEMA IF NOT EXISTS '.quotePgSchemaIdentifier($schema));
   $st=$schemaAdmin->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$st->execute([$schema]);if($st->fetchColumn()===false)throw new RuntimeException('PostgreSQL-Schema wurde nach CREATE SCHEMA nicht gefunden: '.$schema);
   $pdo=new EnterprisePgsqlPdo($host,$port,$db,$user,$pass,$schema);
   $sql=(string)file_get_contents($root.'/install/database.sql');importPostgreSqlScript($pdo,$sql);
 }else{
   if(!extension_loaded('pdo_mysql'))throw new RuntimeException('PHP-Erweiterung pdo_mysql ist nicht aktiv.');
   $server=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
   if(!empty($_POST['create_db']))$server->exec('CREATE DATABASE IF NOT EXISTS `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
   $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]; if(defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS'))$opts[PDO::MYSQL_ATTR_MULTI_STATEMENTS]=true; $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$db.';charset=utf8mb4',$user,$pass,$opts);
   $sql=(string)file_get_contents($root.'/install/database.sql');importMysqlSqlScript($pdo,$sql);
 }
 $cfg=['db_driver'=>$driver,'db_host'=>$host,'db_port'=>$port,'db_service'=>$driver==='oracle'?($service??'XEPDB1'):'','db_name'=>$db,'db_schema'=>$driver==='pgsql'?($schema??'public'):'','db_user'=>$user,'db_password'=>$pass,'db_engine_path'=>$dbEnginePath,'db_encrypt'=>$driver==='mssql','db_trust_server_certificate'=>false,'project_id'=>(int)($project['source_project_id']??$project['id']??1),'project_name'=>(string)($project['name']??'Projekt'),'storage_key'=>(string)($project['storage_key']??'')];
 $content="<?php\ndeclare(strict_types=1);\nreturn ".var_export($cfg,true).";\n";if(file_put_contents($root.'/config.php',$content,LOCK_EX)===false)throw new RuntimeException('config.php konnte nicht geschrieben werden.');@chmod($root.'/config.php',0600);header('Location: index.php');exit;
}catch(Throwable $e){$err=$e->getMessage();}
$portDefault=$driver==='pgsql'?5432:($driver==='oracle'?1521:($driver==='mssql'?1433:3306));$driverLabel=$driver==='pgsql'?'PostgreSQL':($driver==='oracle'?'Oracle XE':($driver==='mssql'?'Microsoft SQL Server':'MySQL / MariaDB'));
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Projekt einrichten</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="setup"><img class="setup-logo" src="assets/img/easyit-epManager-logo.png" alt="easyIT"><h1><?=htmlspecialchars((string)($project['name']??'Projekt'))?> einrichten</h1><p><strong>Datenbanktyp:</strong> <?=htmlspecialchars($driverLabel)?></p><p>Optional kann dieses DataForm-Projekt eine separat exportierte, projektlizenzierte DB-Engine verwenden. Dann wird deren lokaler Dateisystempfad eingetragen.</p><?php if($err):?><div class="notice error"><?=htmlspecialchars($err)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['setup_csrf'])?>"><label>DB-Engine-Pfad (optional)<input name="db_engine_path" placeholder="z. B. D:/apps/easyIT-DB-Engine-projekt"></label><label>DB-Host<input name="host" value="localhost" required></label><label>Port<input name="port" type="number" value="<?=$portDefault?>" required></label><?php if($driver==='pgsql'):?><label>Maintenance-Datenbank<input name="maintenance_database" value="postgres" required></label><label>PostgreSQL-Schema<input name="schema" value="<?=htmlspecialchars((string)($project['database_schema']??'public'))?>" required></label><?php elseif($driver==='oracle'):?><label>Oracle Service/PDB<input name="service" value="XEPDB1" required></label><?php endif;?><label>Datenbank<input name="database" value="<?=htmlspecialchars((string)($project['database_name']??'projekt'))?>" required></label><label>DB-Benutzer<input name="user" required></label><label>DB-Kennwort<input name="password" type="password"></label><?php if($driver!=='oracle'):?><label class="check"><input type="checkbox" name="create_db" value="1" checked> Datenbank anlegen, falls sie noch nicht existiert</label><?php else:?><p>Oracle XE: Das Ziel-Schema/der Benutzer muss bereits existieren; das Paket installiert die Projektobjekte in dieses Schema.</p><?php endif;?><button>Installation starten</button></form><p>Das Paket installiert ausschließlich dieses Projekt und seine DataForms.</p></main></body></html>
