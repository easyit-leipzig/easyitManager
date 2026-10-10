<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class FieldDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
        foreach (['fields', 'lookups', 'derivedEnums'] as $listKey) {
            $this->data[$listKey] = isset($data[$listKey]) && is_array($data[$listKey])
                ? array_values($data[$listKey])
                : array_values($this->data[$listKey] ?? []);
        }
    }

    public static function defaults(): array
    {
        return [
            'context' => [
                'dataForm' => '',
                'sourceProfile' => '',
                'sourceName' => '',
                'primaryKey' => 'id',
            ],
            'fields' => [],
            'lookups' => [],
            'derivedEnums' => [],
            'rules' => [
                'derivedEnumStorage' => 'comma-separated',
                'derivedEnumDelimiter' => ',',
                'trimValues' => true,
                'deduplicateValues' => true,
            ],
        ];
    }

    public function merge(array $changes): self
    {
        $merged = array_replace_recursive($this->data, $changes);
        foreach (['fields', 'lookups', 'derivedEnums'] as $listKey) {
            if (array_key_exists($listKey, $changes)) {
                $merged[$listKey] = is_array($changes[$listKey]) ? array_values($changes[$listKey]) : [];
            }
        }
        return new self($merged);
    }

    public function toArray(): array { return $this->data; }
    public function jsonSerialize(): array { return $this->data; }
}
