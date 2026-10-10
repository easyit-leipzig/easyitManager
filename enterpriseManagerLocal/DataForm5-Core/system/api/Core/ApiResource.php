<?php
declare(strict_types=1);
namespace DataForm5\Api\Core;
use DataForm5\Api\Contracts\ArrayableInterface;
abstract class ApiResource implements ArrayableInterface, \JsonSerializable
{
    public function __construct(protected mixed $resource) {}
    public static function make(mixed $resource): static { return new static($resource); }
    public static function collection(iterable $items): ResourceCollection { return new ResourceCollection($items, static::class); }
    protected function value(string $key, mixed $default=null): mixed {
        if (is_array($this->resource)) return $this->resource[$key] ?? $default;
        if (is_object($this->resource)) {
            if (method_exists($this->resource, 'getAttribute')) return $this->resource->getAttribute($key, $default);
            return $this->resource->{$key} ?? $default;
        }
        return $default;
    }
    public function jsonSerialize(): array { return $this->toArray(); }
}
