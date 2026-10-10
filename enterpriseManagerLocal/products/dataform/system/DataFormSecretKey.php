<?php
declare(strict_types=1);

final class DataFormSecretKey
{
    public static function ensure(string $envPath): array
    {
        $values = self::readEnv($envPath);

        $dedicated = trim((string)($values['DATAFORM_APP_KEY'] ?? ''));
        if ($dedicated !== '') {
            return ['key'=>$dedicated,'source'=>'DATAFORM_APP_KEY','created'=>false];
        }

        $generic = trim((string)($values['APP_KEY'] ?? ''));
        if ($generic !== '') {
            return ['key'=>$generic,'source'=>'APP_KEY','created'=>false];
        }

        if (!is_file($envPath)) {
            throw new RuntimeException('DataForm5-Core/.env fehlt.');
        }
        if (!is_readable($envPath) || !is_writable($envPath)) {
            throw new RuntimeException('DataForm5-Core/.env ist nicht les- und beschreibbar.');
        }

        $key = self::generate();
        self::writeValue($envPath, 'DATAFORM_APP_KEY', $key);

        $verify = self::readEnv($envPath);
        if (!hash_equals($key, (string)($verify['DATAFORM_APP_KEY'] ?? ''))) {
            throw new RuntimeException('Der automatisch erzeugte DataForm-Secret-Schlüssel konnte nicht verifiziert werden.');
        }

        return ['key'=>$key,'source'=>'DATAFORM_APP_KEY','created'=>true];
    }

    public static function generate(): string
    {
        return 'dfk1_' . rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '='
        );
    }

    private static function readEnv(string $path): array
    {
        $values = [];
        if (!is_file($path)) return $values;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key,$value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                strlen($value) >= 2
                && (
                    ($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = str_replace(['\\"','\\\\'], ['"','\\'], $value);
        }

        return $values;
    }

    private static function writeValue(string $path, string $name, string $value): void
    {
        $content = (string)file_get_contents($path);
        $line = $name . '=' . str_replace(["\r","\n"], '', $value);
        $pattern = '/^' . preg_quote($name, '/') . '=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            $content = preg_replace($pattern, $line, $content) ?? $content;
        } else {
            if ($content !== '' && !str_ends_with($content, "\n")) $content .= PHP_EOL;
            $content .= $line . PHP_EOL;
        }

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('DataForm5-Core/.env konnte nicht aktualisiert werden.');
        }
        @chmod($path, 0600);
    }
}
