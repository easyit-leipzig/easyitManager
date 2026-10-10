<?php
declare(strict_types=1);
namespace DataForm5\View\Contracts;
interface ViewFactoryInterface
{
    public function make(string $view, array $data = []): string;
    public function exists(string $view): bool;
    public function share(string $key, mixed $value): void;
}
