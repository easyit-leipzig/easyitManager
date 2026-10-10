<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Relation;

final class RelationConfigCompiler
{
    public function compile(RelationDraft $draft): array
    {
        $d = $draft->toArray();
        $type = (string) $d['relation']['type'];
        $config = [
            'schema' => 'easyit.dataform.relation.assistant.v1',
            'name' => $d['relation']['name'],
            'type' => $type,
            'parent' => [
                'dataForm' => $d['parent']['dataForm'],
                'source' => $d['parent']['source'],
                'keyField' => $d['parent']['keyField'],
            ],
            'child' => [
                'dataForm' => $d['child']['dataForm'],
                'source' => $d['child']['source'],
            ],
            'ui' => [
                'paginationPlacement' => 'below-records',
                'buttonRegistry' => 'central',
            ],
        ];

        if ($type === 'one_to_many') {
            $config['child']['foreignKeyField'] = $d['child']['foreignKeyField'];
            $config['binding'] = [
                'enabled' => (bool) $d['binding']['enabled'],
                'valueSource' => 'parent.currentRecord',
                'parentValueField' => $d['binding']['parentValueField'],
                'childTargetField' => $d['binding']['childTargetField'],
                'fillOnNewRecord' => true,
                'readOnly' => (bool) $d['binding']['readOnly'],
            ];
        } else {
            $config['manyToMany'] = [
                'junctionSource' => $d['manyToMany']['junctionSource'],
                'parentForeignKeyField' => $d['manyToMany']['parentForeignKeyField'],
                'childForeignKeyField' => $d['manyToMany']['childForeignKeyField'],
                'childKeyField' => $d['manyToMany']['childKeyField'],
            ];
        }

        return $config;
    }
}
