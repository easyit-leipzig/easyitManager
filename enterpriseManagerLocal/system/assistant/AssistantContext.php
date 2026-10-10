<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantContext implements \JsonSerializable
{
    public function __construct(
        private ?string $projectId = null,
        private ?string $dataFormId = null,
        private ?string $recordId = null,
        private ?string $route = null,
        private array $input = [],
        private array $meta = []
    ) {}

    public function getProjectId(): ?string { return $this->projectId; }
    public function getDataFormId(): ?string { return $this->dataFormId; }
    public function getRecordId(): ?string { return $this->recordId; }
    public function getRoute(): ?string { return $this->route; }
    public function getInput(): array { return $this->input; }
    public function getMeta(): array { return $this->meta; }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function jsonSerialize(): array
    {
        return [
            'projectId' => $this->projectId,
            'dataFormId' => $this->dataFormId,
            'recordId' => $this->recordId,
            'route' => $this->route,
            'input' => $this->input,
            'meta' => $this->meta,
        ];
    }
}
