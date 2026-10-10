<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Stores;
use DataForm5\Secrets\Contracts\{EncrypterInterface,SecretStoreInterface};
use DataForm5\Secrets\Exceptions\SecretException;
final class EncryptedFileSecretStore implements SecretStoreInterface
{
    public function __construct(private readonly string $file,private readonly EncrypterInterface $encrypter){}
    public function has(string $name):bool{return array_key_exists($this->name($name),$this->read());}
    public function get(string $name,?string $default=null):?string{$data=$this->read();$name=$this->name($name);return isset($data[$name])?$this->encrypter->decrypt($data[$name]):$default;}
    public function set(string $name,string $value):void{$data=$this->read();$data[$this->name($name)]=$this->encrypter->encrypt($value);$this->write($data);}
    public function delete(string $name):void{$data=$this->read();unset($data[$this->name($name)]);$this->write($data);}
    public function names():array{$n=array_keys($this->read());sort($n);return $n;}
    /** Re-encrypt every entry with the currently active key. */ public function rotate():void{$plain=[];foreach($this->names() as $name)$plain[$name]=(string)$this->get($name);$data=[];foreach($plain as $name=>$value)$data[$name]=$this->encrypter->encrypt($value);$this->write($data);}
    /** @return array<string,string> */ private function read():array{if(!is_file($this->file))return[];$raw=file_get_contents($this->file);if($raw===false)throw new SecretException('Secret-Datei kann nicht gelesen werden.');$data=json_decode($raw,true);if(!is_array($data))throw new SecretException('Secret-Datei ist beschädigt.');return array_map('strval',$data);}
    /** @param array<string,string> $data */ private function write(array $data):void{$dir=dirname($this->file);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new SecretException('Secret-Verzeichnis kann nicht erstellt werden.');$tmp=$this->file.'.'.bin2hex(random_bytes(6)).'.tmp';$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new SecretException('Secret-Datei kann nicht geschrieben werden.');@chmod($tmp,0600);if(!rename($tmp,$this->file)){@unlink($tmp);throw new SecretException('Secret-Datei kann nicht atomar aktiviert werden.');}}
    private function name(string $name):string{$name=trim($name);if(!preg_match('/^[A-Za-z0-9_.-]{1,160}$/',$name))throw new SecretException('Ungültiger Secret-Name.');return $name;}
}
