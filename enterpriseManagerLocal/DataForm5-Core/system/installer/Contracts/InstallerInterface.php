<?php
declare(strict_types=1);
namespace DataForm5\Installer\Contracts;
interface InstallerInterface
{
    public function inspect(): array;
    public function install(array $configuration = []): array;
    public function isInstalled(): bool;
    public function status(): array;
}
