<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Contracts;
use DataForm5\Licensing\Core\License;
interface LicenseManagerInterface
{
    public function license(): ?License;
    public function status(?\DateTimeImmutable $at = null): string;
    public function valid(?\DateTimeImmutable $at = null): bool;
    public function allows(string $capability, array $context = []): bool;
    public function requireCapability(string $capability, array $context = []): void;
    public function summary(?\DateTimeImmutable $at = null): array;
}
