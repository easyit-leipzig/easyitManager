<?php
declare(strict_types=1);
namespace DataForm5\Rules\Core;
final class FactContext
{
    public function __construct(private array $facts = []) {}
    public function all(): array { return $this->facts; }
    public function has(string $key): bool { return $this->read($key, $exists) !== null || $exists; }
    public function get(string $key, mixed $default = null): mixed { $value=$this->read($key,$exists); return $exists?$value:$default; }
    public function with(array $facts): self { return new self(array_replace_recursive($this->facts,$facts)); }
    private function read(string $key, ?bool &$exists): mixed
    {
        $value=$this->facts; $exists=true;
        foreach(explode('.',$key) as $segment){ if(!is_array($value)||!array_key_exists($segment,$value)){ $exists=false; return null;} $value=$value[$segment]; }
        return $value;
    }
}
