<?php
declare(strict_types=1);

/**
 * HF88 Storage Phase 1
 * Central, project-oriented storage layout.
 *
 * storage/projects/<storage_key>/
 *   csv/
 *   sqlite/
 *   pgsql/dumps/
 *   documents/dataform/
 *   documents/calendar/
 *   documents/attachments/
 *   documents/imports/
 *   documents/generated/
 *   backup/
 *   export/
 *   temp/
 *   project.json
 */

function enterprise_project_storage_base(): string
{
    return dirname(__DIR__, 2) . '/storage/projects';
}

/** HF88 Storage Phase 4: central system storage areas outside project-owned data. */
function enterprise_system_storage_base(): string
{
    return dirname(__DIR__, 2) . '/storage/system';
}

function enterprise_system_storage_path(string $area): string
{
    $allowed = [
        'archive', 'archive/project-backups', 'archive/legacy-project-distribution-packages',
        'temp', 'temp/project-restore-previews', 'logs', 'cache', 'licensing',
        'recovery', 'packages', 'installer', 'monitoring', 'secrets'
    ];
    $area = trim(str_replace('\\', '/', $area), '/');
    if (!in_array($area, $allowed, true)) {
        throw new InvalidArgumentException('Unbekannter System-Storage-Bereich: ' . $area);
    }
    return enterprise_system_storage_base() . '/' . $area;
}

function enterprise_system_storage_ensure(string $area): string
{
    $path = enterprise_system_storage_path($area);
    enterprise_project_storage_mkdir($path);
    $deny = "Options -Indexes -ExecCGI\nRequire all denied\nDeny from all\n";
    if (!is_file($path . '/.htaccess')) @file_put_contents($path . '/.htaccess', $deny, LOCK_EX);
    if (!is_file($path . '/index.html')) @file_put_contents($path . '/index.html', '<!doctype html><title>403</title>', LOCK_EX);
    return $path;
}

function enterprise_project_storage_artifact_path(array $project, string $area, ?string $subdir = null): string
{
    if (!in_array($area, ['backup', 'export', 'temp'], true)) {
        throw new InvalidArgumentException('Ungültiger Projekt-Artefaktbereich: ' . $area);
    }
    $base = enterprise_project_storage_path($project, $area);
    if ($subdir === null || trim($subdir) === '') return $base;
    $subdir = trim(str_replace('\\', '/', $subdir), '/');
    if (preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,190}$~', $subdir) !== 1 || str_contains($subdir, '..')) {
        throw new InvalidArgumentException('Ungültiger Projekt-Artefakt-Unterordner.');
    }
    return $base . '/' . $subdir;
}

function enterprise_project_storage_safe_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 80) : 'project';
}

function enterprise_project_storage_key(int $projectId, string $slug): string
{
    if ($projectId < 1) throw new RuntimeException('Ungültige Projekt-ID für Storage-Key.');
    return 'prj_' . str_pad((string)$projectId, 8, '0', STR_PAD_LEFT) . '_' . enterprise_project_storage_safe_slug($slug);
}

function enterprise_project_storage_validate_key(string $key): string
{
    $key = trim($key);
    if (preg_match('/^prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}$/', $key) !== 1) {
        throw new RuntimeException('Ungültiger Projekt-Storage-Key.');
    }
    return $key;
}

function enterprise_project_storage_root(array $project): string
{
    $key = enterprise_project_storage_validate_key((string)($project['storage_key'] ?? ''));
    return enterprise_project_storage_base() . '/' . $key;
}

function enterprise_project_storage_path(array $project, string $area): string
{
    $allowed = ['csv','sqlite','pgsql','documents','backup','export','temp'];
    if (!in_array($area, $allowed, true)) throw new InvalidArgumentException('Unbekannter Projekt-Storage-Bereich: '.$area);
    return enterprise_project_storage_root($project) . '/' . $area;
}

/** HF88 Storage Phase 5: canonical physical locations. New runtime code must use these helpers. */
function enterprise_project_storage_csv_path(array $project): string
{
    return enterprise_project_storage_path($project, 'csv');
}

function enterprise_project_storage_sqlite_file(array $project): string
{
    return enterprise_project_storage_path($project, 'sqlite') . '/database.sqlite';
}

function enterprise_project_storage_pgsql_metadata_file(array $project): string
{
    return enterprise_project_storage_path($project, 'pgsql') . '/connection.json';
}

function enterprise_project_storage_export_path(array $project, string $subdir = 'application-packages'): string
{
    return enterprise_project_storage_artifact_path($project, 'export', $subdir);
}

function enterprise_project_storage_documents_path(array $project, ?string $module = null): string
{
    $base = enterprise_project_storage_path($project, 'documents');
    if ($module === null || trim($module) === '') return $base;
    $module = strtolower(trim($module));
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $module) !== 1) throw new InvalidArgumentException('Ungültiger Dokument-Modulname.');
    return $base . '/' . $module;
}


function enterprise_project_storage_find_by_id(int $projectId): ?string
{
    if ($projectId < 1) return null;
    $pattern = enterprise_project_storage_base() . '/prj_' . str_pad((string)$projectId, 8, '0', STR_PAD_LEFT) . '_*';
    $matches = array_values(array_filter(glob($pattern) ?: [], 'is_dir'));
    if (count($matches) !== 1) return null;
    return $matches[0];
}

function enterprise_project_storage_documents_path_by_id(int $projectId, ?string $module = null): ?string
{
    $root = enterprise_project_storage_find_by_id($projectId);
    if ($root === null) return null;
    $base = $root . '/documents';
    if ($module === null || trim($module) === '') return $base;
    $module = strtolower(trim($module));
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $module) !== 1) throw new InvalidArgumentException('Ungültiger Dokument-Modulname.');
    return $base . '/' . $module;
}

function enterprise_project_storage_relative_path(string $absolutePath): string
{
    $root = dirname(__DIR__, 2);
    $rootNorm = rtrim(str_replace('\\','/',$root), '/');
    $pathNorm = str_replace('\\','/',$absolutePath);
    if (!str_starts_with($pathNorm, $rootNorm . '/')) throw new RuntimeException('Pfad liegt außerhalb des Enterprise-Managers.');
    return ltrim(substr($pathNorm, strlen($rootNorm)), '/');
}

function enterprise_project_storage_mkdir(string $path): void
{
    if (is_dir($path)) return;
    if (!@mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Storage-Verzeichnis konnte nicht angelegt werden: '.$path);
    }
}

function enterprise_project_storage_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\n";
    if (@file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Storage-Metadatei konnte nicht geschrieben werden: '.$path);
    }
}

function enterprise_project_storage_manifest(array $project): array
{
    return [
        'schema_version' => 1,
        'project_id' => (int)($project['id'] ?? 0),
        'storage_key' => (string)($project['storage_key'] ?? ''),
        'project_name' => (string)($project['name'] ?? ''),
        'project_slug' => (string)($project['slug'] ?? ''),
        'product_type' => (string)($project['product_type'] ?? 'dataform'),
        'active_database_driver' => (string)($project['database_driver'] ?? ''),
        'database_name' => (string)($project['database_name'] ?? ''),
        'created_at' => (string)($project['created_at'] ?? ''),
    ];
}

function enterprise_project_storage_provision(array $project, array $env = []): string
{
    $root = enterprise_project_storage_root($project);
    enterprise_project_storage_mkdir($root);

    foreach ([
        'csv', 'sqlite', 'pgsql', 'pgsql/dumps',
        'documents', 'documents/dataform', 'documents/calendar', 'documents/attachments', 'documents/imports', 'documents/generated',
        'backup', 'export', 'temp'
    ] as $relative) {
        enterprise_project_storage_mkdir($root . '/' . $relative);
    }

    // System-owned areas deliberately live outside project roots so they can
    // survive project deletion and cannot be confused with business documents.
    enterprise_system_storage_ensure('archive/project-backups');
    enterprise_system_storage_ensure('temp/project-restore-previews');

    // Protect project files when Apache serves the application root directly.
    $deny = "Options -Indexes -ExecCGI\nRequire all denied\nDeny from all\n";
    if (!is_file($root.'/.htaccess')) @file_put_contents($root.'/.htaccess', $deny, LOCK_EX);
    if (!is_file($root.'/index.html')) @file_put_contents($root.'/index.html', '<!doctype html><title>403</title>', LOCK_EX);

    enterprise_project_storage_write_json($root . '/project.json', enterprise_project_storage_manifest($project));

    if (strtolower((string)($project['database_driver'] ?? '')) === 'pgsql') {
        enterprise_project_storage_write_json($root . '/pgsql/connection.json', [
            'driver' => 'pgsql',
            'host' => (string)($env['PROJECT_DB_HOST'] ?? '127.0.0.1'),
            'port' => (int)($env['PROJECT_DB_PORT'] ?? 5432),
            'database' => (string)($project['database_name'] ?? ''),
            'schema' => function_exists('enterprise_project_store_pgsql_schema') ? enterprise_project_store_pgsql_schema($env) : (string)($env['PROJECT_DB_SCHEMA'] ?? 'public'),
            'username' => (string)($env['PROJECT_DB_USERNAME'] ?? ''),
            'contains_password' => false,
        ]);
    }

    return $root;
}

function enterprise_project_storage_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $base = realpath(enterprise_project_storage_base());
    $real = realpath($path);
    if ($base === false || $real === false || !str_starts_with(str_replace('\\','/',$real), rtrim(str_replace('\\','/',$base),'/').'/')) {
        throw new RuntimeException('Projekt-Storage liegt außerhalb des freigegebenen Projektbereichs.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) { if (!@unlink($item->getPathname())) throw new RuntimeException('Storage-Datei konnte nicht gelöscht werden: '.$item->getPathname()); }
        elseif ($item->isDir() && !@rmdir($item->getPathname())) throw new RuntimeException('Storage-Ordner konnte nicht gelöscht werden: '.$item->getPathname());
    }
    if (!@rmdir($real)) throw new RuntimeException('Projekt-Storage konnte nicht gelöscht werden: '.$real);
}

function enterprise_project_storage_assign(PDO $pdo, int $projectId, string $slug): string
{
    $key = enterprise_project_storage_key($projectId, $slug);
    $st = $pdo->prepare('UPDATE projects SET storage_key=? WHERE id=?');
    $st->execute([$key, $projectId]);
    return $key;
}

function enterprise_project_storage_fetch(PDO $pdo, int $projectId): array
{
    $st=$pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');
    $st->execute([$projectId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Projekt für Storage-Provisionierung nicht gefunden.');
    return $row;
}

/**
 * Backfills stable storage keys for legacy projects. Physical legacy CSV/SQLite
 * stores are intentionally not moved in phase 1.
 */
function enterprise_project_storage_upgrade(PDO $pdo, array $env = []): void
{
    $driver = strtolower((string)($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''));
    $isSqlite = $pdo instanceof EnterpriseSqlitePdo || $driver === 'sqlite';
    $has = false;
    try { $pdo->query('SELECT storage_key FROM projects WHERE 1=0'); $has=true; } catch (Throwable) {}
    if (!$has) {
        if ($isSqlite) $pdo->exec('ALTER TABLE projects ADD storage_key TEXT NULL');
        elseif (in_array($driver,['mysql'],true)) $pdo->exec("ALTER TABLE projects ADD storage_key VARCHAR(190) NULL AFTER slug");
        elseif ($driver === 'pgsql') $pdo->exec('ALTER TABLE projects ADD COLUMN storage_key VARCHAR(190) NULL');
        elseif (in_array($driver,['sqlsrv','dblib'],true)) $pdo->exec('ALTER TABLE projects ADD storage_key NVARCHAR(190) NULL');
        else $pdo->exec('ALTER TABLE projects ADD storage_key VARCHAR(190) NULL');
    }

    $rows=$pdo->query('SELECT id,slug,storage_key FROM projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $update=$pdo->prepare('UPDATE projects SET storage_key=? WHERE id=?');
    foreach($rows as $row){
        $current=trim((string)($row['storage_key']??''));
        if($current!=='') continue;
        $update->execute([enterprise_project_storage_key((int)$row['id'],(string)$row['slug']),(int)$row['id']]);
    }

    try { $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_projects_storage_key ON projects(storage_key)'); }
    catch(Throwable){ try { $pdo->exec('CREATE UNIQUE INDEX uq_projects_storage_key ON projects(storage_key)'); } catch(Throwable){} }

    enterprise_project_storage_mkdir(enterprise_project_storage_base());
    $deny="Options -Indexes -ExecCGI\nRequire all denied\nDeny from all\n";
    if(!is_file(enterprise_project_storage_base().'/.htaccess')) @file_put_contents(enterprise_project_storage_base().'/.htaccess',$deny,LOCK_EX);
    if(!is_file(enterprise_project_storage_base().'/index.html')) @file_put_contents(enterprise_project_storage_base().'/index.html','<!doctype html><title>403</title>',LOCK_EX);
}
