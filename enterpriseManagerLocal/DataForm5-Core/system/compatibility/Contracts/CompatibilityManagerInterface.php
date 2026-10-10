<?php
declare(strict_types=1);
namespace DataForm5\Compatibility\Contracts;
interface CompatibilityManagerInterface
{
    public function currentVersion(): string;
    public function supports(string $version): bool;
    public function inspect(string $fromVersion, ?string $toVersion = null): array;
    public function plan(string $fromVersion, ?string $toVersion = null): array;
}
