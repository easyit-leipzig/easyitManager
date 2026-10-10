<?php
declare(strict_types=1);
namespace DataForm5\Modules\Core;
final class HookDispatcher
{
    /** @var array<string,list<callable>> */ private array $listeners=[];
    public function listen(string $hook, callable $listener): void { $this->listeners[$hook][]=$listener; }
    /** @return list<mixed> */ public function dispatch(string $hook, mixed ...$arguments): array { $out=[]; foreach($this->listeners[$hook] ?? [] as $listener) $out[]=$listener(...$arguments); return $out; }
}
