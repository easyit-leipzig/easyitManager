<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Field;

final class FieldTypeRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $types;

    public function __construct()
    {
        $this->types = [
            'text' => ['label' => 'Text', 'valueKind' => 'string'],
            'textarea' => ['label' => 'Mehrzeiliger Text', 'valueKind' => 'string'],
            'integer' => ['label' => 'Ganzzahl', 'valueKind' => 'integer'],
            'decimal' => ['label' => 'Dezimalzahl', 'valueKind' => 'decimal'],
            'boolean' => ['label' => 'Ja/Nein', 'valueKind' => 'boolean'],
            'date' => ['label' => 'Datum', 'valueKind' => 'date'],
            'datetime' => ['label' => 'Datum/Zeit', 'valueKind' => 'datetime'],
            'email' => ['label' => 'E-Mail', 'valueKind' => 'string'],
            'url' => ['label' => 'URL', 'valueKind' => 'string'],
            'hidden' => ['label' => 'Versteckt', 'valueKind' => 'mixed'],
            'lookup' => ['label' => 'Lookup', 'valueKind' => 'scalar', 'requiresLookup' => true],
            'enum' => ['label' => 'Enum', 'valueKind' => 'scalar'],
            'derived_enum' => [
                'label' => 'Abgeleitetes Mehrfach-Enum',
                'valueKind' => 'delimited-list',
                'multiple' => true,
                'requiresDerivedEnum' => true,
            ],
        ];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): array
    {
        if (!$this->has($type)) {
            throw new \OutOfBoundsException('Unbekannter Feldtyp: ' . $type);
        }
        return $this->types[$type];
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->types;
    }
}
