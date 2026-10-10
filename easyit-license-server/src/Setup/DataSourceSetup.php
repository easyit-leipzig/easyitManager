<?php
declare(strict_types=1);

namespace EasyIT\LicenseServer\Setup;

use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Security\ServerSigner;
use PDO;
use RuntimeException;

final class DataSourceSetup
{
    public static function availableDrivers(): array
    {
        $available=array_fill_keys(PDO::getAvailableDrivers(),true);
        $supported=[];
        foreach([
            'mysql'=>['label'=>'MySQL / MariaDB','default_port'=>3306],
            'pgsql'=>['label'=>'PostgreSQL','default_port'=>5432],
            'sqlite'=>['label'=>'SQLite','default_port'=>null],
        ] as $code=>$meta){
            if(isset($available[$code]))$supported[$code]=$meta;
        }
        return $supported;
    }

    public static function defaults(string $basePath): array
    {
        return [
            'driver'=>'mysql',
            'host'=>'127.0.0.1',
            'port'=>'3306',
            'database'=>'easyit_license',
            'schema'=>'public',
            'user'=>'root',
            'password'=>'',
            'sqlite_path'=>$basePath.'/storage/database/easyit_license.sqlite',
            'auto_create_database'=>true,
            'auto_create_schema'=>true,
        ];
    }

    public static function fromInput(array $input,string $basePath): array
    {
        $driver=strtolower(trim((string)($input['driver']??'')));
        $available=self::availableDrivers();
        if(!isset($available[$driver]))throw new RuntimeException('DATASOURCE_DRIVER_NOT_AVAILABLE');

        // HF115: Setup-Vertrag ist verbindlich. Fehlende SQL-Datenbank bzw.
        // PostgreSQL-Schema werden immer angelegt; fehlen die Rechte, bricht
        // das Setup mit einer klaren Berechtigungsmeldung ab.
        $autoDb=true;
        $autoSchema=true;

        if($driver==='sqlite'){
            $raw=trim((string)($input['sqlite_path']??''));
            if($raw==='')throw new RuntimeException('DATASOURCE_SQLITE_PATH_REQUIRED');
            if(str_contains($raw,"\0"))throw new RuntimeException('DATASOURCE_SQLITE_PATH_INVALID');
            $path=self::absolutePath($raw,$basePath);
            return [
                'driver'=>'sqlite',
                'dsn'=>'sqlite:'.$path,
                'user'=>'',
                'password'=>'',
                'path'=>$path,
                'schema'=>'',
                'auto_create_database'=>true,
                'auto_create_schema'=>false,
            ];
        }

        $host=trim((string)($input['host']??''));
        if($host==='')throw new RuntimeException('DATASOURCE_HOST_REQUIRED');
        $port=(int)($input['port']??0);
        if($port<1||$port>65535)throw new RuntimeException('DATASOURCE_PORT_INVALID');
        $database=trim((string)($input['database']??''));
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_$-]{0,62}$/',$database))throw new RuntimeException('DATASOURCE_DATABASE_INVALID');
        $user=trim((string)($input['user']??''));
        if($user==='')throw new RuntimeException('DATASOURCE_USER_REQUIRED');
        $password=(string)($input['password']??'');

        if($driver==='mysql'){
            return [
                'driver'=>'mysql',
                'dsn'=>'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4',
                'host'=>$host,'port'=>$port,'database'=>$database,'schema'=>'',
                'user'=>$user,'password'=>$password,
                'auto_create_database'=>$autoDb,
                'auto_create_schema'=>false,
            ];
        }

        $schema=trim((string)($input['schema']??'public'));
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/',$schema))throw new RuntimeException('DATASOURCE_SCHEMA_INVALID');
        return [
            'driver'=>'pgsql',
            'dsn'=>'pgsql:host='.$host.';port='.$port.';dbname='.$database,
            'host'=>$host,'port'=>$port,'database'=>$database,'schema'=>$schema,
            'user'=>$user,'password'=>$password,
            'auto_create_database'=>$autoDb,
            'auto_create_schema'=>$autoSchema,
        ];
    }

    public static function test(array $db): array
    {
        $probe=Connection::probeSetup($db);
        return [
            'driver'=>(string)($probe['driver']??''),
            'server_version'=>(string)($probe['server_version']??''),
            'target_exists'=>(bool)($probe['target_exists']??false),
            'summary'=>self::summary($db),
        ];
    }

    public static function install(array $db,array $baseConfig,string $basePath,bool $rebuild=true): array
    {
        if(!extension_loaded('sodium'))throw new RuntimeException('SODIUM_EXTENSION_REQUIRED');
        // Im Setup gelten die HF115-Neuaufbau-Regeln verbindlich: Eine fehlende
        // Datenbank wird erzeugt; eine vorhandene Datenbank / das konfigurierte
        // PostgreSQL-Schema wird von allen Benutzertabellen geleert und danach
        // vollständig neu provisioniert. Da easyit_license die gemeinsame zentrale Administrationsdatenbank ist, umfasst dies Lizenzserver- und Enterprise-Control-Plane.
        $db['auto_create_database']=true;
        if(($db['driver']??'')==='pgsql')$db['auto_create_schema']=true;
        $pdo=Connection::create($db);
        $runner=new MigrationRunner($pdo);
        $schema=$rebuild?$runner->rebuild():$runner->migrate();

        // Erst NACH vollständigem Schema-Gate werden Konfiguration, Schlüssel und
        // Setup-Sperre finalisiert. Ein partieller Tabellenbestand bleibt reparierbar.
        $config=$baseConfig;
        $config['database']=array_merge((array)($config['database']??[]),$db,['configured'=>true]);
        $security=(array)($config['security']??[]);
        new ServerSigner(
            (string)($security['server_key_id']??'SERVER-2026-01'),
            (string)($security['server_private_key_file']??($basePath.'/secure/keys/server-private.key')),
            (string)($security['server_public_key_file']??($basePath.'/secure/keys/server-public.key')),
            (string)($security['server_keyring_file']??($basePath.'/secure/keys/server-keyring.json'))
        );

        self::persist($db,$basePath);
        self::writeLock($db,$basePath,$schema);
        return ['database'=>self::summary($db),'schema'=>$schema];
    }

    /**
     * Repariert/komplettiert eine bereits konfigurierte lokale Installation.
     * Diese Funktion ist absichtlich idempotent und löscht keine Tabellen/Daten.
     */
    public static function repairExisting(array $baseConfig,string $basePath): array
    {
        if(!extension_loaded('sodium'))throw new RuntimeException('SODIUM_EXTENSION_REQUIRED');
        $db=(array)($baseConfig['database']??[]);
        if(trim((string)($db['dsn']??''))==='')throw new RuntimeException('DATASOURCE_NOT_CONFIGURED');
        $pdo=Connection::create($db);
        $schema=(new MigrationRunner($pdo))->migrate();
        self::writeLock($db,$basePath,$schema);
        return ['database'=>self::summary($db),'schema'=>$schema];
    }

    public static function persist(array $db,string $basePath): void
    {
        $dir=$basePath.'/config';
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('DATASOURCE_CONFIG_DIRECTORY_NOT_WRITABLE');
        if(!is_writable($dir))throw new RuntimeException('DATASOURCE_CONFIG_DIRECTORY_NOT_WRITABLE');
        $file=$dir.'/database.local.php';
        $tmp=$file.'.tmp.'.bin2hex(random_bytes(5));
        $payload="<?php\ndeclare(strict_types=1);\n\nreturn ".var_export($db,true).";\n";
        if(file_put_contents($tmp,$payload,LOCK_EX)===false)throw new RuntimeException('DATASOURCE_CONFIG_WRITE_FAILED');
        @chmod($tmp,0600);
        if(!@rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('DATASOURCE_CONFIG_WRITE_FAILED');}
        @chmod($file,0600);
    }

    public static function isLocked(string $basePath): bool
    {
        return is_file($basePath.'/secure/license-server-setup.lock');
    }

    public static function lockInfo(string $basePath): array
    {
        $file=$basePath.'/secure/license-server-setup.lock';
        if(!is_file($file))return [];
        $decoded=json_decode((string)file_get_contents($file),true);
        return is_array($decoded)?$decoded:[];
    }

    public static function summary(array $db): array
    {
        $driver=(string)($db['driver']??strtok((string)($db['dsn']??''),':'));
        if($driver==='sqlite')return ['driver'=>'sqlite','path'=>(string)($db['path']??substr((string)($db['dsn']??''),7))];
        $out=[
            'driver'=>$driver,
            'host'=>(string)($db['host']??''),
            'port'=>(int)($db['port']??0),
            'database'=>(string)($db['database']??''),
            'user'=>(string)($db['user']??''),
        ];
        if($driver==='pgsql')$out['schema']=(string)($db['schema']??'public');
        return $out;
    }

    private static function writeLock(array $db,string $basePath,array $schema=[]): void
    {
        $dir=$basePath.'/secure';
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('SETUP_LOCK_DIRECTORY_NOT_WRITABLE');
        $file=$dir.'/license-server-setup.lock';
        $payload=json_encode([
            'completed_at'=>time(),
            'database'=>self::summary($db),
            'schema'=>[
                'migration'=>(string)($schema['migration']??MigrationRunner::FULL_SCHEMA_MIGRATION),
                'required'=>(int)($schema['required']??count(MigrationRunner::REQUIRED_TABLES)),
                'present'=>(int)($schema['present']??0),
                'missing'=>array_values((array)($schema['missing']??[])),
            ],
        ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($payload===false||file_put_contents($file,$payload.PHP_EOL,LOCK_EX)===false)throw new RuntimeException('SETUP_LOCK_WRITE_FAILED');
        @chmod($file,0600);
    }

    private static function toBool(mixed $value): bool
    {
        return in_array($value,[1,'1',true,'true','on','yes'],true);
    }

    private static function absolutePath(string $path,string $basePath): string
    {
        $path=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$path);
        $isAbsolute=str_starts_with($path,DIRECTORY_SEPARATOR)||preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR,'/').'/',$path)===1;
        if(!$isAbsolute)$path=rtrim($basePath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path,DIRECTORY_SEPARATOR);
        $dir=dirname($path);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('DATASOURCE_SQLITE_DIRECTORY_NOT_WRITABLE');
        if(!is_writable($dir))throw new RuntimeException('DATASOURCE_SQLITE_DIRECTORY_NOT_WRITABLE');
        return $path;
    }
}
