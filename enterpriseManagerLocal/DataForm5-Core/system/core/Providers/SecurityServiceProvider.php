<?php
declare(strict_types=1);
namespace DataForm5\Core\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Security\Auth\AuthManager;
use DataForm5\Security\Auth\InMemoryUserProvider;
use DataForm5\Security\Auth\PasswordHasher;
use DataForm5\Security\Authorization\Gate;
use DataForm5\Security\Contracts\SessionStoreInterface;
use DataForm5\Security\Contracts\UserProviderInterface;
use DataForm5\Security\Csrf\CsrfTokenManager;
use DataForm5\Security\Session\ArraySessionStore;
use DataForm5\Security\Session\NativeSessionStore;
final class SecurityServiceProvider implements ServiceProviderInterface {
    public function register(ServiceContainer $c): void {
        $c->singleton(PasswordHasher::class, static fn(ServiceContainer $c) => new PasswordHasher(PASSWORD_BCRYPT, ['cost'=>(int)$c->get(Config::class)->get('security.password.bcrypt_cost',12)]));
        $c->singleton(SessionStoreInterface::class, static function(ServiceContainer $c): SessionStoreInterface { $cfg=$c->get(Config::class); return $cfg->get('security.session.driver','native') === 'array' ? new ArraySessionStore() : new NativeSessionStore((string)$cfg->get('security.session.name','DATAFORM5SESSID')); });
        $c->singleton(UserProviderInterface::class, static fn() => new InMemoryUserProvider());
        $c->singleton(AuthManager::class);
        $c->singleton(Gate::class);
        $c->singleton(CsrfTokenManager::class);
    }
    public function boot(ServiceContainer $container): void {}
}
