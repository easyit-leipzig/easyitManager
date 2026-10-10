<?php
declare(strict_types=1);

namespace DataForm5\Core\Configuration;

final class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return self::cast($value);
    }

    public static function cast(string $value): mixed
    {
        return match (strtolower(trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => self::castNumeric($value),
        };
    }

    private static function castNumeric(string $value): mixed
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        return $value;
    }
}
