<?php
declare(strict_types=1);

require_once __DIR__ . '/project_store.php';

/**
 * Project backup restore helper (HF73).
 *
 * Restores ZIP archives created by enterprise_project_backup_create(). The
 * archive is never extracted into the web root; only the known manifest,
 * project metadata and SQL dump are read through ZipArchive.
 */

function enterprise_project_restore_root(): string
{
    return enterprise_system_storage_ensure('temp/project-restore-previews');
}

function enterprise_project_restore_ensure_root(): string
{
    $root = enterprise_project_restore_root();

    $denyFile = $root . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\nDeny from all\n");
    }
    $indexFile = $root . '/index.html';
    if (!is_file($indexFile)) {
        @file_put_contents($indexFile, "<!doctype html><title>403</title>\n");
    }

    return $root;
}

function enterprise_project_restore_cleanup(int $maxAgeSeconds = 7200): void
{
    $root = enterprise_project_restore_ensure_root();
    $cutoff = time() - max(1800, $maxAgeSeconds);
    foreach (glob($root . '/*.zip') ?: [] as $file) {
        if (is_file($file) && (int)@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }

    foreach ((array)($_SESSION['project_restore_previews'] ?? []) as $token => $entry) {
        if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < time()) {
            unset($_SESSION['project_restore_previews'][$token]);
        }
    }
}

function enterprise_project_restore_valid_database_name(string $name): bool
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/', $name) === 1;
}

function enterprise_project_restore_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function enterprise_project_restore_server(array $env): PDO
{
    foreach (['PROJECT_DB_HOST', 'PROJECT_DB_PORT', 'PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') {
            throw new RuntimeException("Projekt-DB-Konfiguration {$key} fehlt. Eine Wiederherstellung ist nicht möglich.");
        }
    }

    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function enterprise_project_restore_database_exists(PDO $server, string $database): bool
{
    $stmt = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->execute([$database]);
    return $stmt->fetchColumn() !== false;
}

function enterprise_project_restore_assert_target_safe(array $env, string $database): void
{
    if (!enterprise_project_restore_valid_database_name($database)) {
        throw new RuntimeException('Der Ziel-Datenbankname ist ungültig.');
    }

    $protected = ['mysql', 'information_schema', 'performance_schema', 'sys', 'postgres', 'template0', 'template1'];
    $adminDatabase = strtolower(trim((string)($env['ADMIN_DB_DATABASE'] ?? '')));
    if ($adminDatabase !== '') {
        $protected[] = $adminDatabase;
    }
    if (in_array(strtolower($database), array_unique($protected), true)) {
        throw new RuntimeException('Die Datenbank `' . $database . '` ist eine geschützte System-/Enterprise-Datenbank und darf nicht als Restore-Ziel verwendet werden.');
    }
}

/** @return array{manifest:array<string,mixed>,project:array<string,mixed>,database_sql:string,database_driver:string,sha256:string,size:int,filename:string} */
function enterprise_project_restore_inspect_archive(string $path, string $filename = ''): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Für den Projekt-Restore wird die PHP-Erweiterung ext-zip / ZipArchive benötigt.');
    }
    if (!is_file($path) || (int)@filesize($path) <= 0) {
        throw new RuntimeException('Die Sicherungsdatei wurde nicht gefunden oder ist leer.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Die ausgewählte Datei ist kein lesbares ZIP-Sicherungsarchiv.');
    }

    try {
        $csvFiles = 0;
        $sqliteFileFound = false;
        $pgsqlFileFound = false;
        $oracleFileFound = false;
        $mssqlFileFound = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name) === 1) {
                throw new RuntimeException('Das Sicherungsarchiv enthält einen unzulässigen Dateipfad.');
            }
            if (str_starts_with($name, 'csv-data/') && !str_ends_with($name, '/')) {
                $csvFiles++;
            }
            if ($name === 'sqlite-data/database.sqlite') {
                $sqliteFileFound = true;
            }
            if ($name === 'postgresql-data/database.sql') {
                $pgsqlFileFound = true;
            }
            if ($name === 'oracle-data/database.sql') {
                $oracleFileFound = true;
            }
            if ($name === 'mssql-data/database.sql') {
                $mssqlFileFound = true;
            }
        }

        foreach (['manifest.json', 'project.json'] as $required) {
            if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) {
                throw new RuntimeException('Die Projektsicherung ist unvollständig: `' . $required . '` fehlt.');
            }
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        $projectRaw = $zip->getFromName('project.json');
        if (!is_string($manifestRaw) || !is_string($projectRaw)) {
            throw new RuntimeException('Die Projektsicherung konnte nicht vollständig gelesen werden.');
        }

        $manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR);
        $project = json_decode($projectRaw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($project)) {
            throw new RuntimeException('Manifest oder Projektmetadaten sind ungültig.');
        }
        if ((string)($manifest['format'] ?? '') !== 'easyit-project-backup') {
            throw new RuntimeException('Die ZIP-Datei ist keine easyIT-Projektsicherung.');
        }
        $formatVersion = (int)($manifest['format_version'] ?? 0);
        if (!in_array($formatVersion, [1, 2, 3, 4, 5], true)) {
            throw new RuntimeException('Diese Version der Projektsicherung wird nicht unterstützt.');
        }

        $manifestProject = is_array($manifest['project'] ?? null) ? $manifest['project'] : [];
        $database = trim((string)($manifestProject['database_name'] ?? $project['database_name'] ?? ''));
        if (!enterprise_project_restore_valid_database_name($database)) {
            throw new RuntimeException('Die Sicherung enthält keinen gültigen Projektspeichernamen.');
        }
        $driver = strtolower(trim((string)($manifestProject['database_driver'] ?? $project['database_driver'] ?? 'mysql')));
        if ($driver === 'mariadb') $driver = 'mysql';
        if (!in_array($driver, ['mysql', 'csv', 'sqlite', 'pgsql', 'oracle', 'mssql'], true)) {
            throw new RuntimeException('Der automatische Restore ist für diesen Projekttreiber noch nicht freigegeben: ' . $driver);
        }

        $databaseSql = '';
        if ($driver === 'mysql') {
            if ($zip->locateName('database.sql', ZipArchive::FL_NOCASE) === false) {
                throw new RuntimeException('Die MySQL/MariaDB-Projektsicherung ist unvollständig: `database.sql` fehlt.');
            }
            $databaseSql = $zip->getFromName('database.sql');
            if (!is_string($databaseSql) || trim($databaseSql) === '') {
                throw new RuntimeException('Der SQL-Dump in der Projektsicherung ist leer.');
            }
        } elseif ($driver === 'pgsql') {
            if ($formatVersion < 4 || !$pgsqlFileFound) {
                throw new RuntimeException('Die PostgreSQL-Projektsicherung enthält keine Datei `postgresql-data/database.sql`.');
            }
            $databaseSql=$zip->getFromName('postgresql-data/database.sql');
            if(!is_string($databaseSql)||trim($databaseSql)==='')throw new RuntimeException('Der PostgreSQL-SQL-Dump in der Projektsicherung ist leer.');
        } elseif ($driver === 'oracle') {
            if ($formatVersion < 4 || !$oracleFileFound) throw new RuntimeException('Die Oracle-XE-Projektsicherung enthält keine Datei `oracle-data/database.sql`.');
            $databaseSql=$zip->getFromName('oracle-data/database.sql');
            if(!is_string($databaseSql)||trim($databaseSql)==='')throw new RuntimeException('Der Oracle-XE-SQL-Dump in der Projektsicherung ist leer.');
        } elseif ($driver === 'mssql') {
            if ($formatVersion < 4 || !$mssqlFileFound) throw new RuntimeException('Die MSSQL-Projektsicherung enthält keine Datei `mssql-data/database.sql`.');
            $databaseSql=$zip->getFromName('mssql-data/database.sql');
            if(!is_string($databaseSql)||trim($databaseSql)==='')throw new RuntimeException('Der MSSQL-SQL-Dump in der Projektsicherung ist leer.');
        } elseif ($driver === 'csv') {
            if ($formatVersion < 2 || $csvFiles < 1) {
                throw new RuntimeException('Die CSV-Projektsicherung enthält keinen vollständigen Ordner `csv-data/`.');
            }
        } else {
            if ($formatVersion < 3 || !$sqliteFileFound) {
                throw new RuntimeException('Die SQLite-Projektsicherung enthält keine Datei `sqlite-data/database.sqlite`.');
            }
            $stat=$zip->statName('sqlite-data/database.sqlite');
            if(!is_array($stat) || (int)($stat['size']??0)<=0) {
                throw new RuntimeException('Die SQLite-Datenbankdatei in der Projektsicherung ist leer.');
            }
        }

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || strlen($sha256) !== 64) {
            throw new RuntimeException('Die SHA-256-Prüfung der Projektsicherung ist fehlgeschlagen.');
        }

        return [
            'manifest' => $manifest,
            'project' => $project,
            'database_sql' => $databaseSql,
            'database_driver' => $driver,
            'sha256' => $sha256,
            'size' => (int)filesize($path),
            'filename' => $filename !== '' ? basename($filename) : basename($path),
        ];
    } catch (JsonException $e) {
        throw new RuntimeException('Manifest oder Projektmetadaten enthalten ungültiges JSON.', 0, $e);
    } finally {
        $zip->close();
    }
}

/** @return array{token:string,path:string,filename:string,expires_at:int,preview:array<string,mixed>} */
function enterprise_project_restore_stage_upload(array $file): array
{
    enterprise_project_restore_cleanup();

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Die Sicherungsdatei überschreitet upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'Die Sicherungsdatei überschreitet die zulässige Formulargröße.',
            UPLOAD_ERR_PARTIAL => 'Die Sicherungsdatei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Bitte wählen Sie eine Projekt-ZIP-Sicherung aus.',
        ];
        throw new RuntimeException($messages[$error] ?? 'Die Sicherungsdatei konnte nicht hochgeladen werden.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Die hochgeladene Sicherungsdatei konnte nicht verifiziert werden.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 1024 * 1024 * 1024) {
        throw new RuntimeException('Die Sicherungsdatei ist leer oder größer als 1 GiB.');
    }

    $root = enterprise_project_restore_ensure_root();
    $token = bin2hex(random_bytes(24));
    $path = $root . '/' . $token . '.zip';
    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Die Sicherungsdatei konnte nicht in den geschützten Restore-Bereich übernommen werden.');
    }
    @chmod($path, 0660);

    try {
        $preview = enterprise_project_restore_inspect_archive($path, (string)($file['name'] ?? 'projekt-backup.zip'));
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }

    $expiresAt = time() + 7200;
    $_SESSION['project_restore_previews'][$token] = [
        'path' => $path,
        'filename' => $preview['filename'],
        'created_at' => time(),
        'expires_at' => $expiresAt,
        'sha256' => $preview['sha256'],
    ];

    return [
        'token' => $token,
        'path' => $path,
        'filename' => $preview['filename'],
        'expires_at' => $expiresAt,
        'preview' => $preview,
    ];
}

/** @return array{path:string,filename:string,expires_at:int,preview:array<string,mixed>} */
function enterprise_project_restore_resolve_preview(string $token): array
{
    enterprise_project_restore_cleanup();
    if ($token === '' || preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
        throw new RuntimeException('Ungültige Restore-Vorschau.');
    }

    $entry = $_SESSION['project_restore_previews'][$token] ?? null;
    if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < time()) {
        unset($_SESSION['project_restore_previews'][$token]);
        throw new RuntimeException('Die Restore-Vorschau ist abgelaufen. Bitte laden Sie die Sicherung erneut hoch.');
    }

    $root = realpath(enterprise_project_restore_ensure_root());
    $path = realpath((string)($entry['path'] ?? ''));
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        unset($_SESSION['project_restore_previews'][$token]);
        throw new RuntimeException('Die temporäre Restore-Datei wurde nicht gefunden. Bitte laden Sie die Sicherung erneut hoch.');
    }

    $preview = enterprise_project_restore_inspect_archive($path, (string)($entry['filename'] ?? basename($path)));
    if (!hash_equals((string)($entry['sha256'] ?? ''), (string)$preview['sha256'])) {
        unset($_SESSION['project_restore_previews'][$token]);
        throw new RuntimeException('Die temporäre Restore-Datei hat sich seit der Prüfung verändert.');
    }

    return [
        'path' => $path,
        'filename' => (string)$preview['filename'],
        'expires_at' => (int)$entry['expires_at'],
        'preview' => $preview,
    ];
}

function enterprise_project_restore_rewrite_database_sql(string $sql, string $sourceDatabase, string $targetDatabase): string
{
    if (!enterprise_project_restore_valid_database_name($sourceDatabase) || !enterprise_project_restore_valid_database_name($targetDatabase)) {
        throw new RuntimeException('Quell- oder Ziel-Datenbankname ist für den Restore ungültig.');
    }
    if ($sourceDatabase === $targetDatabase) {
        return $sql;
    }

    // The HF72 dump writes database references as quoted identifiers. Replacing
    // the exact quoted identifier also updates qualified view/trigger references
    // while leaving ordinary data values untouched.
    return str_replace(
        enterprise_project_restore_quote_identifier($sourceDatabase),
        enterprise_project_restore_quote_identifier($targetDatabase),
        $sql
    );
}

/**
 * Restore project documents from a v5 backup into the new project-oriented
 * storage root. Existing files are never silently overwritten with different
 * content.
 */
function enterprise_project_restore_documents(string $zipPath, array $project): int
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Für den Dokument-Restore wird ext-zip / ZipArchive benötigt.');
    $targetRoot = enterprise_project_storage_path($project, 'documents');
    enterprise_project_storage_mkdir($targetRoot);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Das Sicherungsarchiv konnte für den Dokument-Restore nicht geöffnet werden.');
    $files = 0;
    try {
        for ($i=0; $i<$zip->numFiles; $i++) {
            $entry = str_replace('\\','/',(string)$zip->getNameIndex($i));
            if (!str_starts_with($entry,'documents/') || str_ends_with($entry,'/')) continue;
            $relative = substr($entry, strlen('documents/'));
            if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative,'/') || preg_match('~(^|/)\.\.(/|$)~',$relative)===1) {
                throw new RuntimeException('Die Dokumentensicherung enthält einen unzulässigen Dateipfad.');
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) throw new RuntimeException('Dokument konnte nicht aus der Sicherung gelesen werden: '.$relative);
            $dest = $targetRoot.'/'.$relative;
            enterprise_project_storage_mkdir(dirname($dest));
            if (is_file($dest)) {
                $old = hash_file('sha256',$dest);
                $new = hash('sha256',$content);
                if (!is_string($old) || !hash_equals($old,$new)) throw new RuntimeException('Dokumentkonflikt beim Restore: '.$relative);
                continue;
            }
            if (file_put_contents($dest,$content,LOCK_EX) === false) throw new RuntimeException('Dokument konnte nicht wiederhergestellt werden: '.$relative);
            $files++;
        }
    } finally { $zip->close(); }
    return $files;
}

/**
 * Execute an SQL dump with support for MySQL client DELIMITER directives.
 * The parser only splits delimiters outside strings, quoted identifiers and comments.
 */
function enterprise_project_restore_execute_sql(PDO $server, string $sql): int
{
    $delimiter = ';';
    $buffer = '';
    $statementCount = 0;
    $length = strlen($sql);
    $i = 0;
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $lineStart = true;

    $flush = static function (string $statement) use ($server, &$statementCount): void {
        $statement = trim($statement);
        if ($statement === '') {
            return;
        }
        $server->exec($statement);
        $statementCount++;
    };

    while ($i < $length) {
        // DELIMITER is a mysql-client directive and appears on a standalone line
        // in easyIT backups. Recognize it only at a clean statement boundary.
        if ($lineStart && $quote === null && !$blockComment && trim($buffer) === '') {
            $lineEnd = strpos($sql, "\n", $i);
            if ($lineEnd === false) {
                $lineEnd = $length;
            }
            $line = substr($sql, $i, $lineEnd - $i);
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m) === 1) {
                $delimiter = (string)$m[1];
                if ($delimiter === '') {
                    throw new RuntimeException('Ungültige DELIMITER-Anweisung im SQL-Backup.');
                }
                $i = $lineEnd < $length ? $lineEnd + 1 : $lineEnd;
                $lineStart = true;
                continue;
            }
        }

        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            $buffer .= $ch;
            if ($ch === "\n") {
                $lineComment = false;
                $lineStart = true;
            } else {
                $lineStart = false;
            }
            $i++;
            continue;
        }

        if ($blockComment) {
            $buffer .= $ch;
            if ($ch === '*' && $next === '/') {
                $buffer .= '/';
                $i += 2;
                $blockComment = false;
                $lineStart = false;
                continue;
            }
            $lineStart = $ch === "\n";
            $i++;
            continue;
        }

        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && ($quote === "'" || $quote === '"') && $i + 1 < $length) {
                $buffer .= $sql[$i + 1];
                $i += 2;
                $lineStart = false;
                continue;
            }
            if ($ch === $quote) {
                // SQL permits doubled quote/backtick escaping.
                if ($next === $quote) {
                    $buffer .= $next;
                    $i += 2;
                    $lineStart = false;
                    continue;
                }
                $quote = null;
            }
            $lineStart = $ch === "\n";
            $i++;
            continue;
        }

        if (($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $ch === '#') {
            $lineComment = true;
            $buffer .= $ch;
            if ($ch === '-') {
                $buffer .= $next;
                $i += 2;
            } else {
                $i++;
            }
            $lineStart = false;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $blockComment = true;
            $buffer .= '/*';
            $i += 2;
            $lineStart = false;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buffer .= $ch;
            $lineStart = false;
            $i++;
            continue;
        }

        if ($delimiter !== '' && substr($sql, $i, strlen($delimiter)) === $delimiter) {
            $flush($buffer);
            $buffer = '';
            $i += strlen($delimiter);
            $lineStart = false;
            continue;
        }

        $buffer .= $ch;
        $lineStart = $ch === "\n";
        $i++;
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('Der SQL-Dump endet innerhalb eines nicht abgeschlossenen Strings oder Kommentars.');
    }
    $flush($buffer);

    return $statementCount;
}

/**
 * @return array{database_existed_before:bool,database_restored:bool,database_reused:bool,statements:int,strategy:string}
 */
function enterprise_project_restore_database(array $env, string $sourceDatabase, string $targetDatabase, string $databaseSql, string $strategy, string $replaceConfirmation = ''): array
{
    enterprise_project_restore_assert_target_safe($env, $targetDatabase);
    $server = enterprise_project_restore_server($env);
    $exists = enterprise_project_restore_database_exists($server, $targetDatabase);
    $strategy = strtolower(trim($strategy));
    if (!in_array($strategy, ['auto', 'reuse', 'restore', 'replace'], true)) {
        throw new RuntimeException('Ungültige Restore-Strategie.');
    }

    if ($strategy === 'auto') {
        if ($exists && $targetDatabase !== $sourceDatabase) {
            throw new RuntimeException('Die geänderte Ziel-Datenbank `' . $targetDatabase . '` existiert bereits. Wählen Sie ausdrücklich „Vorhandene Datenbank verwenden“ oder „ersetzen“.');
        }
        $strategy = $exists ? 'reuse' : 'restore';
    }

    if ($strategy === 'reuse') {
        if (!$exists) {
            throw new RuntimeException('Die Ziel-Datenbank `' . $targetDatabase . '` existiert nicht und kann deshalb nicht weiterverwendet werden.');
        }
        return [
            'database_existed_before' => true,
            'database_restored' => false,
            'database_reused' => true,
            'statements' => 0,
            'strategy' => 'reuse',
        ];
    }

    if ($strategy === 'restore' && $exists) {
        throw new RuntimeException('Die Ziel-Datenbank `' . $targetDatabase . '` existiert bereits. Wählen Sie „Vorhandene Datenbank verwenden“ oder – nach Prüfung – „ersetzen“.');
    }

    if ($strategy === 'replace') {
        if (!$exists) {
            $strategy = 'restore';
        } else {
            if (!hash_equals($targetDatabase, trim($replaceConfirmation))) {
                throw new RuntimeException('Zum Ersetzen einer vorhandenen Datenbank muss deren Name exakt bestätigt werden.');
            }
            $server->exec('DROP DATABASE ' . enterprise_project_restore_quote_identifier($targetDatabase));
            if (enterprise_project_restore_database_exists($server, $targetDatabase)) {
                throw new RuntimeException('Die vorhandene Ziel-Datenbank konnte nicht entfernt werden.');
            }
        }
    }

    $rewrittenSql = enterprise_project_restore_rewrite_database_sql($databaseSql, $sourceDatabase, $targetDatabase);
    try {
        @set_time_limit(0);
        $statements = enterprise_project_restore_execute_sql($server, $rewrittenSql);
        if (!enterprise_project_restore_database_exists($server, $targetDatabase)) {
            throw new RuntimeException('Die Ziel-Datenbank wurde nach dem SQL-Restore nicht gefunden.');
        }
    } catch (Throwable $e) {
        // If this was a brand-new target, remove the partial database. For a
        // replace operation the previous database cannot be reconstructed here;
        // keep any partial restored state for diagnosis rather than deleting it.
        if (!$exists) {
            try {
                $server->exec('DROP DATABASE IF EXISTS ' . enterprise_project_restore_quote_identifier($targetDatabase));
            } catch (Throwable) {
            }
        }
        throw new RuntimeException('Die Projektdatenbank konnte nicht aus der Sicherung wiederhergestellt werden: ' . $e->getMessage(), 0, $e);
    }

    return [
        'database_existed_before' => $exists,
        'database_restored' => true,
        'database_reused' => false,
        'statements' => $statements,
        'strategy' => $strategy === 'replace' ? 'replace' : 'restore',
    ];
}


/**
 * Restore a PostgreSQL project database from the logical v4 SQL dump.
 * The dump is PostgreSQL-native and therefore executed as one libpq batch so
 * dollar-quoted PL/pgSQL function bodies remain intact.
 * @return array{database_existed_before:bool,database_restored:bool,database_reused:bool,statements:int,strategy:string}
 */
function enterprise_project_restore_pgsql_store(array $env,string $sourceDatabase,string $targetDatabase,string $databaseSql,string $strategy,string $replaceConfirmation=''): array
{
    enterprise_project_restore_assert_target_safe($env,$targetDatabase);
    if(!extension_loaded('pdo_pgsql'))throw new RuntimeException('Für den PostgreSQL-Projekt-Restore wird pdo_pgsql benötigt.');
    $exists=enterprise_project_store_exists($env,$targetDatabase,'pgsql');
    $strategy=strtolower(trim($strategy));
    if(!in_array($strategy,['auto','reuse','restore','replace'],true))throw new RuntimeException('Ungültige Restore-Strategie.');
    if($strategy==='auto'){
        if($exists && $targetDatabase!==$sourceDatabase)throw new RuntimeException('Die geänderte PostgreSQL-Zieldatenbank `'.$targetDatabase.'` existiert bereits. Wählen Sie ausdrücklich „Vorhandene Datenbank verwenden“ oder „ersetzen“.');
        $strategy=$exists?'reuse':'restore';
    }
    if($strategy==='reuse'){
        if(!$exists)throw new RuntimeException('Die PostgreSQL-Zieldatenbank `'.$targetDatabase.'` existiert nicht.');
        return ['database_existed_before'=>true,'database_restored'=>false,'database_reused'=>true,'statements'=>0,'strategy'=>'reuse'];
    }
    if($strategy==='restore' && $exists)throw new RuntimeException('Die PostgreSQL-Zieldatenbank `'.$targetDatabase.'` existiert bereits. Wählen Sie „verwenden“ oder „ersetzen“.');
    if($strategy==='replace' && $exists){
        if(!hash_equals($targetDatabase,trim($replaceConfirmation)))throw new RuntimeException('Zum Ersetzen einer vorhandenen PostgreSQL-Datenbank muss deren Name exakt bestätigt werden.');
        enterprise_project_store_delete($env,$targetDatabase,'pgsql');
        $exists=false;
    }elseif($strategy==='replace')$strategy='restore';

    $created=false;
    try{
        enterprise_project_store_create($env,$targetDatabase,'pgsql');$created=true;
        $pdo=new EnterprisePgsqlPdo(
            (string)($env['PROJECT_DB_HOST']??'127.0.0.1'),
            (int)($env['PROJECT_DB_PORT']??5432),
            $targetDatabase,
            (string)($env['PROJECT_DB_USERNAME']??''),
            (string)($env['PROJECT_DB_PASSWORD']??''),
            enterprise_project_store_pgsql_schema($env)
        );
        @set_time_limit(0);
        $pdo->exec($databaseSql);
        $tableCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_type='BASE TABLE'")->fetchColumn();
        if($tableCount<1)throw new RuntimeException('Der PostgreSQL-Restore hat keine Projekttabellen erzeugt.');
    }catch(Throwable $e){
        if($created){try{enterprise_project_store_delete($env,$targetDatabase,'pgsql');}catch(Throwable){}}
        throw new RuntimeException('Die PostgreSQL-Projektdatenbank konnte nicht aus der Sicherung wiederhergestellt werden: '.$e->getMessage(),0,$e);
    }
    return ['database_existed_before'=>$exists,'database_restored'=>true,'database_reused'=>false,'statements'=>1,'strategy'=>$strategy==='replace'?'replace':'restore'];
}

/**
 * Restore a CSV project store from the csv-data/ directory inside a v2 project backup.
 * @return array{database_existed_before:bool,database_restored:bool,database_reused:bool,statements:int,strategy:string}
 */
function enterprise_project_restore_csv_store(array $env, string $sourceDatabase, string $targetDatabase, string $zipPath, string $strategy, string $replaceConfirmation = ''): array
{
    enterprise_project_restore_assert_target_safe($env, $targetDatabase);
    $exists = enterprise_project_store_exists($env, $targetDatabase, 'csv');
    $strategy = strtolower(trim($strategy));
    if (!in_array($strategy, ['auto', 'reuse', 'restore', 'replace'], true)) {
        throw new RuntimeException('Ungültige Restore-Strategie.');
    }

    if ($strategy === 'auto') {
        if ($exists && $targetDatabase !== $sourceDatabase) {
            throw new RuntimeException('Der geänderte Ziel-Speicher `' . $targetDatabase . '` existiert bereits. Wählen Sie ausdrücklich „Vorhandenen Speicher verwenden“ oder „ersetzen“.');
        }
        $strategy = $exists ? 'reuse' : 'restore';
    }
    if ($strategy === 'reuse') {
        if (!$exists) throw new RuntimeException('Der Ziel-Speicher `' . $targetDatabase . '` existiert nicht und kann deshalb nicht weiterverwendet werden.');
        return ['database_existed_before'=>true,'database_restored'=>false,'database_reused'=>true,'statements'=>0,'strategy'=>'reuse'];
    }
    if ($strategy === 'restore' && $exists) {
        throw new RuntimeException('Der Ziel-Speicher `' . $targetDatabase . '` existiert bereits. Wählen Sie „Vorhandenen Speicher verwenden“ oder – nach Prüfung – „ersetzen“.');
    }
    if ($strategy === 'replace' && $exists) {
        if (!hash_equals($targetDatabase, trim($replaceConfirmation))) {
            throw new RuntimeException('Zum Ersetzen eines vorhandenen CSV-Projektspeichers muss dessen Name exakt bestätigt werden.');
        }
        enterprise_project_store_delete($env, $targetDatabase, 'csv');
    } elseif ($strategy === 'replace') {
        $strategy = 'restore';
    }

    if (!class_exists('ZipArchive')) throw new RuntimeException('Für den CSV-Projekt-Restore wird ext-zip / ZipArchive benötigt.');
    $target = enterprise_project_store_csv_database_path($env, $targetDatabase);
    $created = false;
    try {
        if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
            throw new RuntimeException('Der CSV-Zielordner konnte nicht angelegt werden.');
        }
        $created = true;
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('Das Sicherungsarchiv konnte nicht geöffnet werden.');
        $files = 0;
        try {
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = str_replace('\\','/',(string)$zip->getNameIndex($i));
                if (!str_starts_with($entry, 'csv-data/') || str_ends_with($entry, '/')) continue;
                $relative = substr($entry, strlen('csv-data/'));
                if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.(/|$)~',$relative)===1) {
                    throw new RuntimeException('Die CSV-Sicherung enthält einen unzulässigen Dateipfad.');
                }
                $content = $zip->getFromIndex($i);
                if (!is_string($content)) throw new RuntimeException('CSV-Sicherungsdatei konnte nicht gelesen werden: ' . $relative);
                $dest = $target . '/' . $relative;
                $dir = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Restore-Unterordner konnte nicht angelegt werden: '.$relative);
                if (file_put_contents($dest,$content,LOCK_EX) === false) throw new RuntimeException('CSV-Sicherungsdatei konnte nicht geschrieben werden: '.$relative);
                $files++;
            }
        } finally { $zip->close(); }
        if ($files < 1 || !enterprise_project_store_exists($env, $targetDatabase, 'csv')) {
            throw new RuntimeException('Der wiederhergestellte CSV-Projektspeicher ist unvollständig.');
        }
    } catch (Throwable $e) {
        if ($created && is_dir($target)) {
            try { enterprise_project_store_remove_tree($target); } catch (Throwable) {}
        }
        throw new RuntimeException('Der CSV-Projektspeicher konnte nicht aus der Sicherung wiederhergestellt werden: '.$e->getMessage(),0,$e);
    }

    return [
        'database_existed_before'=>$exists,
        'database_restored'=>true,
        'database_reused'=>false,
        'statements'=>0,
        'strategy'=>$strategy==='replace'?'replace':'restore',
    ];
}



/**
 * Restore a native SQLite project database from a v3 project backup.
 * The archived file is streamed to a temporary file, checked using
 * PRAGMA integrity_check and only then moved into the configured project store.
 *
 * @return array{database_existed_before:bool,database_restored:bool,database_reused:bool,statements:int,strategy:string}
 */
function enterprise_project_restore_sqlite_store(array $env, string $sourceDatabase, string $targetDatabase, string $zipPath, string $strategy, string $replaceConfirmation = ''): array
{
    enterprise_project_restore_assert_target_safe($env, $targetDatabase);
    $exists=enterprise_project_store_exists($env,$targetDatabase,'sqlite');
    $strategy=strtolower(trim($strategy));
    if(!in_array($strategy,['auto','reuse','restore','replace'],true))throw new RuntimeException('Ungültige Restore-Strategie.');
    if($strategy==='auto'){
        if($exists && $targetDatabase!==$sourceDatabase)throw new RuntimeException('Der geänderte SQLite-Zielspeicher `'.$targetDatabase.'` existiert bereits. Wählen Sie ausdrücklich „Vorhandenen Speicher verwenden“ oder „ersetzen“.');
        $strategy=$exists?'reuse':'restore';
    }
    if($strategy==='reuse'){
        if(!$exists)throw new RuntimeException('Der SQLite-Zielspeicher `'.$targetDatabase.'` existiert nicht und kann deshalb nicht weiterverwendet werden.');
        return ['database_existed_before'=>true,'database_restored'=>false,'database_reused'=>true,'statements'=>0,'strategy'=>'reuse'];
    }
    if($strategy==='restore' && $exists)throw new RuntimeException('Der SQLite-Zielspeicher `'.$targetDatabase.'` existiert bereits. Wählen Sie „Vorhandenen Speicher verwenden“ oder – nach Prüfung – „ersetzen“.');
    if($strategy==='replace' && $exists && !hash_equals($targetDatabase,trim($replaceConfirmation)))throw new RuntimeException('Zum Ersetzen eines vorhandenen SQLite-Projektspeichers muss dessen Name exakt bestätigt werden.');
    if($strategy==='replace' && !$exists)$strategy='restore';
    if(!class_exists('ZipArchive'))throw new RuntimeException('Für den SQLite-Projekt-Restore wird ext-zip / ZipArchive benötigt.');
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Für den SQLite-Projekt-Restore wird pdo_sqlite benötigt.');

    $target=enterprise_project_store_sqlite_database_path($env,$targetDatabase);
    $dir=dirname($target);
    if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir))throw new RuntimeException('Das SQLite-Zielverzeichnis konnte nicht angelegt werden.');
    $tmp=$dir.'/.'.basename($target).'.restore-'.bin2hex(random_bytes(8)).'.tmp';
    $zip=new ZipArchive();
    if($zip->open($zipPath)!==true)throw new RuntimeException('Das Sicherungsarchiv konnte nicht geöffnet werden.');
    try{
        $stream=$zip->getStream('sqlite-data/database.sqlite');
        if(!is_resource($stream))throw new RuntimeException('Die SQLite-Datenbankdatei konnte nicht aus der Sicherung gelesen werden.');
        $out=fopen($tmp,'wb');
        if($out===false){fclose($stream);throw new RuntimeException('Die temporäre SQLite-Restore-Datei konnte nicht angelegt werden.');}
        try{
            $bytes=stream_copy_to_stream($stream,$out);
            if($bytes===false || $bytes<=0)throw new RuntimeException('Die SQLite-Datenbankdatei konnte nicht vollständig entpackt werden.');
        }finally{fclose($out);fclose($stream);}
    }finally{$zip->close();}

    try{
        $probe=new EnterpriseSqlitePdo($tmp);
        $integrity=strtolower(trim((string)$probe->query('PRAGMA integrity_check')->fetchColumn()));
        if($integrity!=='ok')throw new RuntimeException('SQLite integrity_check meldet: '.($integrity!==''?$integrity:'unbekannter Fehler'));
        unset($probe);
        if($exists && $strategy==='replace')enterprise_project_store_delete($env,$targetDatabase,'sqlite');
        if(is_file($target))throw new RuntimeException('Die SQLite-Zieldatei existiert noch und konnte nicht sicher ersetzt werden.');
        if(!@rename($tmp,$target)){
            if(!@copy($tmp,$target) || !@unlink($tmp))throw new RuntimeException('Die geprüfte SQLite-Datenbankdatei konnte nicht in den Projektspeicher übernommen werden.');
        }
        $check=new EnterpriseSqlitePdo($target);
        $integrity2=strtolower(trim((string)$check->query('PRAGMA integrity_check')->fetchColumn()));
        if($integrity2!=='ok')throw new RuntimeException('Der wiederhergestellte SQLite-Projektspeicher ist beschädigt: '.$integrity2);
    }catch(Throwable $e){
        if(is_file($tmp))@unlink($tmp);
        if(!$exists && is_file($target))@unlink($target);
        throw new RuntimeException('Der SQLite-Projektspeicher konnte nicht aus der Sicherung wiederhergestellt werden: '.$e->getMessage(),0,$e);
    }

    return ['database_existed_before'=>$exists,'database_restored'=>true,'database_reused'=>false,'statements'=>0,'strategy'=>$strategy==='replace'?'replace':'restore'];
}


/** @return list<string> */
function enterprise_project_restore_oracle_statements(string $sql): array
{
    $out=[];$buf='';$single=false;$double=false;$len=strlen($sql);
    for($i=0;$i<$len;$i++){
        $c=$sql[$i];$n=$i+1<$len?$sql[$i+1]:'';
        if($single){$buf.=$c;if($c==="'"&&$n==="'"){$buf.=$n;$i++;continue;}if($c==="'")$single=false;continue;}
        if($double){$buf.=$c;if($c==='"'&&$n==='"'){$buf.=$n;$i++;continue;}if($c==='"')$double=false;continue;}
        if($c==="'"){$single=true;$buf.=$c;continue;}if($c==='"'){$double=true;$buf.=$c;continue;}
        if($c===';'){$stmt=trim($buf);if($stmt!==''&&!str_starts_with($stmt,'--'))$out[]=$stmt;$buf='';continue;}
        $buf.=$c;
    }
    $stmt=trim($buf);if($stmt!=='')$out[]=$stmt;return $out;
}
function enterprise_project_restore_oracle_store(array $env,string $sourceDatabase,string $targetDatabase,string $databaseSql,string $strategy,string $replaceConfirmation=''): array
{
    if(!extension_loaded('pdo_oci'))throw new RuntimeException('Für den Oracle-XE-Projekt-Restore wird pdo_oci benötigt.');
    if(!enterprise_project_restore_valid_database_name($targetDatabase))throw new RuntimeException('Ungültiger Oracle-Projektspeichername.');
    $pdo=enterprise_project_store_pdo($env,$targetDatabase,'oracle');
    $exists=enterprise_project_store_exists($env,$targetDatabase,'oracle');
    if($strategy==='replace'){
        if($replaceConfirmation!==$targetDatabase)throw new RuntimeException('Zum Ersetzen muss der Zielname exakt bestätigt werden.');
        $objects=$pdo->query("SELECT object_name,object_type FROM user_objects WHERE object_type IN ('VIEW','TABLE','SEQUENCE') ORDER BY CASE object_type WHEN 'VIEW' THEN 1 WHEN 'TABLE' THEN 2 ELSE 3 END")->fetchAll(PDO::FETCH_ASSOC);
        foreach($objects as $o){$name=(string)($o['OBJECT_NAME']??$o['object_name']??'');$type=strtoupper((string)($o['OBJECT_TYPE']??$o['object_type']??''));if($name==='')continue;$q='"'.str_replace('"','""',$name).'"';try{$pdo->exec('DROP '.$type.' '.$q.($type==='TABLE'?' CASCADE CONSTRAINTS PURGE':''));}catch(Throwable){}}
    } elseif($exists && $strategy==='restore') {
        $tables=(int)$pdo->query('SELECT COUNT(*) FROM user_tables')->fetchColumn();
        if($tables>0)throw new RuntimeException('Das Oracle-Zielschema ist nicht leer. Verwenden Sie Ersetzen oder ein leeres Schema.');
    }
    $count=0;foreach(enterprise_project_restore_oracle_statements($databaseSql) as $stmt){$pdo->exec($stmt);$count++;}
    return ['database_existed_before'=>$exists,'database_restored'=>true,'database_reused'=>$exists&&$strategy!=='replace','statements'=>$count,'strategy'=>$strategy];
}


function enterprise_project_restore_mssql_store(array $env,string $sourceDatabase,string $targetDatabase,string $databaseSql,string $strategy,string $replaceConfirmation=''): array
{
    if(!in_array('sqlsrv',PDO::getAvailableDrivers(),true))throw new RuntimeException('Für den MSSQL-Projekt-Restore wird pdo_sqlsrv benötigt.');
    $exists=enterprise_project_store_exists($env,$targetDatabase,'mssql');
    if($exists&&$strategy==='replace'){if($replaceConfirmation!==$targetDatabase)throw new RuntimeException('Bestätigung für MSSQL-Ersetzen stimmt nicht.');enterprise_project_store_delete($env,$targetDatabase,'mssql');$exists=false;}
    if($exists&&$strategy!=='replace')throw new RuntimeException('MSSQL-Zieldatenbank existiert bereits.');
    $pdo=enterprise_project_store_create($env,$targetDatabase,'mssql');
    $batches=preg_split('/^\s*GO\s*$/mi',$databaseSql)?:[];foreach($batches as $batch){$batch=trim($batch);if($batch!=='')$pdo->exec($batch);}return ['database_restored'=>true,'database_driver'=>'mssql','database_name'=>$targetDatabase];
}
// STAND 4 MSSQL restore archive contract: mssql-data/database.sql
