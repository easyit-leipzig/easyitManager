<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class DerivedEnumValueCodec
{
    public const DELIMITER = ',';

    /** @return list<string> */
    public function decode(string|array|null $value): array
    {
        $values = is_array($value) ? $value : explode(self::DELIMITER, (string) ($value ?? ''));
        $normalized = [];
        foreach ($values as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (str_contains($item, self::DELIMITER)) {
                foreach (explode(self::DELIMITER, $item) as $part) {
                    $part = trim($part);
                    if ($part !== '' && !in_array($part, $normalized, true)) {
                        $normalized[] = $part;
                    }
                }
                continue;
            }
            if (!in_array($item, $normalized, true)) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    public function encode(array $values): string
    {
        foreach ($values as $value) {
            if (str_contains((string) $value, self::DELIMITER)) {
                throw new \InvalidArgumentException('Ein einzelner Derived-Enum-Schlüssel darf kein Komma enthalten.');
            }
        }
        return implode(self::DELIMITER, $this->decode($values));
    }

    public function normalize(string|array|null $value): string
    {
        return $this->encode($this->decode($value));
    }

    /** @return list<array{value:string,label:string,selected:bool}> */
    public function optionsFromRows(array $rows, string $valueField, string $labelField, string|array|null $selectedValue = null): array
    {
        $selected = $this->decode($selectedValue);
        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($valueField, $row)) {
                continue;
            }
            $value = trim((string) $row[$valueField]);
            if ($value === '' || str_contains($value, self::DELIMITER)) {
                continue;
            }
            $label = array_key_exists($labelField, $row) ? (string) $row[$labelField] : $value;
            $options[] = ['value' => $value, 'label' => $label, 'selected' => in_array($value, $selected, true)];
        }
        return $options;
    }
}
