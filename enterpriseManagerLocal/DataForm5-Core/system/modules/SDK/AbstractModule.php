<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Modules\Contracts\ModuleInterface;
abstract class AbstractModule implements ModuleInterface
{
    public function register(ServiceContainer $container): void {}
    public function boot(ServiceContainer $container): void {}
}
