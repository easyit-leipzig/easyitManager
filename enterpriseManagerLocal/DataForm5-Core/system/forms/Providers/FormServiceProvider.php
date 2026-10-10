<?php
declare(strict_types=1);
namespace DataForm5\Forms\Providers;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Forms\Core\{FormFactory,FormRenderer};
use DataForm5\Validation\Core\Validator;
use DataForm5\Security\Csrf\CsrfTokenManager;
final class FormServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(FormFactory::class,fn(ServiceContainer $c)=>new FormFactory($c->get(Validator::class)));
        $container->singleton(FormRenderer::class,fn(ServiceContainer $c)=>new FormRenderer($c->get(CsrfTokenManager::class)));
    }
    public function boot(ServiceContainer $container): void {}
}
