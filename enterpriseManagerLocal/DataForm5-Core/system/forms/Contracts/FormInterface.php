<?php
declare(strict_types=1);
namespace DataForm5\Forms\Contracts;
use DataForm5\Forms\Core\FormResult;
interface FormInterface
{
    public function name(): string;
    public function fields(): array;
    public function bind(array $data): static;
    public function validate(array $data): FormResult;
    public function values(): array;
}
