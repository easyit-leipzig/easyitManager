<?php
declare(strict_types=1);

require_once __DIR__ . '/EnterprisePgsqlPdo.php';

function enterprise_dbuser_normalize_driver(string $driver): string
{
    $driver=strtolower(trim($driver));
    if (in_array($driver,['mariadb'],true)) return 'mysql';
    if (in_array($driver,['postgres','postgresql'],true)) return 'pgsql';
    return $driver;
}

function enterprise_dbuser_sources(array $env): array
{
    $sources=[];
    foreach ([
        'admin'=>['label'=>'Administrationsspeicher','prefix'=>'ADMIN_DB_'],
        'project'=>['label'=>'Projektspeicher','prefix'=>'PROJECT_DB_'],
    ] as $key=>$meta) {
        $p=$meta['prefix'];
        $driver=enterprise_dbuser_normalize_driver((string)($env[$p.'DRIVER']??''));
        if (!in_array($driver,['mysql','pgsql'],true)) continue;
        $pdoDriver=$driver==='pgsql'?'pgsql':'mysql';
        $available=class_exists(PDO::class) && in_array($pdoDriver,PDO::getAvailableDrivers(),true);
        $sources[$key]=[
            'key'=>$key,
            'label'=>$meta['label'],
            'prefix'=>$p,
            'driver'=>$driver,
            'available'=>$available,
            'host'=>trim((string)($env[$p.'HOST']??'127.0.0.1')),
            'port'=>(int)($env[$p.'PORT']??($driver==='pgsql'?5432:3306)),
            'database'=>trim((string)($env[$p.'DATABASE']??'')),
            'schema'=>trim((string)($env[$p.'SCHEMA']??'public')) ?: 'public',
            'maintenance_database'=>trim((string)($env[$p.'MAINTENANCE_DATABASE']??'postgres')) ?: 'postgres',
            'username'=>trim((string)($env[$p.'USERNAME']??'')),
            'password'=>(string)($env[$p.'PASSWORD']??''),
        ];
    }
    return $sources;
}

function enterprise_dbuser_source(array $env,string $key): array
{
    $sources=enterprise_dbuser_sources($env);
    if (!isset($sources[$key])) throw new RuntimeException('Die gewählte Datenbankquelle ist nicht für die Benutzerverwaltung verfügbar.');
    $source=$sources[$key];
    if (empty($source['available'])) throw new RuntimeException('Der benötigte PDO-Treiber für '.($source['driver']==='pgsql'?'PostgreSQL':'MySQL').' ist nicht verfügbar.');
    foreach (['host','port','username'] as $field) if ((string)($source[$field]??'')==='') throw new RuntimeException('Die Verbindungsdaten der gewählten Datenbankquelle sind unvollständig.');
    return $source;
}

function enterprise_dbuser_server_pdo(array $source): PDO
{
    if ($source['driver']==='pgsql') {
        return new EnterprisePgsqlPdo((string)$source['host'],(int)$source['port'],(string)$source['maintenance_database'],(string)$source['username'],(string)$source['password'],'public');
    }
    return new PDO(
        'mysql:host='.$source['host'].';port='.(int)$source['port'].';charset=utf8mb4',
        (string)$source['username'],(string)$source['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function enterprise_dbuser_target_pdo(array $source,string $database,?string $schema=null): PDO
{
    enterprise_dbuser_validate_database($database);
    if ($source['driver']==='pgsql') {
        $schema=trim((string)$schema) ?: 'public';
        enterprise_dbuser_validate_schema($schema);
        return new EnterprisePgsqlPdo((string)$source['host'],(int)$source['port'],$database,(string)$source['username'],(string)$source['password'],$schema);
    }
    return new PDO(
        'mysql:host='.$source['host'].';port='.(int)$source['port'].';dbname='.$database.';charset=utf8mb4',
        (string)$source['username'],(string)$source['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function enterprise_dbuser_validate_database(string $name): void
{
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$name)!==1) throw new RuntimeException('Ungültiger Datenbankname.');
}
function enterprise_dbuser_validate_schema(string $name): void
{
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$name)!==1) throw new RuntimeException('Ungültiger Schemaname.');
}
function enterprise_dbuser_validate_username(string $name,string $driver): void
{
    $max=$driver==='mysql'?32:63;
    if ($name==='' || strlen($name)>$max || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$name)!==1) {
        throw new RuntimeException('Ungültiger Datenbank-Benutzername. Er muss mit einem Buchstaben beginnen und darf nur Buchstaben, Zahlen und Unterstriche enthalten.');
    }
}
function enterprise_dbuser_validate_mysql_host(string $host): void
{
    if ($host==='' || strlen($host)>255 || preg_match('/^[A-Za-z0-9._:%-]+$/',$host)!==1) throw new RuntimeException('Ungültiger MySQL-Host für das Benutzerkonto.');
}
function enterprise_dbuser_quote_ident(string $name,string $driver): string
{
    if ($driver==='mysql') return '`'.str_replace('`','``',$name).'`';
    return '"'.str_replace('"','""',$name).'"';
}
function enterprise_dbuser_mysql_account(PDO $pdo,string $username,string $host): string
{
    return $pdo->quote($username).'@'.$pdo->quote($host);
}
function enterprise_dbuser_password_check(string $password): void
{
    if (strlen($password)<10) throw new RuntimeException('Das Datenbank-Kennwort muss mindestens 10 Zeichen lang sein.');
}
function enterprise_dbuser_valid_access(string $access): string
{
    if (!in_array($access,['none','read','readwrite','full'],true)) throw new RuntimeException('Ungültige Zugriffsstufe.');
    return $access;
}
function enterprise_dbuser_is_protected(array $source,string $username,?string $host=null): bool
{
    $name=strtolower($username);
    if ($name===strtolower((string)$source['username'])) return true;
    if ($source['driver']==='pgsql') return $name==='postgres' || str_starts_with($name,'pg_');
    return in_array($name,['root','mysql.sys','mysql.session','mysql.infoschema','mariadb.sys'],true);
}

function enterprise_dbuser_databases(array $source): array
{
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        return array_values(array_filter(array_map('strval',$pdo->query("SELECT datname FROM pg_database WHERE datistemplate=false AND datallowconn=true ORDER BY datname")->fetchAll(PDO::FETCH_COLUMN)),fn($v)=>$v!==''));
    }
    $rows=$pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    $system=['information_schema','mysql','performance_schema','sys'];
    return array_values(array_filter(array_map('strval',$rows),fn($v)=>$v!==''&&!in_array(strtolower($v),$system,true)));
}

function enterprise_dbuser_schemas(array $source,string $database): array
{
    if ($source['driver']!=='pgsql') return [];
    $pdo=enterprise_dbuser_target_pdo($source,$database,'public');
    return array_values(array_map('strval',$pdo->query("SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg_%' AND nspname<>'information_schema' ORDER BY nspname")->fetchAll(PDO::FETCH_COLUMN)));
}

function enterprise_dbuser_list(array $source): array
{
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        $sql="SELECT rolname AS username, rolcanlogin AS can_login, rolsuper AS is_super, rolcreatedb AS can_create_db, rolcreaterole AS can_create_role, rolvaliduntil AS valid_until FROM pg_roles ORDER BY rolname";
        return $pdo->query($sql)->fetchAll();
    }
    try {
        return $pdo->query("SELECT User AS username,Host AS host,IFNULL(account_locked,'N') AS account_locked,plugin FROM mysql.user ORDER BY User,Host")->fetchAll();
    } catch (Throwable) {
        return $pdo->query("SELECT User AS username,Host AS host,'N' AS account_locked,'' AS plugin FROM mysql.user ORDER BY User,Host")->fetchAll();
    }
}

function enterprise_dbuser_create(array $source,string $username,string $host,string $password,bool $login): void
{
    enterprise_dbuser_validate_username($username,(string)$source['driver']);
    enterprise_dbuser_password_check($password);
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        $ident=enterprise_dbuser_quote_ident($username,'pgsql');
        $exists=$pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname=?');$exists->execute([$username]);
        if ($exists->fetchColumn()!==false) throw new RuntimeException('Der PostgreSQL-Benutzer existiert bereits.');
        $pdo->exec('CREATE ROLE '.$ident.' '.($login?'LOGIN':'NOLOGIN').' PASSWORD '.$pdo->quote($password).' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION');
        return;
    }
    enterprise_dbuser_validate_mysql_host($host);
    $account=enterprise_dbuser_mysql_account($pdo,$username,$host);
    $exists=$pdo->prepare('SELECT 1 FROM mysql.user WHERE User=? AND Host=?');$exists->execute([$username,$host]);
    if ($exists->fetchColumn()!==false) throw new RuntimeException('Der MySQL-Benutzer existiert bereits.');
    $pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($password));
    if (!$login) $pdo->exec('ALTER USER '.$account.' ACCOUNT LOCK');
}

function enterprise_dbuser_set_password(array $source,string $username,string $host,string $password): void
{
    enterprise_dbuser_password_check($password);
    if (enterprise_dbuser_is_protected($source,$username,$host)) throw new RuntimeException('Das aktuell verwendete bzw. ein geschütztes Systemkonto kann hier nicht geändert werden.');
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        enterprise_dbuser_validate_username($username,'pgsql');
        $pdo->exec('ALTER ROLE '.enterprise_dbuser_quote_ident($username,'pgsql').' PASSWORD '.$pdo->quote($password));
    } else {
        enterprise_dbuser_validate_mysql_host($host);
        $pdo->exec('ALTER USER '.enterprise_dbuser_mysql_account($pdo,$username,$host).' IDENTIFIED BY '.$pdo->quote($password));
    }
}

function enterprise_dbuser_set_login(array $source,string $username,string $host,bool $enabled): void
{
    if (enterprise_dbuser_is_protected($source,$username,$host)) throw new RuntimeException('Das aktuell verwendete bzw. ein geschütztes Systemkonto kann hier nicht gesperrt werden.');
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        enterprise_dbuser_validate_username($username,'pgsql');
        $pdo->exec('ALTER ROLE '.enterprise_dbuser_quote_ident($username,'pgsql').' '.($enabled?'LOGIN':'NOLOGIN'));
    } else {
        enterprise_dbuser_validate_mysql_host($host);
        $pdo->exec('ALTER USER '.enterprise_dbuser_mysql_account($pdo,$username,$host).' ACCOUNT '.($enabled?'UNLOCK':'LOCK'));
    }
}

function enterprise_dbuser_set_access(array $source,string $username,string $host,string $database,string $schema,string $access): void
{
    enterprise_dbuser_validate_username($username,(string)$source['driver']);
    enterprise_dbuser_validate_database($database);
    $access=enterprise_dbuser_valid_access($access);
    $server=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='mysql') {
        enterprise_dbuser_validate_mysql_host($host);
        $account=enterprise_dbuser_mysql_account($server,$username,$host);
        $db=enterprise_dbuser_quote_ident($database,'mysql').'.*';
        try { $server->exec('REVOKE ALL PRIVILEGES, GRANT OPTION ON '.$db.' FROM '.$account); } catch (PDOException $e) {
            $msg=strtolower($e->getMessage()); if (!str_contains($msg,'no such grant') && !str_contains($msg,'1141')) throw $e;
        }
        $privs=match($access){
            'read'=>'SELECT, SHOW VIEW',
            'readwrite'=>'SELECT, INSERT, UPDATE, DELETE, EXECUTE, SHOW VIEW',
            'full'=>'ALL PRIVILEGES',
            default=>'',
        };
        if ($privs!=='') $server->exec('GRANT '.$privs.' ON '.$db.' TO '.$account);
        return;
    }
    enterprise_dbuser_validate_schema($schema);
    $role=enterprise_dbuser_quote_ident($username,'pgsql');
    $db=enterprise_dbuser_quote_ident($database,'pgsql');
    $sch=enterprise_dbuser_quote_ident($schema,'pgsql');
    $target=enterprise_dbuser_target_pdo($source,$database,$schema);
    // Nur Rechte auf dem gewählten Ziel werden neu gesetzt; andere Datenbanken bleiben unberührt.
    foreach([
        'REVOKE ALL PRIVILEGES ON DATABASE '.$db.' FROM '.$role,
        'REVOKE ALL PRIVILEGES ON SCHEMA '.$sch.' FROM '.$role,
        'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA '.$sch.' FROM '.$role,
        'REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA '.$sch.' FROM '.$role,
        'REVOKE ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA '.$sch.' FROM '.$role,
        'ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' REVOKE ALL PRIVILEGES ON TABLES FROM '.$role,
        'ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' REVOKE ALL PRIVILEGES ON SEQUENCES FROM '.$role,
        'ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' REVOKE ALL PRIVILEGES ON FUNCTIONS FROM '.$role,
    ] as $sql) { try{$target->exec($sql);}catch(Throwable){} }
    if ($access==='none') return;
    $target->exec('GRANT CONNECT ON DATABASE '.$db.' TO '.$role);
    $target->exec('GRANT USAGE ON SCHEMA '.$sch.' TO '.$role);
    if ($access==='read') {
        $target->exec('GRANT SELECT ON ALL TABLES IN SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA '.$sch.' TO '.$role);
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT SELECT ON TABLES TO '.$role);}catch(Throwable){}
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT USAGE, SELECT ON SEQUENCES TO '.$role);}catch(Throwable){}
    } elseif ($access==='readwrite') {
        $target->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA '.$sch.' TO '.$role);
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$role);}catch(Throwable){}
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.$role);}catch(Throwable){}
    } else {
        $target->exec('GRANT CREATE ON DATABASE '.$db.' TO '.$role);
        $target->exec('GRANT ALL PRIVILEGES ON SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA '.$sch.' TO '.$role);
        $target->exec('GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA '.$sch.' TO '.$role);
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT ALL PRIVILEGES ON TABLES TO '.$role);}catch(Throwable){}
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT ALL PRIVILEGES ON SEQUENCES TO '.$role);}catch(Throwable){}
        try{$target->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$sch.' GRANT ALL PRIVILEGES ON FUNCTIONS TO '.$role);}catch(Throwable){}
    }
}

function enterprise_dbuser_drop(array $source,string $username,string $host): void
{
    if (enterprise_dbuser_is_protected($source,$username,$host)) throw new RuntimeException('Das aktuell verwendete bzw. ein geschütztes Systemkonto kann nicht gelöscht werden.');
    $pdo=enterprise_dbuser_server_pdo($source);
    if ($source['driver']==='pgsql') {
        enterprise_dbuser_validate_username($username,'pgsql');
        $pdo->exec('DROP ROLE '.enterprise_dbuser_quote_ident($username,'pgsql'));
    } else {
        enterprise_dbuser_validate_mysql_host($host);
        $pdo->exec('DROP USER '.enterprise_dbuser_mysql_account($pdo,$username,$host));
    }
}
