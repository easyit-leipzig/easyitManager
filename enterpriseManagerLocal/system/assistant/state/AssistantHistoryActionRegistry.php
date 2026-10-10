<?php
declare(strict_types=1);

namespace EasyIT\Assistant\State;

final class AssistantHistoryActionRegistry implements \JsonSerializable
{
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [
        'undo' => ['id' => 'undo', 'buttonKey' => 'undo', 'title' => 'Letzte Assistentenänderung rückgängig machen', 'ariaLabel' => 'Letzte Assistentenänderung rückgängig machen'],
        'redo' => ['id' => 'redo', 'buttonKey' => 'redo', 'title' => 'Rückgängig gemachte Assistentenänderung wiederholen', 'ariaLabel' => 'Rückgängig gemachte Assistentenänderung wiederholen'],
        'restore' => ['id' => 'restore', 'buttonKey' => 'restore', 'title' => 'Ausgewählten Assistentenzustand wiederherstellen', 'ariaLabel' => 'Ausgewählten Assistentenzustand wiederherstellen'],
        'compare' => ['id' => 'compare', 'buttonKey' => 'compare', 'title' => 'Zwei Assistentenzustände vergleichen', 'ariaLabel' => 'Zwei Assistentenzustände vergleichen'],
    ];

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        return $this->definitions[$id] ?? [];
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 'easyit.assistant.history-action-registry.v1',
            'registry' => 'central',
            'assetRoot' => 'assets/img/',
            'localTitlesAllowed' => false,
            'localAriaLabelsAllowed' => false,
            'cssBackgroundButtonsAllowed' => false,
            'definitions' => $this->definitions,
        ];
    }
}
