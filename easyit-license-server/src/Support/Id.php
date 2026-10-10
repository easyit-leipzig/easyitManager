<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Support;

final class Id
{
    public static function make(string $prefix): string
    {
        return strtoupper($prefix) . '-' . bin2hex(random_bytes(16));
    }
}
