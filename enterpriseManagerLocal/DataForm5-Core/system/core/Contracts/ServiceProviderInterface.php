<?php
declare(strict_types=1);

namespace DataForm5\Core\Contracts;

use DataForm5\Core\Container\ServiceContainer;

interface ServiceProviderInterface
{
    public function register(ServiceContainer $container): void;
    public function boot(ServiceContainer $container): void;
}
