<?php
declare(strict_types=1);

namespace DataForm5\Core\Configuration;

use RuntimeException;

final class Environment
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(private readonly string $basePath) {}

    public function load(string $filename = '.env', bool $override = false): self
    {
        $file = rtrim($this->basePath, '/\\') . '/' . ltrim($filename, '/\\');
        if (!is_file($file)) {
            return $this;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Umgebungsdatei '{$file}' konnte nicht gelesen werden.");
        }

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException('Ungültiger .env-Eintrag in Zeile ' . ($number + 1) . '.');
            }
            [$key, $rawValue] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                throw new RuntimeException("Ungültiger Variablenname '{$key}' in Zeile " . ($number + 1) . '.');
            }
            if (!$override && getenv($key) !== false) {
                $this->values[$key] = (string)getenv($key);
                continue;
            }
            $value = $this->parseValue($rawValue);
            $this->values[$key] = $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return getenv($key) !== false || array_key_exists($key, $this->values);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    private function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
            return $first === '"' ? stripcslashes($value) : $value;
        }
        $comment = strpos($value, ' #');
        return trim($comment === false ? $value : substr($value, 0, $comment));
    }
}
