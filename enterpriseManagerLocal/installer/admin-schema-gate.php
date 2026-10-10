<?php
declare(strict_types=1);

/**
 * HF112: Verbindliches Gate fuer das Enterprise-Administrationsschema.
 *
 * Der Installer darf Schritt 7 erst erreichen, wenn die Basis-Control-Plane
 * real vorhanden ist. Ein vorhandener Migrationseintrag allein gilt nicht als
 * Beweis, weil eine fruehere/abgebrochene Installation Tabellen verloren haben
 * kann. Die Basismigrationen 001-004 sind idempotent und koennen zur Reparatur
 * gezielt erneut ausgefuehrt werden.
 */

function easyit_admin_schema_required_tables(): array
{
    return [
        'migrations',
        'roles',
        'users',
        'user_roles',
        'projects',
        'audit_log',
        'enterprise_licenses',
    ];
}

function easyit_admin_schema_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('Ungueltiger Tabellenname im Administrationsschema-Gate.');
    }

    if (class_exists('EnterpriseCsvPdo', false) && $pdo instanceof EnterpriseCsvPdo) {
        // CSV ist eine verwaltete Control-Plane: fehlende Basisdateien werden
        // durch ensureAdminSchema() deterministisch angelegt.
        $pdo->ensureAdminSchema();
        return in_array($table, $pdo->tableNames(), true);
    }

    try {
        // Absichtlich ohne information_schema/DATABASE(): funktioniert fuer
        // MySQL/MariaDB, PostgreSQL, SQLite, MSSQL und Oracle gleichermaßen.
        $pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1=0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function easyit_admin_schema_missing_tables(PDO $pdo): array
{
    $missing = [];
    foreach (easyit_admin_schema_required_tables() as $table) {
        if (!easyit_admin_schema_table_exists($pdo, $table)) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function easyit_admin_schema_apply_base_migration(PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        throw new RuntimeException('Schema-Reparaturdatei fehlt: ' . $file);
    }
    $installer = require $file;
    if (!is_callable($installer)) {
        throw new RuntimeException('Ungueltige Schema-Reparaturdatei: ' . basename($file));
    }
    $installer($pdo);
}

function easyit_admin_schema_ensure_superadmin_role(PDO $pdo): void
{
    if (!easyit_admin_schema_table_exists($pdo, 'roles')) {
        return;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM roles WHERE name='superadmin' LIMIT 1");
        $st->execute();
        if ($st->fetchColumn() !== false) {
            return;
        }
    } catch (Throwable) {
        // Einige Adapter normalisieren LIMIT intern; bei einem echten Fehler
        // versucht der INSERT unten die Rolle dennoch idempotent anzulegen.
    }

    try {
        $pdo->prepare('INSERT INTO roles (name,label) VALUES (?,?)')
            ->execute(['superadmin', 'Superadministrator']);
    } catch (Throwable $e) {
        // Parallel angelegte/zwischenzeitlich vorhandene Rolle ist akzeptabel.
        try {
            $st = $pdo->prepare("SELECT id FROM roles WHERE name='superadmin' LIMIT 1");
            $st->execute();
            if ($st->fetchColumn() !== false) {
                return;
            }
        } catch (Throwable) {
        }
        throw $e;
    }
}

/**
 * @return array{repaired:bool,before:list<string>,after:list<string>,applied:list<string>}
 */
function easyit_admin_schema_assert_or_repair(PDO $pdo, string $schemaDir): array
{
    if (class_exists('EnterpriseCsvPdo', false) && $pdo instanceof EnterpriseCsvPdo) {
        $pdo->ensureAdminSchema();
    }

    $before = easyit_admin_schema_missing_tables($pdo);
    $applied = [];

    if ($before !== []) {
        $map = [
            '001_migrations.php' => ['migrations'],
            '002_identity.php' => ['roles', 'users', 'user_roles'],
            '003_projects_audit.php' => ['projects', 'audit_log'],
            '004_licensing.php' => ['enterprise_licenses'],
        ];

        foreach ($map as $migration => $tables) {
            if (array_intersect($tables, $before) === []) {
                continue;
            }
            easyit_admin_schema_apply_base_migration($pdo, rtrim($schemaDir, '/\\') . DIRECTORY_SEPARATOR . $migration);
            $applied[] = $migration;
        }
    }

    easyit_admin_schema_ensure_superadmin_role($pdo);
    $after = easyit_admin_schema_missing_tables($pdo);

    if ($after !== []) {
        throw new RuntimeException(
            'Administrationsschema ist unvollstaendig. Fehlende Pflichttabellen: ' . implode(', ', $after)
            . '. Schritt 6 muss erfolgreich abgeschlossen werden.'
        );
    }

    return [
        'repaired' => $applied !== [],
        'before' => $before,
        'after' => $after,
        'applied' => $applied,
    ];
}
