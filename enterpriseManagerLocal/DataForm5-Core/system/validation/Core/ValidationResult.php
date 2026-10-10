<?php
declare(strict_types=1);
namespace DataForm5\Validation\Core;
use DataForm5\Validation\Exceptions\ValidationException;
final class ValidationResult
{
    public function __construct(private readonly array $validated, private readonly ErrorBag $errors) {}
    public function passes(): bool { return $this->errors->isEmpty(); }
    public function fails(): bool { return $this->errors->any(); }
    public function errors(): ErrorBag { return $this->errors; }
    public function validated(): array
    {
        if ($this->fails()) throw new ValidationException($this);
        return $this->validated;
    }
}
