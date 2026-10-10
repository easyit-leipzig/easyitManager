<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class LookupResolver
{
    public function resolveLabel(array $rows, string $valueField, string $labelField, mixed $value): ?string
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists($valueField, $row) && (string) $row[$valueField] === (string) $value) {
                return array_key_exists($labelField, $row) ? (string) $row[$labelField] : (string) $value;
            }
        }
        return null;
    }
}
