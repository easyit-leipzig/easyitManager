<?php
declare(strict_types=1);
namespace DataForm5\Features\Contracts;
interface FeatureManagerInterface
{
    public function enabled(string $feature, array $context = []): bool;
    public function disabled(string $feature, array $context = []): bool;
    public function value(string $feature, mixed $default = null): mixed;
    public function all(): array;
}
