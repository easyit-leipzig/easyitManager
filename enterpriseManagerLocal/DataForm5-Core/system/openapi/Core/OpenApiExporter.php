<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Core;
use RuntimeException;
final class OpenApiExporter
{
    public function __construct(private readonly OpenApiGenerator $generator){}
    public function exportJson(string $file): string{$dir=dirname($file);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden.');$json=$this->generator->toJson().PHP_EOL;if(file_put_contents($file,$json,LOCK_EX)===false)throw new RuntimeException('OpenAPI-Datei konnte nicht geschrieben werden.');return $file;}
}
