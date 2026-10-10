<?php
declare(strict_types=1);

namespace DataForm5\Installer\Core;

use DataForm5\Installer\Exceptions\InstallerException;
use PDO;

final class AdminDatabaseInstaller
{
    public function connect(array $db,bool $withoutDatabase=false): PDO
    {
        foreach(['host','port','database','username'] as $key){
            if(!isset($db[$key])||$db[$key]==='') throw new InstallerException("DB-Konfiguration '{$key}' fehlt.");
        }
        $driver=strtolower(trim((string)($db['driver']??'mysql')));
        if($driver==='postgres'||$driver==='postgresql')$driver='pgsql';
        if($driver==='oracle'){
            require_once dirname(__DIR__,4).'/system/app/EnterpriseOraclePdo.php';
            $service=(string)($db['service']??$db['service_name']??'XEPDB1');
            return new \EnterpriseOraclePdo((string)$db['host'],(int)$db['port'],$service,(string)$db['username'],(string)($db['password']??''));
        }
        if($driver==='mssql'){
            require_once dirname(__DIR__,4).'/system/app/EnterpriseMssqlPdo.php';
            $database=$withoutDatabase?'master':(string)$db['database'];
            return new \EnterpriseMssqlPdo((string)$db['host'],(int)$db['port'],$database,(string)$db['username'],(string)($db['password']??''),(bool)($db['encrypt']??true),(bool)($db['trust_server_certificate']??false));
        }
        if($driver==='pgsql'){
            require_once dirname(__DIR__,4).'/system/app/EnterprisePgsqlPdo.php';
            $database=$withoutDatabase?(string)($db['maintenance_database']??'postgres'):(string)$db['database'];
            $schema=$withoutDatabase?'public':trim((string)($db['schema']??'public'));
            if($schema==='')$schema='public';
            return new \EnterprisePgsqlPdo((string)$db['host'],(int)$db['port'],$database,(string)$db['username'],(string)($db['password']??''),$schema);
        }
        $dsn='mysql:host='.(string)$db['host'].';port='.(int)$db['port'].';charset=utf8mb4';
        if(!$withoutDatabase) $dsn.=';dbname='.(string)$db['database'];
        return new PDO($dsn,(string)$db['username'],(string)($db['password']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }

    public function createDatabase(array $db): void
    {
        $name=(string)$db['database'];
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$name)) throw new InstallerException('Ungültiger Datenbankname.');
        $driver=strtolower(trim((string)($db['driver']??'mysql')));
        if($driver==='postgres'||$driver==='postgresql')$driver='pgsql';
        if($driver==='oracle'){
            // Oracle XE verwendet ein vorhandenes Schema/User im PDB.
            $this->connect($db,false);
            return;
        }
        $pdo=$this->connect($db,true);
        if($driver==='mssql'){
            $q=$pdo->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');$q->execute([$name]);
            if((int)$q->fetchColumn()===0)$pdo->exec('CREATE DATABASE ['.str_replace(']',']]', $name).']');
            return;
        }
        if($driver==='pgsql'){
            $schema=trim((string)($db['schema']??'public')) ?: 'public';
            if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)) throw new InstallerException('Ungültiger PostgreSQL-Schemaname.');
            $q=$pdo->prepare('SELECT 1 FROM pg_database WHERE datname=?');$q->execute([$name]);
            if($q->fetchColumn()===false)$pdo->exec('CREATE DATABASE "'.str_replace('"','""',$name).'" ENCODING \'UTF8\' TEMPLATE template0');
            $q=$pdo->prepare('SELECT 1 FROM pg_database WHERE datname=?');$q->execute([$name]);
            if($q->fetchColumn()===false) throw new InstallerException('PostgreSQL-Datenbank wurde nach CREATE DATABASE nicht gefunden: '.$name);
            $targetDb=$db;$targetDb['database']=$name;$targetDb['schema']='public';
            $target=$this->connect($targetDb,false);
            $quoted='"'.str_replace('"','""',$schema).'"';
            $target->exec('CREATE SCHEMA IF NOT EXISTS '.$quoted);
            $st=$target->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$st->execute([$schema]);
            if($st->fetchColumn()===false) throw new InstallerException('PostgreSQL-Schema wurde nach CREATE SCHEMA nicht gefunden: '.$schema);
            return;
        }
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function installSchema(PDO $pdo,string $schemaDir): array
    {
        $files=glob(rtrim($schemaDir,'/\\').'/*.php')?:[];
        sort($files,SORT_STRING);
        $applied=[];
        foreach($files as $file){
            $migration=require $file;
            if(!is_callable($migration)) throw new InstallerException('Ungültige Schema-Datei: '.basename($file));
            $migration($pdo);
            $applied[]=basename($file);
        }
        return $applied;
    }

    public function createAdmin(PDO $pdo,string $username,string $email,string $password): int
    {
        if(!preg_match('/^[A-Za-z0-9._-]{3,120}$/',$username)) throw new InstallerException('Ungültiger Benutzername.');
        if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new InstallerException('Ungültige E-Mail-Adresse.');
        if(strlen($password)<10||!preg_match('/[A-Z]/',$password)||!preg_match('/[a-z]/',$password)||!preg_match('/\d/',$password)){
            throw new InstallerException('Admin-Kennwort muss mindestens 10 Zeichen, Groß-/Kleinbuchstaben und eine Ziffer enthalten.');
        }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if(!is_string($hash)) throw new InstallerException('Passwort-Hash konnte nicht erzeugt werden.');
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("INSERT INTO users(username,email,password_hash,is_active) VALUES(?,?,?,1)
                ON DUPLICATE KEY UPDATE email=VALUES(email),password_hash=VALUES(password_hash),is_active=1");
            $stmt->execute([$username,$email!==''?$email:null,$hash]);
            $q=$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$q->execute([$username]);$uid=(int)$q->fetchColumn();
            $rid=(int)$pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
            $superRid=(int)$pdo->query("SELECT id FROM roles WHERE name='superadmin' LIMIT 1")->fetchColumn();
            if($uid<1||$rid<1||$superRid<1) throw new InstallerException('Admin-/Superadmin-Rolle oder Benutzer konnte nicht ermittelt werden.');
            $assign=$pdo->prepare('INSERT IGNORE INTO user_roles(user_id,role_id) VALUES(?,?)');
            $assign->execute([$uid,$rid]);
            $assign->execute([$uid,$superRid]);
            $pdo->commit();
            return $uid;
        }catch(\Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public function registerProducts(PDO $pdo,array $products): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS installed_products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_key VARCHAR(80) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'available',version VARCHAR(40) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $known=[
            'dataform'=>['DataForm','5-dev'],
            'dialog'=>['Dialog',null],
            'nachhilfe'=>['Nachhilfe',null],
            'csv-engine'=>['CSV-Engine',null],
            'sqlite-engine'=>['SQLite-Engine',null],
            'pgsql-engine'=>['PostgreSQL-Engine',null],
            'oracle-engine'=>['Oracle-XE-Engine',null],
            'mssql-engine'=>['Microsoft-SQL-Server-Engine',null],
        ];
        $stmt=$pdo->prepare('INSERT INTO installed_products(product_key,label,status,version) VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE label=VALUES(label),status=VALUES(status),version=VALUES(version)');
        foreach($known as $key=>[$label,$version]){
            $status=in_array($key,$products,true)?'available':'disabled';
            $stmt->execute([$key,$label,$status,$version]);
        }
    }
}
