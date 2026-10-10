<?php
declare(strict_types=1);

namespace DataForm5\Core;

use DataForm5\Core\Configuration\ConfigurationException;

final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public static function loadDirectory(string $directory): self
    {
        $items = [];
        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $value = require $file;
            if (!is_array($value)) {
                throw new ConfigurationException("Konfigurationsdatei '{$file}' muss ein Array liefern.");
            }
            $items[pathinfo($file, PATHINFO_FILENAME)] = $value;
        }
        return new self($items);
    }

    public static function fromCache(string $file): self
    {
        if (!is_file($file)) {
            throw new ConfigurationException("Konfigurationscache '{$file}' wurde nicht gefunden.");
        }
        $items = require $file;
        if (!is_array($items)) {
            throw new ConfigurationException("Konfigurationscache '{$file}' ist ungültig.");
        }
        return new self($items);
    }

    public function cache(string $file): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ConfigurationException("Cacheverzeichnis '{$directory}' konnte nicht erstellt werden.");
        }
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($this->items, true) . ";\n";
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new ConfigurationException("Konfigurationscache '{$file}' konnte nicht geschrieben werden.");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function require(string $key): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);
        if ($value === $sentinel) {
            throw new ConfigurationException("Erforderlicher Konfigurationswert '{$key}' fehlt.");
        }
        return $value;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function set(string $key, mixed $newValue): void
    {
        $segments = explode('.', $key);
        $value =& $this->items;
        foreach ($segments as $segment) {
            if (!isset($value[$segment]) || !is_array($value[$segment])) {
                $value[$segment] = [];
            }
            $value =& $value[$segment];
        }
        $value = $newValue;
    }

    /** @param array<string, mixed> $items */
    public function merge(array $items): void
    {
        $this->items = array_replace_recursive($this->items, $items);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
