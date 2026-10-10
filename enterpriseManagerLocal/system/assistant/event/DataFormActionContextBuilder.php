<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Event;

final class DataFormActionContextBuilder
{
    /**
     * Baut das einheitliche Übergabeobjekt für DataForm-Aktionen und Events.
     * Die Snapshot-Daten kommen aus der laufenden DataForm-Instanz.
     */
    public function build(string $eventName, string $action, array $snapshot): array
    {
        $original = is_array($snapshot['originalRecord'] ?? null) ? $snapshot['originalRecord'] : [];
        $current = is_array($snapshot['currentRecord'] ?? null) ? $snapshot['currentRecord'] : [];
        $changes = is_array($snapshot['changes'] ?? null)
            ? $snapshot['changes']
            : $this->diff($original, $current);

        return [
            'schema' => 'easyit.dataform.action-context.v1',
            'event' => $eventName,
            'action' => $action,
            'project' => is_array($snapshot['project'] ?? null) ? $snapshot['project'] : [],
            'dataForm' => array_replace([
                'id' => null,
                'name' => null,
                'source' => null,
                'mode' => null,
                'isNewRecord' => false,
                'isDirty' => $changes !== [],
            ], is_array($snapshot['dataForm'] ?? null) ? $snapshot['dataForm'] : []),
            'record' => [
                'id' => $snapshot['recordId'] ?? ($current['id'] ?? null),
                'original' => $original,
                'current' => $current,
            ],
            'changes' => $changes,
            'relation' => is_array($snapshot['relation'] ?? null) ? $snapshot['relation'] : [],
            'pagination' => is_array($snapshot['pagination'] ?? null) ? $snapshot['pagination'] : [],
            'operation' => is_array($snapshot['operation'] ?? null) ? $snapshot['operation'] : [],
            'ui' => is_array($snapshot['ui'] ?? null) ? $snapshot['ui'] : [],
            'meta' => is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [],
        ];
    }

    private function diff(array $original, array $current): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($original), array_keys($current))) as $key) {
            $before = $original[$key] ?? null;
            $after = $current[$key] ?? null;
            if ($before !== $after) {
                $changes[$key] = ['before' => $before, 'after' => $after];
            }
        }
        return $changes;
    }
}
