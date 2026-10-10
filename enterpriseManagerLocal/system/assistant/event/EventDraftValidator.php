<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Event;

final class EventDraftValidator
{
    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(EventDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];

        if ($stepId === null || in_array($stepId, ['context', 'review'], true)) {
            $name = trim((string) ($d['context']['objectName'] ?? ''));
            if ($name === '') {
                $errors[] = 'Der Name des JavaScript-Übergabeobjekts fehlt.';
            } elseif (!preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name)) {
                $errors[] = 'Der Objektname muss ein gültiger JavaScript-Bezeichner sein.';
            }
            if (($d['context']['schema'] ?? '') !== 'easyit.dataform.action-context.v1') {
                $errors[] = 'Das DataForm-Übergabeobjekt muss das Schema easyit.dataform.action-context.v1 verwenden.';
            }
        }

        $eventByStep = [
            'before_save' => 'beforeSave',
            'after_save' => 'afterSave',
            'before_delete' => 'beforeDelete',
            'after_delete' => 'afterDelete',
        ];
        $eventsToCheck = [];
        if ($stepId === null || $stepId === 'review') {
            $eventsToCheck = array_values($eventByStep);
        } elseif (isset($eventByStep[$stepId])) {
            $eventsToCheck = [$eventByStep[$stepId]];
        }

        foreach ($eventsToCheck as $eventName) {
            $event = is_array($d['events'][$eventName] ?? null) ? $d['events'][$eventName] : [];
            $enabled = !empty($event['enabled']);
            $handler = trim((string) ($event['handler'] ?? ''));
            if ($enabled && $handler === '') {
                $errors[] = $eventName . ': JavaScript-Methode fehlt.';
            }
            if ($handler !== '' && !$this->validHandlerPath($handler)) {
                $errors[] = $eventName . ': Methodenname ist ungültig. Erlaubt sind Namen oder Pfade wie afterSave oder app.forms.afterSave.';
            }
        }

        if (($d['runtime']['allowEval'] ?? true) !== false) {
            $errors[] = 'Event-Handler dürfen nicht über eval ausgeführt werden.';
        }
        if (($d['runtime']['beforeEventFalseCancelsAction'] ?? false) !== true) {
            $errors[] = 'beforeSave/beforeDelete müssen die Aktion durch Rückgabe von false abbrechen können.';
        }

        $enabledCount = 0;
        foreach (($d['events'] ?? []) as $event) {
            if (!empty($event['enabled'])) {
                $enabledCount++;
            }
        }
        if (($stepId === null || $stepId === 'review') && $enabledCount === 0) {
            $warnings[] = 'Es ist noch kein Event aktiviert.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validHandlerPath(string $handler): bool
    {
        return (bool) preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $handler);
    }
}
