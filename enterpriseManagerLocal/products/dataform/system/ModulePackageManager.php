<?php
declare(strict_types=1);

final class ModulePackageManager
{
    public const MAX_BYTES = 5242880;

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_module_packages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(100) NOT NULL,
            version VARCHAR(50) NOT NULL,
            action_name VARCHAR(30) NOT NULL,
            package_hash CHAR(64) NOT NULL,
            manifest_json LONGTEXT NOT NULL,
            installed_by BIGINT UNSIGNED NULL,
            installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(30) NOT NULL DEFAULT 'installed',
            message TEXT NULL,
            KEY idx_df_module_package_key (module_key),
            KEY idx_df_module_package_time (installed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function inspectUpload(array $file, string $workspace): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip ist nicht aktiviert.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Das Modulpaket konnte nicht hochgeladen werden. Upload-Fehler: ' . (int)($file['error'] ?? -1));
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Das Modulpaket muss zwischen 1 Byte und 5 MB groß sein.');
        }
        $name = (string)($file['name'] ?? '');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Nur ZIP-Dateien sind als Modulpaket zulässig.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('Die Uploadquelle ist ungültig.');
        }
        if (!is_dir($workspace) && !mkdir($workspace, 0770, true) && !is_dir($workspace)) {
            throw new RuntimeException('Das Paket-Arbeitsverzeichnis konnte nicht angelegt werden.');
        }
        $token = bin2hex(random_bytes(16));
        $zipPath = rtrim($workspace, '/\\') . DIRECTORY_SEPARATOR . $token . '.zip';
        if (!move_uploaded_file($tmp, $zipPath)) {
            throw new RuntimeException('Das hochgeladene Paket konnte nicht sicher gespeichert werden.');
        }
        $extractPath = rtrim($workspace, '/\\') . DIRECTORY_SEPARATOR . $token;
        mkdir($extractPath, 0770, true);
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Das ZIP-Archiv ist beschädigt oder nicht lesbar.');
            }
            if ($zip->numFiles < 1 || $zip->numFiles > 500) {
                throw new RuntimeException('Das Paket enthält eine ungültige Anzahl Dateien.');
            }
            $total = 0;
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($entry === '' || str_starts_with($entry, '/') || preg_match('~(^|/)\.\.(/|$)~', $entry) || preg_match('~^[A-Za-z]:~', $entry)) {
                    throw new RuntimeException('Das Paket enthält einen unzulässigen Pfad.');
                }
                $mode = ((int)($stat['external_attributes'] ?? 0) >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new RuntimeException('Symbolische Links sind in Modulpaketen nicht zulässig.');
                }
                $total += (int)($stat['size'] ?? 0);
                if ($total > 20971520) {
                    throw new RuntimeException('Der entpackte Paketinhalt überschreitet 20 MB.');
                }
            }
            if (!$zip->extractTo($extractPath)) {
                throw new RuntimeException('Das Paket konnte nicht entpackt werden.');
            }
            $zip->close();
            $manifests = glob($extractPath . '/*/module.json') ?: [];
            if (count($manifests) !== 1) {
                throw new RuntimeException('Das Paket muss genau einen Modulordner mit module.json enthalten.');
            }
            $manifestPath = $manifests[0];
            $moduleDir = dirname($manifestPath);
            $manifestRaw = file_get_contents($manifestPath);
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (!is_array($manifest)) {
                throw new RuntimeException('module.json enthält kein gültiges JSON.');
            }
            $directory = basename($moduleDir);
            $key = strtolower(trim((string)($manifest['key'] ?? $directory)));
            if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $key)) {
                throw new RuntimeException('Der Modulschlüssel im Manifest ist ungültig.');
            }
            $version = trim((string)($manifest['version'] ?? ''));
            if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                throw new RuntimeException('Die Modulversion muss einer semantischen Version entsprechen, z. B. 1.2.0.');
            }
            $nameValue = trim((string)($manifest['name'] ?? ''));
            if ($nameValue === '') {
                throw new RuntimeException('Im Manifest fehlt der Modulname.');
            }
            $files = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (!$item->isFile()) continue;
                $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($moduleDir)+1));
                if (preg_match('/\.(php|json|md|txt|css|js|sql|svg|png|jpg|jpeg|webp)$/i', $rel) !== 1) {
                    throw new RuntimeException('Unzulässiger Dateityp im Paket: ' . $rel);
                }
                $files[$rel] = hash_file('sha256', $item->getPathname());
            }
            ksort($files);
            return [
                'token'=>$token, 'zip_path'=>$zipPath, 'extract_path'=>$extractPath,
                'module_dir'=>$moduleDir, 'directory'=>$directory, 'key'=>$key,
                'name'=>$nameValue, 'version'=>$version,
                'description'=>trim((string)($manifest['description'] ?? '')),
                'dependencies'=>array_values(array_filter(array_map('strval',(array)($manifest['dependencies'] ?? [])))),
                'required'=>(bool)($manifest['required'] ?? false),
                'manifest'=>$manifest, 'files'=>$files,
                'package_hash'=>hash_file('sha256',$zipPath)
            ];
        } catch (Throwable $e) {
            self::removeTree($extractPath);
            @unlink($zipPath);
            throw $e;
        }
    }

    public static function install(PDO $pdo, array $package, string $modulesPath, int $userId): string
    {
        self::ensureSchema($pdo);
        $target = rtrim($modulesPath, '/\\') . DIRECTORY_SEPARATOR . $package['directory'];
        $action = is_dir($target) ? 'update' : 'install';
        $backup = null;
        if ($action === 'update') {
            $currentManifest = $target . DIRECTORY_SEPARATOR . 'module.json';
            $current = is_file($currentManifest) ? json_decode((string)file_get_contents($currentManifest), true) : null;
            $currentKey = strtolower((string)($current['key'] ?? basename($target)));
            if ($currentKey !== $package['key']) {
                throw new RuntimeException('Der Zielordner gehört zu einem anderen Modul.');
            }
            $backup = dirname($modulesPath) . '/.module-backups/' . $package['key'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0770, true);
            if (!rename($target, $backup)) throw new RuntimeException('Das vorhandene Modul konnte nicht gesichert werden.');
        }
        try {
            if (!self::copyTree($package['module_dir'], $target)) {
                throw new RuntimeException('Das Modul konnte nicht in das Modulverzeichnis kopiert werden.');
            }
            $migrationDir = $target . '/migrations';
            if (is_dir($migrationDir)) {
                foreach (glob($migrationDir . '/*.sql') ?: [] as $migration) {
                    $sql = trim((string)file_get_contents($migration));
                    if ($sql !== '') $pdo->exec($sql);
                }
            }
            $stmt = $pdo->prepare('INSERT INTO dataform_module_packages (module_key,version,action_name,package_hash,manifest_json,installed_by,status,message) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$package['key'],$package['version'],$action,$package['package_hash'],json_encode($package['manifest'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,'installed',null]);
            if ($backup) self::removeTree($backup);
            self::cleanup($package);
            return $action;
        } catch (Throwable $e) {
            self::removeTree($target);
            if ($backup && is_dir($backup)) @rename($backup,$target);
            $stmt = $pdo->prepare('INSERT INTO dataform_module_packages (module_key,version,action_name,package_hash,manifest_json,installed_by,status,message) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$package['key'],$package['version'],$action,$package['package_hash'],json_encode($package['manifest'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,'failed',$e->getMessage()]);
            self::cleanup($package);
            throw $e;
        }
    }

    public static function history(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        return $pdo->query('SELECT * FROM dataform_module_packages ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function cleanup(array $package): void
    {
        self::removeTree((string)($package['extract_path'] ?? ''));
        @unlink((string)($package['zip_path'] ?? ''));
    }

    private static function copyTree(string $source, string $target): bool
    {
        if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) return false;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $dest = $target . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($item->isDir()) { if (!is_dir($dest) && !mkdir($dest,0770,true) && !is_dir($dest)) return false; }
            elseif (!copy($item->getPathname(),$dest)) return false;
        }
        return true;
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($path);
    }
}
