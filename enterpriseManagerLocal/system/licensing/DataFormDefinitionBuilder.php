<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;

final class DataFormDefinitionBuilder
{
    public static function build(object $db, int $dataformId): array
    {
        if ($dataformId < 1) throw new \InvalidArgumentException('DATAFORM_ID_INVALID');

        $stmt = $db->prepare('SELECT * FROM dataforms WHERE id=? LIMIT 1');
        $stmt->execute([$dataformId]);
        $form = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($form)) throw new \RuntimeException('DATAFORM_NOT_FOUND');

        $fieldStmt = $db->prepare('SELECT * FROM dataform_fields WHERE dataform_id=? ORDER BY position,id');
        $fieldStmt->execute([$dataformId]);
        $fields = [];
        foreach ($fieldStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $cfg = json_decode((string)($row['configuration_json'] ?? ''), true);
            if (!is_array($cfg)) $cfg = [];
            $fields[] = [
                'name' => (string)($row['name'] ?? ''),
                'type' => (string)($row['field_type'] ?? 'text'),
                'required' => !empty($row['is_required']),
                'readonly' => !empty($cfg['readonly']),
            ];
        }

        $dfHandlers = json_decode((string)($form['event_handlers_json'] ?? ''), true);
        if (!is_array($dfHandlers)) $dfHandlers = [];
        $dfEvents = [];
        foreach (['open','close','before_view_change','view_change','before_refresh','after_refresh'] as $event) {
            if (trim((string)($dfHandlers[$event] ?? '')) !== '') $dfEvents[] = $event;
        }

        $dsEvents = [];
        try {
            $rs = $db->prepare("SELECT event_key,handler_code FROM dataform_recordset_event_handlers WHERE dataform_id=? AND recordset_key='main'");
            $rs->execute([$dataformId]);
            foreach ($rs->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (trim((string)($row['handler_code'] ?? '')) !== '') $dsEvents[] = (string)$row['event_key'];
            }
        } catch (\Throwable) {
        }

        $relations = [];
        try {
            $rel = $db->prepare('SELECT * FROM dataform_relations WHERE (source_dataform_id=? OR target_dataform_id=?) AND is_enabled=1 ORDER BY id');
            $rel->execute([$dataformId,$dataformId]);
            foreach ($rel->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $binding = null;
                if ((string)($row['relation_type'] ?? '') === '1:n' && (int)($row['target_dataform_id'] ?? 0) > 0) {
                    $binding = [
                        'parent_dataform_id' => (string)($row['source_dataform_id'] ?? ''),
                        'child_dataform_id' => (string)($row['target_dataform_id'] ?? ''),
                        'child_field_id' => (string)($row['lookup_field_id'] ?? ''),
                        'readonly' => true,
                    ];
                }
                $relations[] = [
                    'name' => (string)($row['name'] ?? ('relation-'.$row['id'])),
                    'type' => (string)($row['relation_type'] ?? ''),
                    'binding' => $binding,
                ];
            }
        } catch (\Throwable) {
        }

        $caps = ['dataset.read'];
        if (!empty($form['show_search'])) $caps[] = 'dataform.search';
        if (!empty($form['show_filter'])) $caps[] = 'dataform.filter';
        if (!empty($form['show_pagination'])) $caps[] = 'dataform.pagination';
        if (trim((string)($form['css_class'] ?? '')) !== '' || trim((string)($form['additional_css'] ?? '')) !== '') $caps[] = 'dataform.custom_css';
        if ($dfEvents || $dsEvents) $caps[] = 'dataform.events';
        if (!empty($form['allow_create'])) $caps[] = 'dataset.create';
        if (!empty($form['allow_edit'])) $caps[] = 'dataset.update';
        if (!empty($form['allow_delete'])) $caps[] = 'dataset.delete';
        if ($relations) {
            $caps[] = 'relation.read';
            $caps[] = 'relation.createChild';
            $caps[] = 'relation.attach';
            $caps[] = 'relation.detach';
        }

        return [
            'dataform_uuid' => (string)($form['df_uuid'] ?? ''),
            'name' => (string)($form['name'] ?? ''),
            'slug' => (string)($form['slug'] ?? ''),
            'view' => [
                'mode' => (string)($form['view_mode'] ?? 'form'),
                'page_size' => max(1,(int)($form['default_per_page'] ?? 20)),
                'fulltext_search' => !empty($form['show_search']),
                'filter' => !empty($form['show_filter']),
                'pagination' => !empty($form['show_pagination']),
                'addClass' => (string)($form['css_class'] ?? ''),
                'addJavaScript' => '',
            ],
            'capabilities' => array_values(array_unique($caps)),
            'fields' => $fields,
            'events' => [
                'dataform' => array_values(array_unique($dfEvents)),
                'dataset' => array_values(array_unique($dsEvents)),
            ],
            'relations' => $relations,
        ];
    }
}
