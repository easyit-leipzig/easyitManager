<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Action;

final class ActionDraftValidator
{
    public function __construct(private DataFormActionRegistry $registry) {}

    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(ActionDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];

        if ($stepId === null || in_array($stepId, ['context', 'review'], true)) {
            if (trim((string) ($d['dataForm']['name'] ?? '')) === '') {
                $errors[] = 'DataForm-Name fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['actions', 'review'], true)) {
            $enabled = 0;
            foreach (($d['actions'] ?? []) as $id => $active) {
                if (!$this->registry->has((string) $id)) {
                    $errors[] = 'Nicht registrierte DataForm-Aktion: ' . $id;
                    continue;
                }
                if ($active) { $enabled++; }
            }
            if ($enabled === 0) {
                $warnings[] = 'Es ist keine DataForm-Aktion aktiviert.';
            }
        }

        $ui = $d['ui'] ?? [];
        if (($ui['buttonRegistry'] ?? '') !== 'central') {
            $errors[] = 'Aktionsbuttons müssen die zentrale Button-Registry verwenden.';
        }
        if (($ui['localTitlesAllowed'] ?? true) !== false || ($ui['localAriaLabelsAllowed'] ?? true) !== false) {
            $errors[] = 'title und aria-label dürfen nicht lokal überschrieben werden.';
        }
        if (($ui['cssBackgroundButtonsAllowed'] ?? true) !== false) {
            $errors[] = 'Farbige oder verlaufende CSS-Hintergründe für Aktionsbuttons sind nicht erlaubt.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
