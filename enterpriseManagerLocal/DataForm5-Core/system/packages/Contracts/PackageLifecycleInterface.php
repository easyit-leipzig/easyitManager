<?php
declare(strict_types=1);
namespace DataForm5\Packages\Contracts;
use DataForm5\Core\Container\ServiceContainer;
interface PackageLifecycleInterface
{
    public function install(ServiceContainer $container): void;
    public function update(ServiceContainer $container, string $fromVersion): void;
    public function uninstall(ServiceContainer $container): void;
}
