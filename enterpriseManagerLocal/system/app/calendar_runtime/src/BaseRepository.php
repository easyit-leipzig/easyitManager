<?php
declare(strict_types=1);

namespace EasyIT\Calendar;

use PDO;
use RuntimeException;

abstract class BaseRepository
{
    protected PDO $pdo;
    protected array $config;
    protected int $formId;
    protected array $fields;

    public function __construct(PDO $pdo, array $config, string $dataformKey)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $dataforms = (array)($config['dataforms'] ?? []);
        $this->formId = (int)($dataforms[$dataformKey] ?? 0);
        if ($this->formId < 1) {
            throw new RuntimeException('DataForm "' . $dataformKey . '" ist nicht konfiguriert.');
        }
        $this->fields = \df_fields($this->pdo, $this->formId);
    }

    public function formId(): int
    {
        return $this->formId;
    }

    protected function rows(): array
    {
        return \df_raw_records($this->pdo, $this->formId, $this->fields);
    }

    protected function raw(int $id): ?array
    {
        return \df_find_record($this->pdo, $this->formId, $id, $this->fields);
    }

    protected function write(array $data, ?int $id = null): int
    {
        return \df_write_record($this->pdo, $this->formId, $this->fields, $data, $id);
    }
}
