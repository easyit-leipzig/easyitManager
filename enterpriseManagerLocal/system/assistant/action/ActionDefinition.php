<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Action;

final class ActionDefinition implements \JsonSerializable
{
    /**
     * @param list<string> $aliases
     * @param list<string> $allowedModes
     */
    public function __construct(
        private string $id,
        private string $label,
        private string $buttonKey,
        private string $title,
        private string $ariaLabel,
        private bool $requiresRecord,
        private array $allowedModes = [],
        private array $aliases = []
    ) {}

    public function getId(): string { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function getButtonKey(): string { return $this->buttonKey; }
    public function getTitle(): string { return $this->title; }
    public function getAriaLabel(): string { return $this->ariaLabel; }
    public function requiresRecord(): bool { return $this->requiresRecord; }
    public function getAllowedModes(): array { return $this->allowedModes; }
    public function getAliases(): array { return $this->aliases; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'buttonKey' => $this->buttonKey,
            'title' => $this->title,
            'ariaLabel' => $this->ariaLabel,
            'requiresRecord' => $this->requiresRecord,
            'allowedModes' => $this->allowedModes,
            'aliases' => $this->aliases,
        ];
    }
}
