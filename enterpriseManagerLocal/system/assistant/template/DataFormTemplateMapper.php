<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Template;

final class DataFormTemplateMapper
{
    /**
     * @param array<string,mixed> $template
     * @param array<string,string> $mapping
     * @return array<string,array<string,mixed>>
     */
    public function map(array $template, string $targetDataForm, array $mapping = []): array
    {
        $bundle = is_array($template['bundle'] ?? null) ? $template['bundle'] : [];
        $sourceDataForm = (string)($template['source']['dataFormId'] ?? '');
        if ($sourceDataForm !== '' && $targetDataForm !== '') { $mapping[$sourceDataForm] = $targetDataForm; }
        $mapped = [];
        foreach ($bundle as $assistantId => $state) {
            if (!is_array($state)) { continue; }
            $mapped[$assistantId] = $this->walk($state, $mapping);
        }

        if (isset($mapped['dataform.create'])) {
            $mapped['dataform.create']['identity']['dataFormName'] = $targetDataForm;
        }
        if (isset($mapped['dataform.fields'])) {
            $mapped['dataform.fields']['context']['dataForm'] = $targetDataForm;
        }
        if (isset($mapped['dataform.actions'])) {
            $mapped['dataform.actions']['dataForm']['name'] = $targetDataForm;
        }
        return $mapped;
    }

    /** @param array<string,mixed> $value @param array<string,string> $mapping */
    private function walk(array $value, array $mapping): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->walk($item, $mapping);
            } elseif (is_string($item) && array_key_exists($item, $mapping)) {
                $value[$key] = $mapping[$item];
            }
        }
        return $value;
    }
}
