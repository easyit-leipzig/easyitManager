<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataForm;

final class DataFormDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
    }

    public static function defaults(): array
    {
        return [
            'source' => [
                'profile' => '',
                'driver' => 'mysql',
                'connection' => '',
                'name' => '',
            ],
            'identity' => [
                'dataFormName' => '',
                'primaryKey' => 'id',
            ],
            'features' => [
                'fullTextSearch' => false,
                'filter' => false,
                'pagination' => [
                    'enabled' => true,
                    'position' => 'below-records',
                    'pageSize' => 20,
                    'windowLeft' => 2,
                    'windowRight' => 2,
                    'showFirst' => true,
                    'showLast' => true,
                ],
            ],
            'crud' => [
                'create' => true,
                'show' => true,
                'edit' => true,
                'delete' => true,
                'save' => true,
            ],
            'fields' => [],
        ];
    }

    public function merge(array $changes): self
    {
        $merged = array_replace_recursive($this->data, $changes);
        if (array_key_exists('fields', $changes)) {
            $merged['fields'] = is_array($changes['fields']) ? array_values($changes['fields']) : [];
        }
        return new self($merged);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
