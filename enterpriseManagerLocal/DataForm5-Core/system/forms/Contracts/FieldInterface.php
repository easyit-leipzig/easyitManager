<?php
declare(strict_types=1);
namespace DataForm5\Forms\Contracts;
interface FieldInterface
{
    public function name(): string;
    public function type(): string;
    public function rules(): string|array;
    public function value(): mixed;
    public function withValue(mixed $value): static;
    public function toArray(): array;
}
