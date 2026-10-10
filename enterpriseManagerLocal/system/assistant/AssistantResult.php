<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantResult implements \JsonSerializable
{
    /**
     * @param list<AssistantStep> $steps
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        private bool $ok,
        private string $assistantId,
        private ?string $currentStepId,
        private array $steps = [],
        private array $data = [],
        private array $errors = [],
        private array $warnings = []
    ) {}

    public static function success(string $assistantId, ?string $currentStepId, array $steps = [], array $data = [], array $warnings = []): self
    {
        return new self(true, $assistantId, $currentStepId, $steps, $data, [], $warnings);
    }

    public static function failure(string $assistantId, array $errors, ?string $currentStepId = null, array $steps = [], array $data = []): self
    {
        return new self(false, $assistantId, $currentStepId, $steps, $data, $errors, []);
    }

    public function isOk(): bool { return $this->ok; }
    public function getAssistantId(): string { return $this->assistantId; }
    public function getCurrentStepId(): ?string { return $this->currentStepId; }
    public function getSteps(): array { return $this->steps; }
    public function getData(): array { return $this->data; }
    public function getErrors(): array { return $this->errors; }
    public function getWarnings(): array { return $this->warnings; }

    public function jsonSerialize(): array
    {
        return [
            'ok' => $this->ok,
            'assistantId' => $this->assistantId,
            'currentStepId' => $this->currentStepId,
            'steps' => $this->steps,
            'data' => $this->data,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
