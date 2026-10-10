<?php
declare(strict_types=1);

/** Persistent license-server configuration for upgrade-safe installations. */
final class EnterpriseLicenseConfigStore
{
    public static function persistentPath(string $root): string
    {
        return rtrim($root, '/\\') . '/storage/system/config/licensing.php';
    }

    public static function legacyPath(string $root): string
    {
        return rtrim($root, '/\\') . '/config/licensing.php';
    }

    public static function load(string $root): array
    {
        $persistent = self::persistentPath($root);
        $legacy = self::legacyPath($root);
        if (is_file($persistent)) {
            $cfg = require $persistent;
            return is_array($cfg) ? $cfg : [];
        }
        if (is_file($legacy)) {
            $cfg = require $legacy;
            if (is_array($cfg) && $cfg !== []) {
                self::save($root, $cfg); // one-time migration away from replaceable application files
                return $cfg;
            }
        }
        return [];
    }

    public static function save(string $root, array $cfg): void
    {
        $file = self::persistentPath($root);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Persistentes Konfigurationsverzeichnis konnte nicht angelegt werden.');
        }
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Lizenzkonfiguration konnte nicht persistent gespeichert werden.');
        }
        @chmod($file, 0600);
    }
}
