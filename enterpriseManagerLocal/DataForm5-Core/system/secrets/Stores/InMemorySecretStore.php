<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Stores;
use DataForm5\Secrets\Contracts\SecretStoreInterface;
use DataForm5\Secrets\Exceptions\SecretException;
final class InMemorySecretStore implements SecretStoreInterface
{
    /** @var array<string,string> */ private array $data=[];
    public function has(string $name):bool{return array_key_exists($this->name($name),$this->data);}
    public function get(string $name,?string $default=null):?string{return $this->data[$this->name($name)]??$default;}
    public function set(string $name,string $value):void{$this->data[$this->name($name)]=$value;}
    public function delete(string $name):void{unset($this->data[$this->name($name)]);}
    public function names():array{$n=array_keys($this->data);sort($n);return $n;}
    private function name(string $name):string{$name=trim($name);if(!preg_match('/^[A-Za-z0-9_.-]{1,160}$/',$name))throw new SecretException('Ungültiger Secret-Name.');return $name;}
}
