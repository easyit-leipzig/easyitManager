<?php
declare(strict_types=1);
namespace DataForm5\Observability\Core;
use DataForm5\Observability\Exceptions\ObservabilityException;
final class MetricKey
{
    public static function normalizeName(string $name): string
    {
        $name=trim($name);
        if($name===''||!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]*$/',$name)) throw new ObservabilityException("Ungültiger Metrikname '{$name}'.");
        return $name;
    }
    public static function normalizeTags(array $tags): array
    {
        $normalized=[];
        foreach($tags as $key=>$value){$key=trim((string)$key);if($key===''||!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]*$/',$key))throw new ObservabilityException("Ungültiger Tagname '{$key}'.");if(!is_scalar($value)&&$value!==null)throw new ObservabilityException("Tag '{$key}' muss skalar sein.");$normalized[$key]=$value===null?'null':(string)$value;}
        ksort($normalized);return $normalized;
    }
    public static function id(string $name,array $tags=[]):string{return hash('sha256',self::normalizeName($name).'|'.json_encode(self::normalizeTags($tags),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
}
