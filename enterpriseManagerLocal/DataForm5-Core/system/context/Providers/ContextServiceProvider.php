<?php
declare(strict_types=1);
namespace DataForm5\Context\Providers;
use DataForm5\Context\Contracts\ContextManagerInterface;
use DataForm5\Context\Contracts\ContextResolverInterface;
use DataForm5\Context\Core\ArrayContextResolver;
use DataForm5\Context\Core\ContextManager;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
final class ContextServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(ContextResolverInterface::class, static fn(ServiceContainer $c) => new ArrayContextResolver((array)$c->get(Config::class)->get('context.default', [])));
        $c->singleton(ContextManager::class, static fn() => new ContextManager());
        $c->singleton(ContextManagerInterface::class, static fn(ServiceContainer $c) => $c->get(ContextManager::class));
    }
    public function boot(ServiceContainer $c): void
    {
        $config = $c->get(Config::class);
        if ((bool)$config->get('context.auto_activate', false)) {
            $resolved = $c->get(ContextResolverInterface::class)->resolve();
            if ($resolved !== null) $c->get(ContextManagerInterface::class)->activate($resolved);
        }
    }
}
