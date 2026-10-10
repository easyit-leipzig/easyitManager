<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class DataSourceDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
        if (isset($data['discovery'])) {
            $this->data['discovery'] = is_array($data['discovery']) ? array_values($data['discovery']) : [];
        }
    }

    public static function defaults(): array
    {
        return [
            'profile' => [
                'name' => 'project',
                'driver' => 'mysql',
            ],
            'connection' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => '',
                'schema' => 'public',
                'username' => '',
                'passwordRef' => '',
                'charset' => 'utf8mb4',
                'path' => '',
                'service' => '',
                'delimiter' => '|',
                'header' => true,
            ],
            'test' => [
                'ok' => false,
                'message' => 'Noch nicht getestet.',
                'details' => [],
                'warnings' => [],
                'testedAt' => null,
            ],
            'discovery' => [],
            'selection' => [
                'sourceName' => '',
                'sourceType' => '',
            ],
        ];
    }

    public function merge(array $changes): self
    {
        $merged = array_replace_recursive($this->data, $changes);
        if (array_key_exists('discovery', $changes)) {
            $merged['discovery'] = is_array($changes['discovery']) ? array_values($changes['discovery']) : [];
        }
        return new self($merged);
    }

    public function toArray(): array { return $this->data; }
    public function jsonSerialize(): array { return $this->data; }
}
