<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantStep implements \JsonSerializable
{
    public const STATE_PENDING = 'pending';
    public const STATE_CURRENT = 'current';
    public const STATE_COMPLETE = 'complete';
    public const STATE_BLOCKED = 'blocked';

    public function __construct(
        private string $id,
        private string $title,
        private string $description = '',
        private array $payload = [],
        private string $state = self::STATE_PENDING
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('AssistantStep id must not be empty.');
        }
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getPayload(): array { return $this->payload; }
    public function getState(): string { return $this->state; }

    public function withState(string $state): self
    {
        $clone = clone $this;
        $clone->state = $state;
        return $clone;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'payload' => $this->payload,
            'state' => $this->state,
        ];
    }
}
