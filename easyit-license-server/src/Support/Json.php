<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Support;

final class Json
{
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function decode(string $value): array
    {
        $d=json_decode($value,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($d)) throw new \RuntimeException('JSON object expected.');
        return $d;
    }
}
