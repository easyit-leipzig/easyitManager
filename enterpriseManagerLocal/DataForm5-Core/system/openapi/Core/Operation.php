<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Core;
final class Operation
{
    public static function make(string $summary,array $responses,array $options=[]): array{return array_merge(['summary'=>$summary,'responses'=>$responses],$options);}
    public static function jsonRequest(array $schema,bool $required=true): array{return ['required'=>$required,'content'=>['application/json'=>['schema'=>$schema]]];}
    public static function jsonResponse(string $description,array $schema): array{return ['description'=>$description,'content'=>['application/json'=>['schema'=>$schema]]];}
    public static function pathParameter(string $name,array $schema,string $description=''): array{$p=['name'=>$name,'in'=>'path','required'=>true,'schema'=>$schema];if($description!=='')$p['description']=$description;return $p;}
}
