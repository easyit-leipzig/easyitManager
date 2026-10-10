<?php
declare(strict_types=1);

namespace EasyIT\Calendar;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class EventRepository extends BaseRepository
{
    public function __construct(PDO $pdo, array $config)
    {
        parent::__construct($pdo, $config, 'events');
    }

    public function get(?string $start = null, ?string $end = null, bool $includeInactive = false): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            $item = $this->normalize($row);
            if (!$includeInactive && !$item['active']) {
                continue;
            }
            if ($start !== null && $start !== '' && $item['end'] !== '' && $item['end'] < $start) {
                continue;
            }
            if ($end !== null && $end !== '' && $item['start'] !== '' && $item['start'] >= $end) {
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

    public function create(array $event): int
    {
        $data = $this->toStorage($event, []);
        $this->validateRequired($data);
        return $this->write($data);
    }

    public function update(int $id, array $event): int
    {
        $row = $this->raw($id);
        if ($row === null) {
            throw new RuntimeException('Termin #' . $id . ' wurde nicht gefunden.');
        }
        $data = $this->toStorage($event, (array)$row['data']);
        $this->validateRequired($data);
        return $this->write($data, $id);
    }

    public function delete(int $id): void
    {
        $row = $this->raw($id);
        if ($row === null) {
            throw new RuntimeException('Termin #' . $id . ' wurde nicht gefunden.');
        }
        $data = (array)$row['data'];
        $activeField = $this->field('active', 'activ');
        $data[$activeField] = '0';
        $this->write($data, $id);
    }

    private function normalize(array $row): array
    {
        $data = (array)($row['data'] ?? []);
        $value = fn(string $logical, string $fallback, mixed $default = '') => $data[$this->field($logical, $fallback)] ?? $default;
        return [
            'id' => (int)($row['id'] ?? 0),
            'start' => $this->iso((string)$value('start', 'start')),
            'end' => $this->iso((string)$value('end', 'end')),
            'title' => (string)$value('title', 'thema'),
            'description' => (string)$value('description', 'description'),
            'active' => (int)$value('active', 'activ', 1) === 1,
            'all_day' => (int)$value('all_day', 'all_day', 0) === 1,
            'location' => $value('location', 'location', null),
            'resource' => $value('resource', 'resource_id', null),
            'category' => $value('category', 'category_id', null),
            'status' => (string)$value('status', 'event_status'),
            'raw' => $data,
        ];
    }

    private function toStorage(array $event, array $data): array
    {
        $map = [
            'start' => ['start', 'start'],
            'end' => ['end', 'end'],
            'title' => ['title', 'thema'],
            'description' => ['description', 'description'],
            'location' => ['location', 'location'],
            'resource' => ['resource', 'resource_id'],
            'category' => ['category', 'category_id'],
            'status' => ['status', 'event_status'],
        ];
        foreach ($map as $input => [$logical, $fallback]) {
            if (array_key_exists($input, $event)) {
                $v = $event[$input];
                if (in_array($input, ['start', 'end'], true)) {
                    $v = str_replace('T', ' ', (string)$v);
                }
                $data[$this->field($logical, $fallback)] = $v;
            }
        }
        if (array_key_exists('active', $event)) {
            $data[$this->field('active', 'activ')] = !empty($event['active']) ? '1' : '0';
        } elseif (!array_key_exists($this->field('active', 'activ'), $data)) {
            $data[$this->field('active', 'activ')] = '1';
        }
        if (array_key_exists('all_day', $event)) {
            $data[$this->field('all_day', 'all_day')] = !empty($event['all_day']) ? '1' : '0';
        }
        return $data;
    }

    private function validateRequired(array $data): void
    {
        foreach ([['start', 'start'], ['end', 'end'], ['title', 'thema']] as [$logical, $fallback]) {
            $name = $this->field($logical, $fallback);
            if (trim((string)($data[$name] ?? '')) === '') {
                throw new InvalidArgumentException('Pflichtfeld "' . $logical . '" fehlt.');
            }
        }
        $start = (string)$data[$this->field('start', 'start')];
        $end = (string)$data[$this->field('end', 'end')];
        if ($end <= $start) {
            throw new InvalidArgumentException('Das Terminende muss nach dem Terminbeginn liegen.');
        }
    }

    private function field(string $logical, string $fallback): string
    {
        $mapping = (array)($this->config['field_mapping']['fields'] ?? []);
        $name = trim((string)($mapping[$logical]['field'] ?? $fallback));
        return $name !== '' ? $name : $fallback;
    }

    private function iso(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return str_replace(' ', 'T', $value);
    }
}
