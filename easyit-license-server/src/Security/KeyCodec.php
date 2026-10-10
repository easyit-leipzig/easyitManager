<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Security;

final class KeyCodec
{
    public static function encode(string $raw): string { return base64_encode($raw); }
    public static function decode(string $encoded): string
    {
        $raw=base64_decode(trim($encoded),true);
        if($raw===false) throw new \RuntimeException('Invalid base64 key.');
        return $raw;
    }
}
