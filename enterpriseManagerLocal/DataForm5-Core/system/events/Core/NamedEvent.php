<?php
declare(strict_types=1);
namespace DataForm5\Events\Core;

/**
 * Generisches Enterprise-Ereignis mit stabilem Namen und transportierbarer Nutzlast.
 * Die Nutzlast darf keine Geheimnisse (Passwörter, Tokens) enthalten.
 */
final class NamedEvent extends Event
{
    private string $occurredAt;

    public function __construct(
        private readonly string $name,
        private readonly array $payload = [],
        private readonly array $context = []
    ) {
        if ($name === '' || !preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Ungültiger Ereignisname: ' . $name);
        }
        $this->occurredAt = gmdate('c');
    }

    public function name(): string { return $this->name; }
    public function payload(): array { return $this->payload; }
    public function context(): array { return $this->context; }
    public function occurredAt(): string { return $this->occurredAt; }
}
