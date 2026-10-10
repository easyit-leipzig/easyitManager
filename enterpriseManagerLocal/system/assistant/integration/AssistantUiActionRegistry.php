<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

final class AssistantUiActionRegistry
{
    /** @var array<string,array{buttonKey:string,title:string,ariaLabel:string}> */
    private array $actions = [
        'center' => ['buttonKey' => 'home', 'title' => 'Assistentenzentrale öffnen', 'ariaLabel' => 'Assistentenzentrale öffnen'],
        'contextual' => ['buttonKey' => 'show', 'title' => 'Passende Assistenten für den aktuellen Kontext anzeigen', 'ariaLabel' => 'Passende Assistenten für den aktuellen Kontext anzeigen'],
        'open' => ['buttonKey' => 'show', 'title' => 'Assistent öffnen', 'ariaLabel' => 'Assistent öffnen'],
        'history' => ['buttonKey' => 'history', 'title' => 'Verlauf, Undo und Redo öffnen', 'ariaLabel' => 'Verlauf, Undo und Redo öffnen'],
        'workflow' => ['buttonKey' => 'current', 'title' => 'Zum Workflow-Fortschritt zurückkehren', 'ariaLabel' => 'Zum Workflow-Fortschritt zurückkehren'],
        'back' => ['buttonKey' => 'back', 'title' => 'Zur vorherigen Assistentenansicht zurückkehren', 'ariaLabel' => 'Zur vorherigen Assistentenansicht zurückkehren'],
    ];

    /** @return array{buttonKey:string,title:string,ariaLabel:string} */
    public function get(string $id): array
    {
        if (!isset($this->actions[$id])) {
            throw new \OutOfBoundsException('Unbekannte Assistenten-UI-Aktion: ' . $id);
        }
        return $this->actions[$id];
    }
}
