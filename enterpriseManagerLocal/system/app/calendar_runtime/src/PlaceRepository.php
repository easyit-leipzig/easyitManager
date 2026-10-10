<?php
declare(strict_types=1);

namespace EasyIT\Calendar;

use PDO;

final class PlaceRepository extends BaseRepository
{
    public function __construct(PDO $pdo, array $config)
    {
        parent::__construct($pdo, $config, 'places');
    }

    public function get(bool $includeInactive = false): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            $item = $this->normalize($row);
            if (!$includeInactive && !$item['active']) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        $row = $this->raw($id);
        return $row === null ? null : $this->normalize($row);
    }

    private function normalize(array $row): array
    {
        $data = (array)($row['data'] ?? []);
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($data['name'] ?? ''),
            'active' => (int)($data['activ'] ?? $data['active'] ?? 1) === 1,
            'raw' => $data,
        ];
    }
}
