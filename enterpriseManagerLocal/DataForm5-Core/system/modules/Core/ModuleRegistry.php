<?php
declare(strict_types=1);
namespace DataForm5\Modules\Core;
final class ModuleRegistry
{
    /** @var array<string,ModuleManifest> */ private array $manifests=[];
    /** @var array<string,object> */ private array $instances=[];
    public function add(ModuleManifest $manifest, object $instance): void { $this->manifests[$manifest->name]=$manifest; $this->instances[$manifest->name]=$instance; }
    public function has(string $name): bool { return isset($this->instances[$name]); }
    public function get(string $name): ?object { return $this->instances[$name] ?? null; }
    /** @return array<string,ModuleManifest> */ public function manifests(): array { return $this->manifests; }
    /** @return list<string> */ public function names(): array { return array_keys($this->instances); }
}
