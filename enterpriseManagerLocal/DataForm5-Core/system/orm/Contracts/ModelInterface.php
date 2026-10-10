<?php
declare(strict_types=1);

namespace DataForm5\ORM\Contracts;

interface ModelInterface extends \JsonSerializable
{
    public static function table(): string;
    public static function primaryKey(): string;
    public function getKey(): ?int;
    public function exists(): bool;
    public function attributes(): array;
    public function fill(array $attributes): static;
    public function forceFill(array $attributes): static;
    public function toArray(): array;
}
