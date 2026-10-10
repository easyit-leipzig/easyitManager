<?php
declare(strict_types=1);
namespace DataForm5\Validation\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Validation\Core\Validator;
final class ValidationServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(Validator::class, static fn(ServiceContainer $c): Validator => new Validator((array)$c->get(Config::class)->get('validation.messages', [])));
    }
    public function boot(ServiceContainer $container): void {}
}
