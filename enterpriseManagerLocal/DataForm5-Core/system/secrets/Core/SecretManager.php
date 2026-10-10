<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Core;
use DataForm5\Secrets\Contracts\SecretStoreInterface;
use DataForm5\Secrets\Exceptions\SecretException;
use DataForm5\Secrets\Stores\EncryptedFileSecretStore;
final class SecretManager
{
    public function __construct(private readonly SecretStoreInterface $store){}
    public function has(string $name):bool{return $this->store->has($name);}
    public function get(string $name,?string $default=null):?string{return $this->store->get($name,$default);}
    public function require(string $name):string{$value=$this->get($name);if($value===null)throw new SecretException("Erforderliches Secret '{$name}' fehlt.");return $value;}
    public function set(string $name,string $value):void{$this->store->set($name,$value);}
    public function delete(string $name):void{$this->store->delete($name);}
    public function names():array{return $this->store->names();}
    public function rotate():void{if(!$this->store instanceof EncryptedFileSecretStore)throw new SecretException('Der aktive Secret-Store unterstützt keine Schlüsselrotation.');$this->store->rotate();}
}
