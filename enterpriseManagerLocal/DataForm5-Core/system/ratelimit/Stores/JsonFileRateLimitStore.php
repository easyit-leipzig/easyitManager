<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Stores;
use DataForm5\RateLimit\Contracts\RateLimitStoreInterface;
use DataForm5\RateLimit\Exceptions\RateLimitException;
final class JsonFileRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private readonly string $directory){}
    public function read(string $key):?array{$file=$this->file($key);if(!is_file($file))return null;$data=json_decode((string)file_get_contents($file),true);return is_array($data)?$data:null;}
    public function write(string $key,array $state):void{$this->ensure();$file=$this->file($key);$tmp=$file.'.tmp.'.bin2hex(random_bytes(4));$json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RateLimitException('Rate-Limit-Datei konnte nicht geschrieben werden.');if(!rename($tmp,$file)){@unlink($tmp);throw new RateLimitException('Rate-Limit-Datei konnte nicht atomar aktiviert werden.');}}
    public function delete(string $key):void{$file=$this->file($key);if(is_file($file))@unlink($file);}
    public function healthy():bool{$dir=is_dir($this->directory)?$this->directory:dirname($this->directory);return is_dir($dir)&&is_writable($dir);}
    private function ensure():void{if(!is_dir($this->directory)&&!mkdir($this->directory,0775,true)&&!is_dir($this->directory))throw new RateLimitException('Rate-Limit-Verzeichnis konnte nicht erstellt werden.');}
    private function file(string $key):string{return rtrim($this->directory,'/\\').DIRECTORY_SEPARATOR.$key.'.json';}
}
