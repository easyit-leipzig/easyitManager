<?php
declare(strict_types=1);
namespace DataForm5\Events\Core;

final class EventCatalog
{
    /** @var array<string,array{description:string,producer:string,since:string}> */
    private array $events = [];

    public function register(string $name, string $description, string $producer = 'enterprise', string $since = 'RC1.7.0'): void
    {
        if ($name === '' || !preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Ungültiger Ereignisname: ' . $name);
        }
        $this->events[$name] = ['description'=>$description,'producer'=>$producer,'since'=>$since];
    }

    public function has(string $name): bool { return isset($this->events[$name]); }
    public function all(): array { ksort($this->events); return $this->events; }
}
