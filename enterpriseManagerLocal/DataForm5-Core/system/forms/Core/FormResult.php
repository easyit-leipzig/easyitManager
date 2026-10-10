<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Validation\Core\ErrorBag;
final class FormResult
{
    public function __construct(private readonly array $data, private readonly ErrorBag $errors) {}
    public function passes(): bool { return !$this->errors->any(); }
    public function fails(): bool { return $this->errors->any(); }
    public function data(): array { return $this->data; }
    public function errors(): ErrorBag { return $this->errors; }
}
