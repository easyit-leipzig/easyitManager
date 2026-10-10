<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataForm;

final class DataFormConfigCompiler
{
    public function compile(DataFormDraft $draft): array
    {
        $d = $draft->toArray();
        return [
            'schema' => 'easyit.dataform.assistant.v1',
            'name' => $d['identity']['dataFormName'],
            'source' => [
                'profile' => (string) ($d['source']['profile'] ?? ''),
                'driver' => $d['source']['driver'],
                'connection' => $d['source']['connection'],
                'name' => $d['source']['name'],
            ],
            'primaryKey' => $d['identity']['primaryKey'],
            'properties' => [
                'fullTextSearch' => (bool) $d['features']['fullTextSearch'],
                'filter' => (bool) $d['features']['filter'],
            ],
            'pagination' => [
                'enabled' => true,
                'position' => 'below-records',
                'pageSize' => (int) $d['features']['pagination']['pageSize'],
                'windowLeft' => 2,
                'windowRight' => 2,
                'showFirst' => true,
                'showLast' => true,
            ],
            'crud' => $d['crud'],
            'fields' => array_values($d['fields']),
            'ui' => [
                'buttonRegistry' => 'central',
                'paginationPlacement' => 'below-records',
            ],
        ];
    }
}
