<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Relation;

final class RelationDraftValidator
{
    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(RelationDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];
        $type = (string) ($d['relation']['type'] ?? '');

        if ($stepId === null || in_array($stepId, ['relation', 'review'], true)) {
            if (!in_array($type, ['one_to_many', 'many_to_many'], true)) {
                $errors[] = 'Beziehungstyp muss 1:n oder n:m sein.';
            }
            if (trim((string) ($d['relation']['name'] ?? '')) === '') {
                $errors[] = 'Beziehungsname fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['parent', 'review'], true)) {
            if (trim((string) ($d['parent']['dataForm'] ?? '')) === '') {
                $errors[] = 'Übergeordnetes DataForm fehlt.';
            }
            if (trim((string) ($d['parent']['keyField'] ?? '')) === '') {
                $errors[] = 'Schlüsselfeld des übergeordneten DataForms fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['child', 'review'], true)) {
            if (trim((string) ($d['child']['dataForm'] ?? '')) === '') {
                $errors[] = 'Untergeordnetes DataForm fehlt.';
            }
            if ($type === 'one_to_many' && trim((string) ($d['child']['foreignKeyField'] ?? '')) === '') {
                $errors[] = 'Kind-Fremdschlüsselfeld fehlt.';
            }
        }

        if ($stepId === null || in_array($stepId, ['binding', 'review'], true)) {
            if ($type === 'one_to_many') {
                if (($d['binding']['enabled'] ?? false) !== true) {
                    $warnings[] = 'Ohne Bindung wird der Elternwert bei neuen Kinddatensätzen nicht automatisch übernommen.';
                }
                if (trim((string) ($d['binding']['parentValueField'] ?? '')) === '') {
                    $errors[] = 'Quellfeld des Elternwerts fehlt.';
                }
                if (trim((string) ($d['binding']['childTargetField'] ?? '')) === '') {
                    $errors[] = 'Zielfeld im Kind-DataForm fehlt.';
                }
                if (($d['binding']['valueSource'] ?? '') !== 'parent.currentRecord') {
                    $errors[] = 'Der gebundene Wert muss aus dem aktuellen übergeordneten Datensatz stammen.';
                }
                if (($d['binding']['fillOnNewRecord'] ?? false) !== true) {
                    $errors[] = 'Bei einem neuen gebundenen Kinddatensatz muss das Zielfeld automatisch mit dem Elternwert belegt werden.';
                }
                $childFk = trim((string) ($d['child']['foreignKeyField'] ?? ''));
                $target = trim((string) ($d['binding']['childTargetField'] ?? ''));
                if ($childFk !== '' && $target !== '' && $childFk !== $target) {
                    $errors[] = 'Kind-Fremdschlüsselfeld und gebundenes Zielfeld müssen identisch sein.';
                }
            }
        }

        if ($stepId === null || in_array($stepId, ['junction', 'review'], true)) {
            if ($type === 'many_to_many') {
                foreach ([
                    'junctionSource' => 'Zwischentabelle',
                    'parentForeignKeyField' => 'Eltern-Fremdschlüssel in der Zwischentabelle',
                    'childForeignKeyField' => 'Kind-Fremdschlüssel in der Zwischentabelle',
                    'childKeyField' => 'Schlüsselfeld des Kind-Datensatzes',
                ] as $key => $label) {
                    if (trim((string) ($d['manyToMany'][$key] ?? '')) === '') {
                        $errors[] = $label . ' fehlt.';
                    }
                }
            }
        }

        if (($d['display']['paginationPosition'] ?? '') !== 'below-records') {
            $errors[] = 'Die Paginierung des Kind-DataForms muss unter den Datensätzen stehen.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
