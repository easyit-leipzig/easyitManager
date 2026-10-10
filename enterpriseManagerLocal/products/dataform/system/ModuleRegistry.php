<?php
declare(strict_types=1);

final class ModuleRegistry
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_modules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(100) NOT NULL,
            name VARCHAR(190) NOT NULL,
            version VARCHAR(50) NOT NULL,
            description TEXT NULL,
            category VARCHAR(80) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            manifest_hash CHAR(64) NOT NULL,
            configuration_json LONGTEXT NULL,
            installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dataform_module_key (module_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function discover(string $modulesPath): array
    {
        $modules = [];
        foreach (glob(rtrim($modulesPath, '/\\') . '/*/module.json') ?: [] as $manifestPath) {
            $directory = basename(dirname($manifestPath));
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,79}$/', $directory)) {
                continue;
            }
            $raw = file_get_contents($manifestPath);
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest)) {
                continue;
            }
            $key = strtolower((string)($manifest['key'] ?? $directory));
            $key = preg_replace('/[^a-z0-9_-]+/', '-', $key) ?: '';
            if ($key === '') {
                continue;
            }
            $modules[$key] = [
                'key' => $key,
                'directory' => $directory,
                'name' => trim((string)($manifest['name'] ?? $directory)) ?: $directory,
                'version' => trim((string)($manifest['version'] ?? '0.0.0')) ?: '0.0.0',
                'description' => trim((string)($manifest['description'] ?? '')),
                'category' => trim((string)($manifest['category'] ?? 'Allgemein')) ?: 'Allgemein',
                'required' => (bool)($manifest['required'] ?? false),
                'default_enabled' => (bool)($manifest['enabled'] ?? true),
                'dependencies' => array_values(array_filter(array_map('strval', (array)($manifest['dependencies'] ?? [])))),
                'manifest_hash' => hash('sha256', (string)$raw),
                'manifest_path' => $manifestPath,
            ];
        }
        ksort($modules, SORT_NATURAL | SORT_FLAG_CASE);
        return $modules;
    }

    public static function synchronize(PDO $pdo, array $discovered): void
    {
        self::ensureSchema($pdo);
        $select = $pdo->prepare('SELECT id,is_enabled FROM dataform_modules WHERE module_key=? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO dataform_modules (module_key,name,version,description,category,is_required,is_enabled,manifest_hash,configuration_json) VALUES (?,?,?,?,?,?,?,?,?)');
        $update = $pdo->prepare('UPDATE dataform_modules SET name=?,version=?,description=?,category=?,is_required=?,manifest_hash=? WHERE module_key=?');
        foreach ($discovered as $module) {
            $select->execute([$module['key']]);
            $existing = $select->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $update->execute([$module['name'],$module['version'],$module['description'] ?: null,$module['category'],$module['required'] ? 1 : 0,$module['manifest_hash'],$module['key']]);
            } else {
                $insert->execute([$module['key'],$module['name'],$module['version'],$module['description'] ?: null,$module['category'],$module['required'] ? 1 : 0,($module['required'] || $module['default_enabled']) ? 1 : 0,$module['manifest_hash'],json_encode(['dependencies'=>$module['dependencies']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }
        }
    }

    public static function states(PDO $pdo, array $discovered): array
    {
        self::synchronize($pdo, $discovered);
        $rows = $pdo->query('SELECT * FROM dataform_modules ORDER BY category,name')->fetchAll(PDO::FETCH_ASSOC);
        $states = [];
        foreach ($rows as $row) {
            $key = (string)$row['module_key'];
            if (!isset($discovered[$key])) {
                continue;
            }
            $states[$key] = array_merge($discovered[$key], $row, [
                'enabled' => (int)$row['is_enabled'] === 1,
                'required' => (int)$row['is_required'] === 1,
            ]);
        }
        return $states;
    }

    public static function setEnabled(PDO $pdo, string $key, bool $enabled, array $states): void
    {
        if (!isset($states[$key])) {
            throw new RuntimeException('Das ausgewählte Modul wurde nicht gefunden.');
        }
        $module = $states[$key];
        if ($module['required'] && !$enabled) {
            throw new RuntimeException('Das Pflichtmodul „' . $module['name'] . '“ kann nicht deaktiviert werden.');
        }
        if ($enabled) {
            foreach ($module['dependencies'] as $dependency) {
                $dependency = strtolower((string)$dependency);
                if (!isset($states[$dependency]) || !$states[$dependency]['enabled']) {
                    throw new RuntimeException('Vorher muss das abhängige Modul „' . $dependency . '“ aktiviert werden.');
                }
            }
        } else {
            foreach ($states as $candidate) {
                if ($candidate['enabled'] && in_array($key, array_map('strtolower', $candidate['dependencies']), true)) {
                    throw new RuntimeException('Das Modul wird von „' . $candidate['name'] . '“ benötigt und kann nicht deaktiviert werden.');
                }
            }
        }
        $stmt = $pdo->prepare('UPDATE dataform_modules SET is_enabled=? WHERE module_key=?');
        $stmt->execute([$enabled ? 1 : 0, $key]);
    }

    public static function isEnabled(PDO $pdo, string $key, string $modulesPath): bool
    {
        $discovered = self::discover($modulesPath);
        $states = self::states($pdo, $discovered);
        return isset($states[$key]) && $states[$key]['enabled'];
    }
}
