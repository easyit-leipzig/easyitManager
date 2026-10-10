<?php
declare(strict_types=1);
namespace DataForm5\Validation\Core;
final class ErrorBag
{
    /** @var array<string,list<string>> */
    private array $messages = [];
    public function add(string $field, string $message): void { $this->messages[$field][] = $message; }
    public function has(string $field): bool { return isset($this->messages[$field]); }
    public function first(?string $field = null): ?string
    {
        if ($field !== null) return $this->messages[$field][0] ?? null;
        foreach ($this->messages as $messages) if ($messages !== []) return $messages[0];
        return null;
    }
    /** @return list<string> */
    public function get(string $field): array { return $this->messages[$field] ?? []; }
    /** @return array<string,list<string>> */
    public function all(): array { return $this->messages; }
    public function any(): bool { return $this->messages !== []; }
    public function isEmpty(): bool { return $this->messages === []; }
}
