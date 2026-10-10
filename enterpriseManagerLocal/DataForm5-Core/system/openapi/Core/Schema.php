<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Core;
final class Schema
{
    public static function object(array $properties,array $required=[]): array{$schema=['type'=>'object','properties'=>$properties];if($required!==[])$schema['required']=array_values($required);return $schema;}
    public static function string(?string $format=null,?string $description=null): array{$s=['type'=>'string'];if($format)$s['format']=$format;if($description)$s['description']=$description;return $s;}
    public static function integer(?string $format=null): array{$s=['type'=>'integer'];if($format)$s['format']=$format;return $s;}
    public static function boolean(): array{return ['type'=>'boolean'];}
    public static function array(array $items): array{return ['type'=>'array','items'=>$items];}
    public static function ref(string $name): array{return ['$ref'=>'#/components/schemas/'.$name];}
}
