<?php
declare(strict_types=1);

/**
 * Kanonischer JavaScript-/Action-Uebergabevertrag fuer DataForm-Aktionen.
 * PUBLISH17: dataformContext 1.0
 */
final class DataFormActionContext
{
    public const SCHEMA = 'easyit.dataform.action-context';
    public const VERSION = '1.0';
    public const OBJECT_NAME = 'dataformContext';

    public static function afterSave(
        array $project,
        array $dataform,
        array $fields,
        int $recordId,
        array $values,
        array $originalValues,
        string $operation,
        array $runtimeState = [],
        ?array $parentContext = null
    ): array {
        $changes = [];
        $dirtyFields = [];
        $fieldState = [];

        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') continue;
            $cfg = $field['_config'] ?? [];
            if (!is_array($cfg)) {
                $decoded = json_decode((string)($field['configuration_json'] ?? ''), true);
                $cfg = is_array($decoded) ? $decoded : [];
            }
            $value = $values[$name] ?? null;
            $old = $originalValues[$name] ?? null;
            $changed = self::different($old, $value);
            if ($changed) {
                $dirtyFields[] = $name;
                $changes[$name] = ['old' => $old, 'new' => $value];
            }
            $fieldState[$name] = [
                'field_id' => (int)($field['id'] ?? 0),
                'name' => $name,
                'label' => (string)($field['label'] ?? $name),
                'type' => (string)($field['field_type'] ?? 'text'),
                'value' => $value,
                'original_value' => $old,
                'dirty' => $changed,
                'required' => (int)($field['is_required'] ?? 0) === 1,
                'readonly' => !empty($cfg['readonly']),
                'visible' => (string)($field['field_type'] ?? '') !== 'hidden' && empty($cfg['hidden']),
                'valid' => true,
                'errors' => [],
            ];
        }

        $parent = null;
        if (is_array($parentContext)) {
            $parent = [
                'relation_id' => (int)($parentContext['relation_id'] ?? $parentContext['id'] ?? 0),
                'dataform_id' => (int)($parentContext['parent_form_id'] ?? $parentContext['source_dataform_id'] ?? 0),
                'dataform' => (string)($parentContext['parent_form_name'] ?? $parentContext['parent_name'] ?? ''),
                'record_id' => (int)($parentContext['parent_record_id'] ?? 0),
                'foreign_key' => (string)($parentContext['lookup_field_name'] ?? ''),
                'foreign_key_value' => (int)($parentContext['parent_record_id'] ?? 0),
                'binding' => [
                    'inherited' => true,
                    'readonly' => !array_key_exists('bound_field_readonly',$parentContext)
                        || !empty($parentContext['bound_field_readonly']),
                ],
            ];
        }

        if (
            $parent !== null
            && !empty($parent['binding']['readonly'])
            && isset($fieldState[$parent['foreign_key']])
        ) {
            $fieldState[$parent['foreign_key']]['readonly'] = true;
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::VERSION,
            'object_name' => self::OBJECT_NAME,
            'action' => [
                'name' => 'save',
                'phase' => 'after',
                'trigger' => 'user',
                'timestamp' => date(DATE_ATOM),
            ],
            'project' => [
                'id' => (int)($project['id'] ?? 0),
                'name' => (string)($project['name'] ?? ''),
            ],
            'dataform' => [
                'id' => (int)($dataform['id'] ?? 0),
                'name' => (string)($dataform['name'] ?? ''),
                'slug' => (string)($dataform['slug'] ?? ''),
                'status' => (string)($dataform['status'] ?? ''),
                'view_mode' => (string)($runtimeState['view_mode'] ?? $dataform['view_mode'] ?? 'table'),
                'fulltext_search' => (int)($dataform['show_search'] ?? 1) === 1,
                'filter' => (int)($dataform['show_filter'] ?? 1) === 1,
                'storage_mode' => (string)($runtimeState['storage_mode'] ?? 'generic'),
            ],
            'context' => [
                'mode' => $operation === 'create' ? 'create' : 'edit',
                'is_new_record' => $operation === 'create',
                'is_dirty' => count($dirtyFields) > 0,
                'preview' => !empty($runtimeState['preview']),
                'current_record' => [
                    'id' => $recordId,
                    'page' => max(1, (int)($runtimeState['page'] ?? 1)),
                ],
                'parent' => $parent,
                'relation' => $parent === null ? null : [
                    'relation_id' => $parent['relation_id'],
                    'type' => '1:n',
                    'foreign_key' => $parent['foreign_key'],
                    'foreign_key_value' => $parent['foreign_key_value'],
                    'binding' => $parent['binding'],
                ],
            ],
            'record' => [
                'id' => $recordId,
                'values' => $values,
                'original_values' => $originalValues,
                'changes' => $changes,
                'dirty_fields' => $dirtyFields,
            ],
            'fields' => $fieldState,
            'validation' => [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
            ],
            'ui' => [
                'view_mode' => (string)($runtimeState['view_mode'] ?? $dataform['view_mode'] ?? 'table'),
                'page' => max(1, (int)($runtimeState['page'] ?? 1)),
                'records_per_page' => max(1, (int)($runtimeState['records_per_page'] ?? $dataform['default_per_page'] ?? 20)),
                'search' => (string)($runtimeState['search'] ?? ''),
                'fulltext_search_enabled' => (int)($dataform['show_search'] ?? 1) === 1,
                'filter_enabled' => (int)($dataform['show_filter'] ?? 1) === 1,
                'active_record_id' => $recordId,
                'dialog_open' => !empty($runtimeState['dialog_open']),
            ],
            'event' => [
                'cancel' => false,
                'message' => null,
                'data' => new stdClass(),
            ],
            'result' => [
                'success' => true,
                'operation' => $operation,
                'record_id' => $recordId,
                'affected_rows' => 1,
            ],
        ];
    }

    private static function different(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            !== json_encode($b, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
