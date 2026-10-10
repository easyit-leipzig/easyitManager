<?php
declare(strict_types=1);

namespace DataForm5\ORM\Core;

use DataForm5\ORM\Contracts\ModelInterface;

abstract class Model implements ModelInterface
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $guarded = ['id'];
    protected array $casts = [];
    protected array $attributes = [];
    protected bool $modelExists = false;

    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->modelExists = $exists;
        $exists ? $this->forceFill($attributes) : $this->fill($attributes);
    }

    public static function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }
        $short = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short) . 's';
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    public function getKey(): ?int
    {
        $key = $this->attributes[static::primaryKey()] ?? null;
        return $key === null ? null : (int)$key;
    }

    public function exists(): bool
    {
        return $this->modelExists;
    }

    public function markExists(bool $exists = true): static
    {
        $this->modelExists = $exists;
        return $this;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $key = (string)$key;
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string)$key, $value);
        }
        return $this;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->castInbound($key, $value);
        return $this;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return $default;
        }
        return $this->castOutbound($key, $this->attributes[$key]);
    }

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->castOutbound((string)$key, $value);
        }
        return $result;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected function isFillable(string $key): bool
    {
        if ($this->fillable !== []) {
            return in_array($key, $this->fillable, true);
        }
        return !in_array($key, $this->guarded, true);
    }

    private function castInbound(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        return match ($cast) {
            'json', 'array' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
            'bool', 'boolean' => $value ? 1 : 0,
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'string' => (string)$value,
            'datetime' => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value,
            default => $value,
        };
    }

    private function castOutbound(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        return match ($cast) {
            'json', 'array' => is_string($value) ? (json_decode($value, true) ?? []) : (array)$value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int)$value === 1),
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'string' => (string)$value,
            'datetime' => $value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string)$value),
            default => $value,
        };
    }
}
