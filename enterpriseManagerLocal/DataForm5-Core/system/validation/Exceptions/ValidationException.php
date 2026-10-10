<?php
declare(strict_types=1);
namespace DataForm5\Validation\Exceptions;
use DataForm5\Validation\Core\ValidationResult;
use RuntimeException;
final class ValidationException extends RuntimeException
{
    public function __construct(private readonly ValidationResult $result)
    {
        parent::__construct('Die Validierung ist fehlgeschlagen.');
    }
    public function result(): ValidationResult { return $this->result; }
    public function errors(): array { return $this->result->errors()->all(); }
}
