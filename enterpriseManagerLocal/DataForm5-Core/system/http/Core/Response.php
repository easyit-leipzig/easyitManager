<?php
declare(strict_types=1);
namespace DataForm5\Http\Core;
final class Response {
    /** @param array<string,string> $headers @param array<string,mixed> $meta */
    public function __construct(private string $content='', private int $status=200, private array $headers=[], private array $meta=[]) {}
    public static function text(string $content,int $status=200,array $headers=[]):self{return new self($content,$status,['content-type'=>'text/plain; charset=UTF-8']+$headers);}
    public static function html(string $content,int $status=200,array $headers=[]):self{return new self($content,$status,['content-type'=>'text/html; charset=UTF-8']+$headers);}
    public static function page(string $content,string $title='Modul',int $status=200,array $headers=[]):self{return new self($content,$status,['content-type'=>'text/html; charset=UTF-8']+$headers,['page'=>true,'title'=>$title]);}
    public static function json(mixed $data,int $status=200,array $headers=[]):self{$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return new self($json,$status,['content-type'=>'application/json; charset=UTF-8']+$headers);}
    public static function redirect(string $url,int $status=302):self{return new self('', $status, ['location'=>$url]);}
    public static function noContent(int $status=204):self{return new self('', $status);}
    public function content():string{return $this->content;}
    public function status():int{return $this->status;}
    public function headers():array{return $this->headers;}
    public function meta(?string $key=null,mixed $default=null):mixed{return $key===null?$this->meta:($this->meta[$key]??$default);}
    public function isPage():bool{return ($this->meta['page']??false)===true;}
    public function withHeader(string $name,string $value):self{$c=clone $this;$c->headers[strtolower($name)]=$value;return $c;}
    public function withStatus(int $status):self{$c=clone $this;$c->status=$status;return $c;}
    public function send():void{if(!headers_sent()){http_response_code($this->status);foreach($this->headers as $n=>$v)header($n.': '.$v);}echo $this->content;}
}
