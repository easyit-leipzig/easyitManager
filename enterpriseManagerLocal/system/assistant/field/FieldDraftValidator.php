<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class FieldDraftValidator
{
    public function __construct(private FieldTypeRegistry $types) {}

    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(FieldDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];

        if ($stepId === null || in_array($stepId, ['context', 'review'], true)) {
            if (trim((string) ($d['context']['dataForm'] ?? '')) === '') {
                $errors[] = 'DataForm-Name fehlt.';
            }
            if (trim((string) ($d['context']['primaryKey'] ?? '')) === '') {
                $errors[] = 'Primärschlüssel fehlt.';
            }
        }

        $fieldMap = [];
        if ($stepId === null || in_array($stepId, ['fields', 'lookups', 'derived_enum', 'review'], true)) {
            foreach (($d['fields'] ?? []) as $index => $field) {
                $name = trim((string) ($field['name'] ?? ''));
                $type = trim((string) ($field['type'] ?? 'text'));
                if ($name === '') {
                    $errors[] = 'Feld ' . ($index + 1) . ': Feldname fehlt.';
                    continue;
                }
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                    $errors[] = 'Feld "' . $name . '": ungültiger Feldname.';
                }
                if (isset($fieldMap[$name])) {
                    $errors[] = 'Feldname doppelt vorhanden: ' . $name;
                }
                if (!$this->types->has($type)) {
                    $errors[] = 'Feld "' . $name . '": unbekannter Feldtyp "' . $type . '".';
                }
                $fieldMap[$name] = $field;
            }
        }

        if ($stepId === null || in_array($stepId, ['lookups', 'review'], true)) {
            $seen = [];
            foreach (($d['lookups'] ?? []) as $index => $lookup) {
                $field = trim((string) ($lookup['field'] ?? ''));
                if ($field === '' || !isset($fieldMap[$field])) {
                    $errors[] = 'Lookup ' . ($index + 1) . ': Zielfeld "' . $field . '" existiert nicht.';
                    continue;
                }
                if (($fieldMap[$field]['type'] ?? '') !== 'lookup') {
                    $errors[] = 'Lookup-Feld "' . $field . '" muss den Feldtyp lookup besitzen.';
                }
                if (isset($seen[$field])) {
                    $errors[] = 'Für das Lookup-Feld "' . $field . '" ist mehr als eine Quelle definiert.';
                }
                $seen[$field] = true;
                foreach (['source', 'valueField', 'labelField'] as $required) {
                    if (trim((string) ($lookup[$required] ?? '')) === '') {
                        $errors[] = 'Lookup-Feld "' . $field . '": ' . $required . ' fehlt.';
                    }
                }
            }
        }

        if ($stepId === null || in_array($stepId, ['derived_enum', 'review'], true)) {
            $seen = [];
            foreach (($d['derivedEnums'] ?? []) as $index => $enum) {
                $field = trim((string) ($enum['field'] ?? ''));
                if ($field === '' || !isset($fieldMap[$field])) {
                    $errors[] = 'Derived Enum ' . ($index + 1) . ': Zielfeld "' . $field . '" existiert nicht.';
                    continue;
                }
                if (($fieldMap[$field]['type'] ?? '') !== 'derived_enum') {
                    $errors[] = 'Derived-Enum-Feld "' . $field . '" muss den Feldtyp derived_enum besitzen.';
                }
                if (isset($seen[$field])) {
                    $errors[] = 'Für das Derived-Enum-Feld "' . $field . '" ist mehr als eine Quelle definiert.';
                }
                $seen[$field] = true;
                foreach (['source', 'valueField', 'labelField'] as $required) {
                    if (trim((string) ($enum[$required] ?? '')) === '') {
                        $errors[] = 'Derived-Enum-Feld "' . $field . '": ' . $required . ' fehlt.';
                    }
                }
                if (($enum['delimiter'] ?? ',') !== ',') {
                    $errors[] = 'Derived-Enum-Feld "' . $field . '": DataForm5 speichert Mehrfachwerte verbindlich kommasepariert.';
                }
                if (($enum['multiple'] ?? false) !== true) {
                    $errors[] = 'Derived-Enum-Feld "' . $field . '": Mehrfachauswahl muss aktiviert sein.';
                }
            }
        }

        if ($stepId === null || $stepId === 'review') {
            foreach ($fieldMap as $name => $field) {
                $type = (string) ($field['type'] ?? 'text');
                if ($type === 'lookup' && !$this->hasBinding($d['lookups'] ?? [], $name)) {
                    $errors[] = 'Lookup-Feld "' . $name . '" besitzt noch keine Lookup-Definition.';
                }
                if ($type === 'derived_enum' && !$this->hasBinding($d['derivedEnums'] ?? [], $name)) {
                    $errors[] = 'Derived-Enum-Feld "' . $name . '" besitzt noch keine Derived-Enum-Definition.';
                }
            }
            $pk = trim((string) ($d['context']['primaryKey'] ?? ''));
            if ($pk !== '' && $fieldMap !== [] && !isset($fieldMap[$pk])) {
                $warnings[] = 'Der Primärschlüssel "' . $pk . '" ist in der Feldliste nicht enthalten.';
            }
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    private function hasBinding(array $definitions, string $field): bool
    {
        foreach ($definitions as $definition) {
            if ((string) ($definition['field'] ?? '') === $field) {
                return true;
            }
        }
        return false;
    }
}
