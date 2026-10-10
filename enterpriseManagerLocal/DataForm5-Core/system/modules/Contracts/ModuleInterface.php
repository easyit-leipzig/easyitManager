<?php
declare(strict_types=1);
namespace DataForm5\Modules\Contracts;
use DataForm5\Core\Container\ServiceContainer;
interface ModuleInterface
{
    public function register(ServiceContainer $container): void;
    public function boot(ServiceContainer $container): void;
}
