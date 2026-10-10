<?php
declare(strict_types=1);
namespace DataForm5\Validation\Rules;
use DataForm5\Validation\Contracts\RuleInterface;
final class CallbackRule implements RuleInterface
{
    public function __construct(private readonly mixed $callback, private readonly string $errorMessage) {}
    public function passes(string $attribute, mixed $value, array $data): bool { return (bool)($this->callback)($attribute, $value, $data); }
    public function message(string $attribute): string { return str_replace(':attribute', $attribute, $this->errorMessage); }
}
