<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantRegistry
{
    /** @var array<string, AssistantInterface> */
    private array $assistants = [];

    public function register(AssistantInterface $assistant): void
    {
        $id = trim($assistant->getId());
        if ($id === '') {
            throw new \InvalidArgumentException('Assistant id must not be empty.');
        }
        if (isset($this->assistants[$id])) {
            throw new \LogicException('Assistant already registered: ' . $id);
        }
        $this->assistants[$id] = $assistant;
    }

    public function has(string $id): bool
    {
        return isset($this->assistants[$id]);
    }

    public function get(string $id): AssistantInterface
    {
        if (!$this->has($id)) {
            throw new \OutOfBoundsException('Unknown assistant: ' . $id);
        }
        return $this->assistants[$id];
    }

    /** @return array<string, AssistantInterface> */
    public function all(): array
    {
        return $this->assistants;
    }
}
