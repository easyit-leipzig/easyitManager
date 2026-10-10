<?php
declare(strict_types=1);

/**
 * easyIT Enterprise / DataForm5
 * STAND 4 – Microsoft SQL Server adapter
 *
 * Logical storage type: mssql
 * PDO driver:            sqlsrv (PDO_SQLSRV)
 *
 * The adapter deliberately keeps SQL Server specifics in one place.
 */
final class EnterpriseMssqlAdapter
{
    public const STORAGE_TYPE = 'mssql';
    public const PDO_DRIVER = 'sqlsrv';
    public const DEFAULT_SCHEMA = 'dbo';
    public const DEFAULT_PORT = 1433;

    public static function available(): bool
    {
        return in_array(self::PDO_DRIVER, PDO::getAvailableDrivers(), true);
    }

    public static function normalizeConfig(array $config): array
    {
        $host = trim((string)($config['host'] ?? '127.0.0.1'));
        if ($host === '') {
            $host = '127.0.0.1';
        }

        $instance = trim((string)($config['instance'] ?? ''));
        $port = isset($config['port']) && $config['port'] !== ''
            ? (int)$config['port']
            : self::DEFAULT_PORT;

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('MSSQL-Port muss zwischen 1 und 65535 liegen.');
        }

        if ($instance !== '' && isset($config['port']) && $config['port'] !== '') {
            throw new InvalidArgumentException('MSSQL: instance und port dürfen nicht gleichzeitig gesetzt werden.');
        }

        $loginTimeout = isset($config['login_timeout']) ? (int)$config['login_timeout'] : 15;
        if ($loginTimeout < 1 || $loginTimeout > 300) {
            throw new InvalidArgumentException('MSSQL login_timeout muss zwischen 1 und 300 Sekunden liegen.');
        }

        return [
            'type' => self::STORAGE_TYPE,
            'driver' => self::PDO_DRIVER,
            'host' => $host,
            'instance' => $instance,
            'port' => $port,
            'database' => trim((string)($config['database'] ?? '')),
            'username' => (string)($config['username'] ?? $config['user'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
            'schema' => trim((string)($config['schema'] ?? self::DEFAULT_SCHEMA)) ?: self::DEFAULT_SCHEMA,
            'encrypt' => self::boolValue($config['encrypt'] ?? true),
            'trust_server_certificate' => self::boolValue($config['trust_server_certificate'] ?? false),
            'login_timeout' => $loginTimeout,
        ];
    }

    public static function buildDsn(array $config, bool $omitDatabase = false): string
    {
        $c = self::normalizeConfig($config);

        $server = $c['host'];
        if ($c['instance'] !== '') {
            $server .= '\\' . $c['instance'];
        } else {
            $server .= ',' . $c['port'];
        }

        $parts = [
            'Server=' . $server,
        ];

        if (!$omitDatabase && $c['database'] !== '') {
            $parts[] = 'Database=' . $c['database'];
        }

        $parts[] = 'Encrypt=' . ($c['encrypt'] ? 'true' : 'false');
        $parts[] = 'TrustServerCertificate=' . ($c['trust_server_certificate'] ? 'true' : 'false');
        $parts[] = 'LoginTimeout=' . $c['login_timeout'];

        // UID/PWD intentionally do NOT belong in PDO_SQLSRV DSNs.
        return 'sqlsrv:' . implode(';', $parts);
    }

    public static function connect(array $config, bool $omitDatabase = false): PDO
    {
        if (!self::available()) {
            throw new RuntimeException(
                'PDO_SQLSRV ist nicht geladen. Erwarteter PDO-Treiber: sqlsrv.'
            );
        }

        $c = self::normalizeConfig($config);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::SQLSRV_ATTR_ENCODING') && defined('PDO::SQLSRV_ENCODING_UTF8')) {
            $options[constant('PDO::SQLSRV_ATTR_ENCODING')] = constant('PDO::SQLSRV_ENCODING_UTF8');
        }

        return new PDO(
            self::buildDsn($c, $omitDatabase),
            $c['username'],
            $c['password'],
            $options
        );
    }

    public static function serverVersion(PDO $pdo): string
    {
        $value = $pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128))")->fetchColumn();
        return (string)$value;
    }

    public static function databaseName(PDO $pdo): string
    {
        return (string)$pdo->query('SELECT DB_NAME()')->fetchColumn();
    }

    public static function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new InvalidArgumentException('Ungültiger MSSQL-Bezeichner.');
        }

        $parts = explode('.', $identifier);
        $quoted = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException('Ungültiger qualifizierter MSSQL-Bezeichner.');
            }
            $quoted[] = '[' . str_replace(']', ']]', $part) . ']';
        }

        return implode('.', $quoted);
    }

    public static function qualifiedTable(string $table, string $schema = self::DEFAULT_SCHEMA): string
    {
        if (str_contains($table, '.')) {
            return self::quoteIdentifier($table);
        }
        if ($schema === '') {
            return self::quoteIdentifier($table);
        }
        return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
    }

    public static function listTables(PDO $pdo, string $schema = self::DEFAULT_SCHEMA): array
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_TYPE='BASE TABLE'
                AND TABLE_SCHEMA=?
              ORDER BY TABLE_NAME"
        );
        $stmt->execute([$schema]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function listColumns(PDO $pdo, string $table, string $schema = self::DEFAULT_SCHEMA): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE,
                    COLUMN_DEFAULT, ORDINAL_POSITION
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
              ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$schema, $table]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function primaryKeyColumns(PDO $pdo, string $table, string $schema = self::DEFAULT_SCHEMA): array
    {
        $stmt = $pdo->prepare(
            "SELECT c.name
               FROM sys.key_constraints kc
               JOIN sys.tables t ON t.object_id=kc.parent_object_id
               JOIN sys.schemas s ON s.schema_id=t.schema_id
               JOIN sys.index_columns ic
                 ON ic.object_id=t.object_id AND ic.index_id=kc.unique_index_id
               JOIN sys.columns c
                 ON c.object_id=t.object_id AND c.column_id=ic.column_id
              WHERE kc.type='PK' AND s.name=? AND t.name=?
              ORDER BY ic.key_ordinal"
        );
        $stmt->execute([$schema, $table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function databaseExists(PDO $masterPdo, string $database): bool
    {
        $stmt = $masterPdo->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');
        $stmt->execute([$database]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function createDatabase(PDO $masterPdo, string $database): void
    {
        if (self::databaseExists($masterPdo, $database)) {
            return;
        }
        $masterPdo->exec('CREATE DATABASE ' . self::quoteIdentifier($database));
    }

    public static function dropDatabase(PDO $masterPdo, string $database, bool $force = false): void
    {
        if (!self::databaseExists($masterPdo, $database)) {
            return;
        }

        $q = self::quoteIdentifier($database);
        if ($force) {
            $masterPdo->exec('ALTER DATABASE ' . $q . ' SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
        }
        $masterPdo->exec('DROP DATABASE ' . $q);
    }

    public static function find(
        PDO $pdo,
        string $table,
        array $where = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0,
        string $schema = self::DEFAULT_SCHEMA
    ): array {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('limit muss positiv sein.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset darf nicht negativ sein.');
        }

        [$whereSql, $params] = self::whereClause($where);
        $sql = 'SELECT * FROM ' . self::qualifiedTable($table, $schema) . $whereSql;
        $sql .= self::orderClause($orderBy, $limit !== null || $offset > 0);

        if ($limit !== null) {
            $sql .= ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
        } elseif ($offset > 0) {
            $sql .= ' OFFSET ' . $offset . ' ROWS';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function count(PDO $pdo, string $table, array $where = [], string $schema = self::DEFAULT_SCHEMA): int
    {
        [$whereSql, $params] = self::whereClause($where);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::qualifiedTable($table, $schema) . $whereSql
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function insert(
        PDO $pdo,
        string $table,
        array $data,
        ?string $identityColumn = 'id',
        string $schema = self::DEFAULT_SCHEMA
    ): int|string|null {
        if ($data === []) {
            throw new InvalidArgumentException('INSERT benötigt mindestens ein Feld.');
        }

        $columns = array_keys($data);
        $quotedColumns = array_map([self::class, 'quoteIdentifier'], $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));

        $sql = 'INSERT INTO ' . self::qualifiedTable($table, $schema)
             . ' (' . implode(',', $quotedColumns) . ')';

        if ($identityColumn !== null && $identityColumn !== '') {
            $sql .= ' OUTPUT INSERTED.' . self::quoteIdentifier($identityColumn);
        }

        $sql .= ' VALUES (' . $placeholders . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));

        if ($identityColumn === null || $identityColumn === '') {
            return null;
        }

        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return is_numeric($value) && (string)(int)$value === (string)$value ? (int)$value : (string)$value;
    }

    public static function update(
        PDO $pdo,
        string $table,
        array $data,
        array $where,
        string $schema = self::DEFAULT_SCHEMA
    ): int {
        if ($data === []) {
            return 0;
        }
        if ($where === []) {
            throw new InvalidArgumentException('UPDATE ohne WHERE ist nicht erlaubt.');
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = self::quoteIdentifier((string)$column) . '=?';
        }

        [$whereSql, $whereParams] = self::whereClause($where);
        $sql = 'UPDATE ' . self::qualifiedTable($table, $schema)
             . ' SET ' . implode(',', $set)
             . $whereSql;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(
        PDO $pdo,
        string $table,
        array $where,
        string $schema = self::DEFAULT_SCHEMA
    ): int {
        if ($where === []) {
            throw new InvalidArgumentException('DELETE ohne WHERE ist nicht erlaubt.');
        }

        [$whereSql, $params] = self::whereClause($where);
        $stmt = $pdo->prepare(
            'DELETE FROM ' . self::qualifiedTable($table, $schema) . $whereSql
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function genericType(string $type, ?int $length = null): string
    {
        $t = strtolower(trim($type));
        return match ($t) {
            'bool', 'boolean' => 'BIT',
            'tinyint' => 'TINYINT',
            'smallint' => 'SMALLINT',
            'int', 'integer' => 'INT',
            'bigint' => 'BIGINT',
            'decimal', 'numeric' => 'DECIMAL(38,10)',
            'float', 'double', 'real' => 'FLOAT',
            'date' => 'DATE',
            'time' => 'TIME(6)',
            'datetime', 'timestamp' => 'DATETIME2(6)',
            'uuid', 'guid' => 'UNIQUEIDENTIFIER',
            'binary', 'blob', 'varbinary' => 'VARBINARY(MAX)',
            'json', 'text', 'longtext', 'clob' => 'NVARCHAR(MAX)',
            'char' => 'NCHAR(' . self::safeLength($length, 255) . ')',
            'varchar', 'string' => 'NVARCHAR(' . self::safeLength($length, 4000) . ')',
            default => throw new InvalidArgumentException('Unbekannter generischer MSSQL-Typ: ' . $type),
        };
    }

    public static function sqlLimitOffset(string $sql, int $limit, int $offset = 0, string $orderBy = '(SELECT 0)'): string
    {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException('Ungültige MSSQL-Paginierungswerte.');
        }
        if (trim($orderBy) === '') {
            $orderBy = '(SELECT 0)';
        }

        return rtrim($sql, " \t\n\r\0\x0B;")
            . ' ORDER BY ' . $orderBy
            . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    private static function whereClause(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            $q = self::quoteIdentifier((string)$column);
            if ($value === null) {
                $parts[] = $q . ' IS NULL';
            } else {
                $parts[] = $q . '=?';
                $params[] = $value;
            }
        }
        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    private static function orderClause(array $orderBy, bool $required): string
    {
        if ($orderBy === []) {
            return $required ? ' ORDER BY (SELECT 0)' : '';
        }

        $parts = [];
        foreach ($orderBy as $column => $direction) {
            if (is_int($column)) {
                $column = (string)$direction;
                $direction = 'ASC';
            }
            $dir = strtoupper((string)$direction);
            if (!in_array($dir, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('ORDER BY erlaubt nur ASC oder DESC.');
            }
            $parts[] = self::quoteIdentifier((string)$column) . ' ' . $dir;
        }
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $v = strtolower(trim((string)$value));
        return in_array($v, ['1', 'true', 'yes', 'on', 'ja'], true);
    }

    private static function safeLength(?int $length, int $default): int
    {
        $value = $length ?? $default;
        if ($value < 1 || $value > 4000) {
            throw new InvalidArgumentException('NVARCHAR/NCHAR-Länge muss zwischen 1 und 4000 liegen.');
        }
        return $value;
    }
}
