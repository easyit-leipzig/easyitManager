<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Central PDO connection factory.
 *
 * HF110: the data source is selected by the setup assistant. MySQL/MariaDB,
 * PostgreSQL and SQLite are supported when the corresponding PDO driver is
 * installed. Missing SQL databases/schemas may be provisioned during setup.
 */
final class Connection
{
    public static function create(array $cfg): PDO
    {
        $dsn=trim((string)($cfg['dsn']??''));
        $user=(string)($cfg['user']??'');
        $password=(string)($cfg['password']??'');
        if($dsn==='')throw new RuntimeException('DATASOURCE_NOT_CONFIGURED');

        $driver=self::driver($cfg,$dsn);
        if(!in_array($driver,PDO::getAvailableDrivers(),true)){
            throw new RuntimeException('PDO_DRIVER_NOT_AVAILABLE: '.$driver);
        }
        if($driver==='sqlite')self::prepareSqlite($dsn);

        try{
            $pdo=self::connect($dsn,$user,$password);
        }catch(PDOException $e){
            if(!self::isMissingDatabase($driver,$e))throw $e;
            if(array_key_exists('auto_create_database',$cfg)&&!((bool)$cfg['auto_create_database']))throw $e;
            self::createConfiguredDatabase($driver,$dsn,$user,$password,$e);
            $pdo=self::connect($dsn,$user,$password);
        }

        self::configure($pdo,$cfg);
        return $pdo;
    }

    /**
     * Nicht-destruktiver Setup-Test. Er prueft Server/Dateipfad und meldet,
     * ob die konfigurierte Zieldatenbank bereits existiert. Eine fehlende
     * Datenbank wird hier noch nicht angelegt; das geschieht erst beim Setup.
     *
     * @return array{driver:string,target_exists:bool,server_version:string}
     */
    public static function probeSetup(array $cfg): array
    {
        $dsn=trim((string)($cfg['dsn']??''));
        $user=(string)($cfg['user']??'');
        $password=(string)($cfg['password']??'');
        if($dsn==='')throw new RuntimeException('DATASOURCE_NOT_CONFIGURED');
        $driver=self::driver($cfg,$dsn);
        if(!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO_DRIVER_NOT_AVAILABLE: '.$driver);

        if($driver==='sqlite'){
            self::prepareSqlite($dsn);
            $path=substr($dsn,7);
            $exists=$path===':memory:'||is_file($path);
            $version='';
            if($exists){
                $pdo=self::connect($dsn,'','');
                self::configure($pdo,$cfg);
                try{$version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);}catch(\Throwable){}
            }
            return ['driver'=>'sqlite','target_exists'=>$exists,'server_version'=>$version];
        }

        try{
            $pdo=self::connect($dsn,$user,$password);
            // Der Verbindungstest darf keine Struktur veraendern. Insbesondere
            // wird ein fehlendes PostgreSQL-Schema hier noch nicht angelegt.
            if($driver==='mysql')self::configure($pdo,$cfg);
            $version='';try{$version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);}catch(\Throwable){}
            return ['driver'=>$driver,'target_exists'=>true,'server_version'=>$version];
        }catch(PDOException $e){
            if(!self::isMissingDatabase($driver,$e))throw $e;
        }

        // Ziel fehlt: Serverzugang separat pruefen, ohne die Datenbank anzulegen.
        if($driver==='mysql')$pdo=self::connect(self::withoutDatabase($dsn),$user,$password);
        elseif($driver==='pgsql')$pdo=self::connect(self::withoutDatabase($dsn,'postgres'),$user,$password);
        else throw new RuntimeException('DATASOURCE_DRIVER_NOT_SUPPORTED_FOR_SETUP: '.$driver);
        $version='';try{$version=(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);}catch(\Throwable){}
        return ['driver'=>$driver,'target_exists'=>false,'server_version'=>$version];
    }

    private static function driver(array $cfg,string $dsn): string
    {
        $driver=strtolower(trim((string)($cfg['driver']??'')));
        if($driver!=='')return $driver;
        return strtolower((string)strtok($dsn,':'));
    }

    private static function connect(string $dsn,string $user,string $password): PDO
    {
        return new PDO($dsn,$user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }

    private static function configure(PDO $pdo,array $cfg): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET collation_connection='utf8mb4_unicode_ci'");
            return;
        }
        if($driver==='sqlite'){
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return;
        }
        if($driver==='pgsql'){
            $schema=trim((string)($cfg['schema']??'public')) ?: 'public';
            if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/',$schema))throw new RuntimeException('DATASOURCE_SCHEMA_INVALID');
            $check=$pdo->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$check->execute([$schema]);
            if(!$check->fetchColumn()){
                if(array_key_exists('auto_create_schema',$cfg)&&!((bool)$cfg['auto_create_schema']))throw new RuntimeException('DATASOURCE_SCHEMA_MISSING');
                try{$pdo->exec('CREATE SCHEMA '.self::quotePgIdentifier($schema));}
                catch(PDOException $e){throw new RuntimeException('SCHEMA_BOOTSTRAP_FAILED: Das PostgreSQL-Schema "'.$schema.'" fehlt und konnte nicht angelegt werden. Bitte CREATE-Recht fuer das Schema vergeben. Ursache: '.$e->getMessage(),0,$e);}
            }
            $pdo->exec('SET search_path TO '.self::quotePgIdentifier($schema));
        }
    }

    private static function prepareSqlite(string $dsn): void
    {
        if(!str_starts_with($dsn,'sqlite:'))return;
        $path=substr($dsn,7);
        if($path===''||$path===':memory:')return;
        $dir=dirname($path);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('DATASOURCE_SQLITE_DIRECTORY_NOT_WRITABLE');
        if(!is_writable($dir))throw new RuntimeException('DATASOURCE_SQLITE_DIRECTORY_NOT_WRITABLE');
    }

    private static function isMissingDatabase(string $driver,PDOException $e): bool
    {
        $sqlState=(string)$e->getCode();$native=(int)($e->errorInfo[1]??0);$message=strtolower($e->getMessage());
        if($driver==='mysql')return $native===1049||str_contains($message,'unknown database');
        if($driver==='pgsql')return $sqlState==='3D000'||str_contains($message,'database')&&str_contains($message,'does not exist');
        return false;
    }

    private static function createConfiguredDatabase(string $driver,string $dsn,string $user,string $password,PDOException $original): void
    {
        $parts=self::dsnParts($dsn);$database=trim((string)($parts['dbname']??''));
        if($database==='')throw $original;
        try{
            if($driver==='mysql'){self::createMysqlDatabase($dsn,$user,$password,$database);return;}
            if($driver==='pgsql'){self::createPgsqlDatabase($dsn,$user,$password,$database);return;}
        }catch(PDOException $bootstrapError){
            throw new RuntimeException(
                'DATABASE_BOOTSTRAP_FAILED: Die konfigurierte Datenbank "'.$database.'" fehlt und konnte mit dem DB-Benutzer "'.$user.'" nicht automatisch angelegt werden. '.
                'Bitte CREATE-DATABASE-Recht vergeben, die Option zum automatischen Anlegen deaktivieren oder die Datenbank manuell anlegen. Ursache: '.$bootstrapError->getMessage(),0,$bootstrapError
            );
        }
        throw $original;
    }

    /** @return array<string,string> */
    private static function dsnParts(string $dsn): array
    {
        $colon=strpos($dsn,':');if($colon===false)return [];$tail=substr($dsn,$colon+1);$out=[];
        foreach(explode(';',$tail) as $part){$part=trim($part);if($part===''||!str_contains($part,'='))continue;[$key,$value]=array_map('trim',explode('=',$part,2));if($key!=='')$out[strtolower($key)]=$value;}
        return $out;
    }

    private static function withoutDatabase(string $dsn,?string $replacement=null): string
    {
        $colon=strpos($dsn,':');if($colon===false)return $dsn;$prefix=substr($dsn,0,$colon+1);$tail=substr($dsn,$colon+1);$kept=[];
        foreach(explode(';',$tail) as $part){$trim=trim($part);if($trim===''||str_starts_with(strtolower($trim),'dbname='))continue;$kept[]=$trim;}
        if($replacement!==null)$kept[]='dbname='.$replacement;
        return $prefix.implode(';',$kept);
    }

    private static function createMysqlDatabase(string $dsn,string $user,string $password,string $database): void
    {
        $pdo=self::connect(self::withoutDatabase($dsn),$user,$password);$quoted='`'.str_replace('`','``',$database).'`';
        $pdo->exec('CREATE DATABASE IF NOT EXISTS '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private static function createPgsqlDatabase(string $dsn,string $user,string $password,string $database): void
    {
        $pdo=self::connect(self::withoutDatabase($dsn,'postgres'),$user,$password);
        $check=$pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');$check->execute([$database]);if($check->fetchColumn())return;
        $pdo->exec('CREATE DATABASE '.self::quotePgIdentifier($database)." ENCODING 'UTF8'");
    }

    private static function quotePgIdentifier(string $value): string
    {
        return '"'.str_replace('"','""',$value).'"';
    }
}
