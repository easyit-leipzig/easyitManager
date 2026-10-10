<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Core;
use DataForm5\OpenApi\Contracts\OpenApiGeneratorInterface;
use DataForm5\OpenApi\Exceptions\OpenApiException;
final class OpenApiGenerator implements OpenApiGeneratorInterface
{
    private array $info=['title'=>'DataForm5 API','version'=>'1.0.0'];
    private array $servers=[];
    private array $schemas=[];
    private array $paths=[];
    public function info(string $title,string $version,string $description=''): self{$this->info=['title'=>$title,'version'=>$version];if($description!=='')$this->info['description']=$description;return $this;}
    public function server(string $url,string $description=''): self{$server=['url'=>$url];if($description!=='')$server['description']=$description;$this->servers[]=$server;return $this;}
    public function schema(string $name,array $schema): self{if(!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/',$name))throw new OpenApiException('Ungültiger Schemaname: '.$name);$this->schemas[$name]=$schema;return $this;}
    public function operation(string $method,string $path,array $operation): self{$method=strtolower($method);if(!in_array($method,['get','post','put','patch','delete','options','head'],true))throw new OpenApiException('Nicht unterstützte HTTP-Methode: '.$method);$path='/'.ltrim($path,'/');$operation['responses']=$operation['responses']??['200'=>['description'=>'Erfolgreich']];$this->paths[$path][$method]=$operation;return $this;}
    public function generate(): array{$doc=['openapi'=>'3.1.0','info'=>$this->info,'paths'=>$this->paths,'components'=>['schemas'=>$this->schemas]];if($this->servers!==[])$doc['servers']=$this->servers;return $doc;}
    public function toJson(int $flags=JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE): string{$json=json_encode($this->generate(),$flags);if($json===false)throw new OpenApiException('OpenAPI-Dokument konnte nicht serialisiert werden: '.json_last_error_msg());return $json;}
}
