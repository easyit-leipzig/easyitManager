<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/DataForm5-Core/system/database/autoload.php';

use DataForm\Database\DatabaseFactory;

final class DataSourceManager
{
    private const DRIVERS = ['mysql','pgsql','sqlite','csv','oracle','mssql'];

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            driver VARCHAR(30) NOT NULL,
            config_json LONGTEXT NOT NULL,
            secret_ciphertext LONGTEXT NULL,
            secret_nonce VARCHAR(255) NULL,
            secret_tag VARCHAR(255) NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_test_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            last_test_message TEXT NULL,
            last_test_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_data_source_name(name),
            KEY idx_data_sources_driver(driver),
            KEY idx_data_sources_enabled(is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function drivers(bool $availableOnly=true): array
    {
        $all=[
            'mysql' => 'MySQL / MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'csv' => 'CSV-Engine',
            'oracle' => 'Oracle',
            'mssql' => 'Microsoft SQL Server',
        ];
        if(!$availableOnly)return $all;
        $pdoDrivers=class_exists(PDO::class)?PDO::getAvailableDrivers():[];
        $required=['mysql'=>'mysql','pgsql'=>'pgsql','sqlite'=>'sqlite','csv'=>null,'oracle'=>'oci','mssql'=>'sqlsrv'];
        return array_filter(
            $all,
            static fn(string $label,string $driver): bool => $required[$driver]===null || in_array($required[$driver],$pdoDrivers,true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function list(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        return $pdo->query('SELECT * FROM data_sources ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        self::ensureSchema($pdo);
        $stmt=$pdo->prepare('SELECT * FROM data_sources WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    public static function config(array $row): array
    {
        $cfg=json_decode((string)($row['config_json']??''),true);
        return is_array($cfg)?$cfg:[];
    }

    public static function fromPost(array $input): array
    {
        $driver=strtolower(trim((string)($input['driver']??'')));
        if(!in_array($driver,self::DRIVERS,true)){
            throw new RuntimeException('Der gewählte Datenquellentyp wird nicht unterstützt.');
        }
        if(!array_key_exists($driver,self::drivers())){
            throw new RuntimeException('Der gewählte Datenquellentyp ist in dieser PHP-Laufzeit nicht verfügbar, weil der zugehörige PDO-Treiber fehlt.');
        }

        $config=[];
        if(in_array($driver,['mysql','pgsql','mssql'],true)){
            $config=[
                'host'=>trim((string)($input['host']??'127.0.0.1')),
                'port'=>max(1,min(65535,(int)($input['port']??($driver==='pgsql'?5432:($driver==='mssql'?1433:3306))))),
                'database'=>trim((string)($input['database']??'')),
                'username'=>trim((string)($input['username']??'')),
                'charset'=>trim((string)($input['charset']??($driver==='pgsql'?'UTF8':($driver==='mssql'?'UTF-8':'utf8mb4'))))?:($driver==='pgsql'?'UTF8':($driver==='mssql'?'UTF-8':'utf8mb4')),
            ];
            if($driver==='pgsql'){
                $config['schema']=trim((string)($input['schema']??'public'))?:'public';
                if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',(string)$config['schema'])!==1){
                    throw new RuntimeException('Der PostgreSQL-Schemaname ist ungültig.');
                }
            }
            if($config['host']===''||$config['database']===''||$config['username']===''){
                throw new RuntimeException($driver==='pgsql'?'Für PostgreSQL sind Host, Datenbank, Schema und Benutzer erforderlich.':($driver==='mssql'?'Für Microsoft SQL Server sind Host, Datenbank und Benutzer erforderlich.':'Für MySQL/MariaDB sind Host, Datenbank und Benutzer erforderlich.'));
            }
        } elseif($driver==='sqlite'){
            $path=trim((string)($input['path']??''));
            if($path==='') throw new RuntimeException('Für SQLite ist der Dateipfad erforderlich.');
            $config=['path'=>$path];
        } elseif($driver==='csv'){
            $basePath=trim((string)($input['base_path']??''));
            $database=trim((string)($input['csv_database']??''));
            if($basePath===''||$database===''){
                throw new RuntimeException('Für CSV sind Basispfad und Datenbankname erforderlich.');
            }
            if(!preg_match('/^[A-Za-z0-9_-]+$/',$database)){
                throw new RuntimeException('Der CSV-Datenbankname darf nur Buchstaben, Ziffern, _ und - enthalten.');
            }
            $config=['base_path'=>$basePath,'database'=>$database];
        } elseif($driver==='oracle'){
            $config=[
                'host'=>trim((string)($input['host']??'127.0.0.1')),
                'port'=>max(1,min(65535,(int)($input['port']??1521))),
                'service_name'=>trim((string)($input['service_name']??'XEPDB1')),
                'username'=>trim((string)($input['username']??'')),
                'charset'=>trim((string)($input['oracle_charset']??'AL32UTF8'))?:'AL32UTF8',
                'dsn'=>trim((string)($input['dsn']??'')),
            ];
            if($config['username']==='') throw new RuntimeException('Für Oracle ist der Benutzer erforderlich.');
            if($config['dsn']==='' && ($config['host']===''||$config['service_name']==='')){
                throw new RuntimeException('Für Oracle sind Host und Service-Name oder eine vollständige DSN erforderlich.');
            }
        }
        return ['driver'=>$driver,'config'=>$config];
    }

    public static function save(PDO $pdo, int $id, string $name, string $driver, array $config, string $password, bool $enabled, string $keyMaterial): int
    {
        self::ensureSchema($pdo);
        $name=trim($name);
        if($name==='') throw new RuntimeException('Bitte geben Sie einen Namen für die Datenquelle ein.');
        if(mb_strlen($name)>160) throw new RuntimeException('Der Name der Datenquelle ist zu lang.');
        if(!in_array($driver,self::DRIVERS,true)) throw new RuntimeException('Ungültiger Datenquellentyp.');

        $dup=$pdo->prepare('SELECT COUNT(*) FROM data_sources WHERE name=? AND id<>?');
        $dup->execute([$name,$id]);
        if((int)$dup->fetchColumn()>0) throw new RuntimeException('Eine Datenquelle mit diesem Namen existiert bereits.');

        $secret=[null,null,null];
        $existing=$id>0?self::find($pdo,$id):null;
        if($existing){
            if ((string)$existing['driver']!==$driver && self::bindingCount($pdo,$id)>0) {
                throw new RuntimeException(
                    'Der Treiber einer bereits an DataForms gebundenen Datenquelle kann nicht geändert werden.'
                );
            }
            $secret=[
                $existing['secret_ciphertext']??null,
                $existing['secret_nonce']??null,
                $existing['secret_tag']??null,
            ];
        }
        if($password!=='') $secret=self::encrypt($password,$keyMaterial);

        $json=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if($id>0){
            $stmt=$pdo->prepare('UPDATE data_sources SET name=?,driver=?,config_json=?,secret_ciphertext=?,secret_nonce=?,secret_tag=?,is_enabled=?,last_test_status=\'unknown\',last_test_message=NULL,last_test_at=NULL WHERE id=?');
            $stmt->execute([$name,$driver,$json,$secret[0],$secret[1],$secret[2],$enabled?1:0,$id]);
            if($stmt->rowCount()===0 && !self::find($pdo,$id)) throw new RuntimeException('Die Datenquelle wurde nicht gefunden.');
            return $id;
        }
        $stmt=$pdo->prepare('INSERT INTO data_sources(name,driver,config_json,secret_ciphertext,secret_nonce,secret_tag,is_enabled) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$name,$driver,$json,$secret[0],$secret[1],$secret[2],$enabled?1:0]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(PDO $pdo, int $id): void
    {
        self::ensureSchema($pdo);
        $bindings=self::bindingCount($pdo,$id);
        if ($bindings>0) {
            throw new RuntimeException(
                'Die Datenquelle ist noch mit '.$bindings.' DataForm'.($bindings===1?'':'s').' verbunden. Löschen Sie zuerst die zugehörigen DataForms.'
            );
        }
        $stmt=$pdo->prepare('DELETE FROM data_sources WHERE id=?');
        $stmt->execute([$id]);
        if($stmt->rowCount()!==1) throw new RuntimeException('Die Datenquelle wurde nicht gefunden.');
    }

    public static function test(PDO $pdo, int $id, string $keyMaterial): array
    {
        $row=self::find($pdo,$id);
        if(!$row) throw new RuntimeException('Die Datenquelle wurde nicht gefunden.');
        try{
            $config=self::runtimeConfig($row,$keyMaterial);
            $db=DatabaseFactory::create($config+['auto_connect'=>true]);
            if(!$db->isConnected()) throw new RuntimeException('Der Adapter meldet keine aktive Verbindung.');
            $db->disconnect();
            $status='pass';
            $message='Verbindung erfolgreich.';
        }catch(Throwable $e){
            $status='fail';
            $message=mb_substr($e->getMessage(),0,1000);
        }
        $stmt=$pdo->prepare('UPDATE data_sources SET last_test_status=?,last_test_message=?,last_test_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$status,$message,$id]);
        return ['status'=>$status,'message'=>$message];
    }

    public static function runtimeConfig(array $row, string $keyMaterial): array
    {
        $driver=(string)$row['driver'];
        $config=self::config($row);
        $config['driver']=$driver;
        if(in_array($driver,['mysql','pgsql','oracle','mssql'],true)){
            $config['password']=self::decryptRow($row,$keyMaterial);
            if(in_array($driver,['pgsql','oracle','mssql'],true) && $config['password']==='' && in_array((string)($row['name']??''),['Projekt-PostgreSQL-Datenspeicher','Projekt-Oracle-XE-Datenspeicher','Projekt-MSSQL-Datenspeicher'],true) && function_exists('enterprise_env')){
                $env=enterprise_env();
                $config['password']=(string)($env['PROJECT_DB_PASSWORD']??'');
            }
        }
        return $config;
    }

    public static function secretConfigured(array $row): bool
    {
        return trim((string)($row['secret_ciphertext']??''))!=='';
    }

    public static function keyAvailable(string $keyMaterial): bool
    {
        return trim($keyMaterial)!=='';
    }

    private static function bindingCount(PDO $pdo,int $sourceId): int
    {
        if ($sourceId<1) {
            return 0;
        }
        $exists=$pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dataform_table_bindings'"
        );
        $exists->execute();
        if ((int)$exists->fetchColumn()===0) {
            return 0;
        }
        $stmt=$pdo->prepare(
            "SELECT COUNT(*) FROM dataform_table_bindings WHERE source_kind='external' AND source_id=?"
        );
        $stmt->execute([$sourceId]);
        return (int)$stmt->fetchColumn();
    }

    private static function encrypt(string $plain,string $keyMaterial): array
    {
        if(trim($keyMaterial)===''){
            throw new RuntimeException('APP_KEY oder DATAFORM_APP_KEY fehlt; Kennwörter können nicht sicher gespeichert werden.');
        }
        if(!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL fehlt; Kennwörter können nicht verschlüsselt werden.');
        $key=hash('sha256',$keyMaterial,true);
        $nonce=random_bytes(12);
        $tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'easyIT-DataForm-Source');
        if($cipher===false) throw new RuntimeException('Das Datenquellen-Kennwort konnte nicht verschlüsselt werden.');
        return [base64_encode($cipher),base64_encode($nonce),base64_encode($tag)];
    }

    private static function decryptRow(array $row,string $keyMaterial): string
    {
        if(!self::secretConfigured($row)) return '';
        if(trim($keyMaterial)==='') throw new RuntimeException('APP_KEY oder DATAFORM_APP_KEY fehlt; das gespeicherte Kennwort kann nicht entschlüsselt werden.');
        if(!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL fehlt; das gespeicherte Kennwort kann nicht entschlüsselt werden.');
        $cipher=base64_decode((string)$row['secret_ciphertext'],true);
        $nonce=base64_decode((string)$row['secret_nonce'],true);
        $tag=base64_decode((string)$row['secret_tag'],true);
        if($cipher===false||$nonce===false||$tag===false) throw new RuntimeException('Das gespeicherte Datenquellen-Secret ist beschädigt.');
        $plain=openssl_decrypt($cipher,'aes-256-gcm',hash('sha256',$keyMaterial,true),OPENSSL_RAW_DATA,$nonce,$tag,'easyIT-DataForm-Source');
        if($plain===false) throw new RuntimeException('Das gespeicherte Datenquellen-Kennwort konnte nicht entschlüsselt werden.');
        return $plain;
    }
}
