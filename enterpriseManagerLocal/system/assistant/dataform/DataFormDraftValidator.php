<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataForm;

final class DataFormDraftValidator
{
    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(DataFormDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];

        if ($stepId === null || in_array($stepId, ['source', 'review'], true)) {
            if (trim((string) ($d['source']['driver'] ?? '')) === '') {
                $errors[] = 'Datenquellentyp fehlt.';
            }
            if (trim((string) ($d['source']['name'] ?? '')) === '') {
                $errors[] = 'Haupttabelle bzw. Hauptquelle fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['identity', 'review'], true)) {
            if (trim((string) ($d['identity']['dataFormName'] ?? '')) === '') {
                $errors[] = 'DataForm-Name fehlt.';
            }
            if (trim((string) ($d['identity']['primaryKey'] ?? '')) === '') {
                $errors[] = 'Primärschlüssel fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['features', 'review'], true)) {
            $pagination = $d['features']['pagination'] ?? [];
            $pageSize = (int) ($pagination['pageSize'] ?? 0);
            if ($pageSize < 1 || $pageSize > 500) {
                $errors[] = 'Datensätze pro Seite müssen zwischen 1 und 500 liegen.';
            }
            if (($pagination['enabled'] ?? false) !== true) {
                $errors[] = 'Die DataForm5-Paginierung ist verbindlich aktiviert.';
            }
            if (($pagination['position'] ?? '') !== 'below-records') {
                $errors[] = 'Die Paginierung muss unter den Datensätzen angezeigt werden.';
            }
        }

        if ($stepId === null || in_array($stepId, ['fields', 'review'], true)) {
            $names = [];
            foreach (($d['fields'] ?? []) as $index => $field) {
                $name = trim((string) ($field['name'] ?? ''));
                if ($name === '') {
                    $errors[] = 'Feld ' . ($index + 1) . ': Feldname fehlt.';
                    continue;
                }
                if (isset($names[$name])) {
                    $errors[] = 'Feldname doppelt vorhanden: ' . $name;
                }
                $names[$name] = true;
            }
            $pk = trim((string) ($d['identity']['primaryKey'] ?? ''));
            if ($pk !== '' && $names !== [] && !isset($names[$pk])) {
                $warnings[] = 'Der Primärschlüssel "' . $pk . '" ist in der aktuellen Feldliste noch nicht enthalten.';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
