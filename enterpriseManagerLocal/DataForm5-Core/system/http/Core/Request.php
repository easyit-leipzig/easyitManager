<?php
declare(strict_types=1);
namespace DataForm5\Http\Core;

use DataForm5\Validation\Core\Validator;

final class Request {
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,mixed> $server
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $files
     * @param array<string,mixed>|null $json
     */
    public function __construct(
        private string $method,
        private string $uri,
        private array $query=[],
        private array $body=[],
        private array $headers=[],
        private array $server=[],
        private array $attributes=[],
        private array $files=[],
        private ?array $json=null
    ) {
        $this->method=strtoupper($method);
        $this->uri='/' . ltrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        $normalized=[];
        foreach($this->headers as $name=>$value) $normalized[strtolower((string)$name)]=(string)$value;
        $this->headers=$normalized;
    }

    public static function capture(): self {
        $headers=[];
        foreach ($_SERVER as $k=>$v) {
            if (str_starts_with($k,'HTTP_')) $headers[strtolower(str_replace('_','-',substr($k,5)))]=(string)$v;
        }
        if(isset($_SERVER['CONTENT_TYPE'])) $headers['content-type']=(string)$_SERVER['CONTENT_TYPE'];
        if(isset($_SERVER['CONTENT_LENGTH'])) $headers['content-length']=(string)$_SERVER['CONTENT_LENGTH'];
        $raw=(string)file_get_contents('php://input');
        $json=null;
        if(str_contains(strtolower($headers['content-type']??''),'application/json') && $raw!=='') {
            $decoded=json_decode($raw,true);
            if(is_array($decoded)) $json=$decoded;
        }
        $body=$json ?? $_POST;
        return new self(
            (string)($_SERVER['REQUEST_METHOD']??'GET'),
            (string)($_SERVER['REQUEST_URI']??'/'),
            $_GET,
            $body,
            $headers,
            $_SERVER,
            [],
            $_FILES,
            $json
        );
    }

    /** @param array{get?:array<string,mixed>,post?:array<string,mixed>,files?:array<string,mixed>,headers?:array<string,string>,server?:array<string,mixed>,json?:array<string,mixed>|null} $legacy */
    public static function fromLegacy(string $method,array $legacy,string $uri='/'): self {
        $json=isset($legacy['json']) && is_array($legacy['json']) ? $legacy['json'] : null;
        return new self(
            $method,
            $uri,
            is_array($legacy['get']??null)?$legacy['get']:[],
            $json ?? (is_array($legacy['post']??null)?$legacy['post']:[]),
            is_array($legacy['headers']??null)?$legacy['headers']:[],
            is_array($legacy['server']??null)?$legacy['server']:[],
            [],
            is_array($legacy['files']??null)?$legacy['files']:[],
            $json
        );
    }

    public function method(): string{return $this->method;}
    public function uri(): string{return $this->uri;}
    public function query(?string $key=null,mixed $default=null):mixed{return $key===null?$this->query:($this->query[$key]??$default);}
    public function body(?string $key=null,mixed $default=null):mixed{return $key===null?$this->body:($this->body[$key]??$default);}
    public function input(?string $key=null,mixed $default=null):mixed{$all=array_replace($this->query,$this->body);return $key===null?$all:($all[$key]??$default);}
    public function only(array $keys):array{$all=(array)$this->input();$out=[];foreach($keys as $key)if(array_key_exists((string)$key,$all))$out[(string)$key]=$all[(string)$key];return $out;}
    public function has(string $key):bool{$all=(array)$this->input();return array_key_exists($key,$all);}
    public function filled(string $key):bool{$v=$this->input($key);return $v!==null && $v!=='' && (!is_array($v)||$v!==[]);}
    public function file(?string $key=null,mixed $default=null):mixed{return $key===null?$this->files:($this->files[$key]??$default);}
    public function json(?string $key=null,mixed $default=null):mixed{return $key===null?$this->json:($this->json[$key]??$default);}
    public function isJson():bool{return $this->json!==null || str_contains(strtolower((string)$this->header('content-type','')),'application/json');}
    public function expectsJson():bool{return $this->isJson() || str_contains(strtolower((string)$this->header('accept','')),'application/json');}
    public function header(string $name,?string $default=null):?string{return $this->headers[strtolower($name)]??$default;}
    public function server(?string $key=null,mixed $default=null):mixed{return $key===null?$this->server:($this->server[$key]??$default);}
    public function attribute(string $key,mixed $default=null):mixed{return $this->attributes[$key]??$default;}
    public function withAttribute(string $key,mixed $value):self{$c=clone $this;$c->attributes[$key]=$value;return $c;}
    public function allAttributes():array{return $this->attributes;}
    public function withUser(array $user):self{return $this->withAttribute('user',$user);}
    public function user():?array{$user=$this->attribute('user');return is_array($user)?$user:null;}
    public function withRoute(array $route):self{return $this->withAttribute('route',$route);}
    public function route():?array{$route=$this->attribute('route');return is_array($route)?$route:null;}
    /** @return array{get:array<string,mixed>,post:array<string,mixed>,files:array<string,mixed>,headers:array<string,string>,server:array<string,mixed>,json:?array} */
    public function toLegacyArray():array{return ['get'=>$this->query,'post'=>$this->body,'files'=>$this->files,'headers'=>$this->headers,'server'=>$this->server,'json'=>$this->json];}

    /** @return array<string,mixed> */
    public function validate(Validator $validator,array $rules,array $messages=[]):array {
        return $validator->validate((array)$this->input(),$rules,$messages)->validated();
    }
}
