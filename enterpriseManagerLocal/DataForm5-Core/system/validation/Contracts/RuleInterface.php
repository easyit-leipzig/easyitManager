<?php
declare(strict_types=1);
namespace DataForm5\Validation\Contracts;
interface RuleInterface
{
    public function passes(string $attribute, mixed $value, array $data): bool;
    public function message(string $attribute): string;
}
