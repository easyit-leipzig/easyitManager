<?php
declare(strict_types=1);

$base = __DIR__;
$configFile = $base . '/config/app.php';
if (!is_file($configFile)) {
    $configFile = $base . '/config/app.example.php';
}
$config = require $configFile;

// HF110: A setup-generated database.local.php overrides packaged defaults.
// Environment variables keep highest priority for managed deployments.
$databaseLocalFile=$base.'/config/database.local.php';
if(is_file($databaseLocalFile)){
    $databaseLocal=require $databaseLocalFile;
    if(is_array($databaseLocal))$config['database']=array_merge((array)($config['database']??[]),$databaseLocal);
}
$envDsn=getenv('EASYIT_LICENSE_DSN');
if(is_string($envDsn)&&trim($envDsn)!==''){
    $config['database']['dsn']=trim($envDsn);
    $envUser=getenv('EASYIT_LICENSE_DB_USER');if(is_string($envUser))$config['database']['user']=$envUser;
    $envPassword=getenv('EASYIT_LICENSE_DB_PASSWORD');if(is_string($envPassword))$config['database']['password']=$envPassword;
}
$config['database']['configured']=trim((string)($config['database']['dsn']??''))!=='';

if(!function_exists('easyit_license_database_configured')){
    function easyit_license_database_configured(array $config): bool { return (bool)($config['database']['configured']??false); }
}

spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'EasyIT\\LicenseServer\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = substr($class, strlen($prefix));
    $file = $base . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) require $file;
});

return $config;
