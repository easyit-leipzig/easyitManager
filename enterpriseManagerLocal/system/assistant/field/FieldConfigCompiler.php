<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class FieldConfigCompiler
{
    public function __construct(private FieldTypeRegistry $types) {}

    public function compile(FieldDraft $draft): array
    {
        $d = $draft->toArray();
        $lookups = [];
        foreach ($d['lookups'] ?? [] as $lookup) {
            $lookups[(string) ($lookup['field'] ?? '')] = $lookup;
        }
        $derived = [];
        foreach ($d['derivedEnums'] ?? [] as $enum) {
            $derived[(string) ($enum['field'] ?? '')] = $enum;
        }

        $fields = [];
        foreach ($d['fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $compiled = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'readOnly' => (bool) ($field['readOnly'] ?? false),
                'default' => $field['default'] ?? null,
            ];
            if ($type === 'lookup' && isset($lookups[$name])) {
                $compiled['lookup'] = $this->compileLookup($lookups[$name]);
            }
            if ($type === 'derived_enum' && isset($derived[$name])) {
                $compiled['derivedEnum'] = $this->compileDerivedEnum($derived[$name]);
            }
            $compiled['typeMeta'] = $this->types->has($type) ? $this->types->get($type) : [];
            $fields[] = $compiled;
        }

        return [
            'schema' => 'easyit.dataform.fields.assistant.v1',
            'dataForm' => (string) ($d['context']['dataForm'] ?? ''),
            'source' => [
                'profile' => (string) ($d['context']['sourceProfile'] ?? ''),
                'name' => (string) ($d['context']['sourceName'] ?? ''),
            ],
            'primaryKey' => (string) ($d['context']['primaryKey'] ?? 'id'),
            'fields' => $fields,
            'rules' => [
                'derivedEnumStorage' => 'comma-separated',
                'derivedEnumDelimiter' => ',',
                'derivedEnumMultiple' => true,
                'trimValues' => true,
                'deduplicateValues' => true,
            ],
        ];
    }

    private function compileLookup(array $lookup): array
    {
        return [
            'profile' => (string) ($lookup['profile'] ?? ''),
            'source' => (string) ($lookup['source'] ?? ''),
            'valueField' => (string) ($lookup['valueField'] ?? 'id'),
            'labelField' => (string) ($lookup['labelField'] ?? 'name'),
            'filterField' => (string) ($lookup['filterField'] ?? ''),
            'filterValueSource' => (string) ($lookup['filterValueSource'] ?? ''),
            'multiple' => false,
        ];
    }

    private function compileDerivedEnum(array $enum): array
    {
        return [
            'profile' => (string) ($enum['profile'] ?? ''),
            'source' => (string) ($enum['source'] ?? ''),
            'valueField' => (string) ($enum['valueField'] ?? 'id'),
            'labelField' => (string) ($enum['labelField'] ?? 'name'),
            'filterField' => (string) ($enum['filterField'] ?? ''),
            'filterValueSource' => (string) ($enum['filterValueSource'] ?? ''),
            'multiple' => true,
            'storage' => [
                'mode' => 'delimited',
                'delimiter' => ',',
                'trim' => true,
                'deduplicate' => true,
            ],
        ];
    }
}
