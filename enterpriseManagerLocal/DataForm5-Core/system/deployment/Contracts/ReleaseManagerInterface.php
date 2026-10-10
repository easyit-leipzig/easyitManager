<?php
declare(strict_types=1);
namespace DataForm5\Deployment\Contracts;
interface ReleaseManagerInterface
{
    public function createManifest(string $sourcePath, string $version, array $metadata = []): array;
    public function verifyManifest(string $sourcePath, array $manifest): bool;
    public function enterMaintenance(string $message = 'Wartungsarbeiten'): void;
    public function leaveMaintenance(): void;
    public function isMaintenance(): bool;
    public function maintenanceData(): ?array;
}
