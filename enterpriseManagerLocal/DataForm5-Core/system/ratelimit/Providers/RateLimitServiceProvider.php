<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;
use DataForm5\RateLimit\Contracts\{RateLimiterInterface,RateLimitStoreInterface};use DataForm5\RateLimit\Core\RateLimiter;use DataForm5\RateLimit\Stores\{InMemoryRateLimitStore,JsonFileRateLimitStore};
final class RateLimitServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void
    {
        $c->singleton(RateLimitStoreInterface::class,static function(ServiceContainer $c):RateLimitStoreInterface{$cfg=$c->get(Config::class);$driver=(string)$cfg->get('rate_limit.driver','file');return match($driver){'memory'=>new InMemoryRateLimitStore(),'file'=>new JsonFileRateLimitStore($c->get(Path::class)->base((string)$cfg->get('rate_limit.path','storage/framework/rate-limit'))),default=>throw new \DataForm5\RateLimit\Exceptions\RateLimitException("Unbekannter Rate-Limit-Treiber '{$driver}'.")};});
        $c->singleton(RateLimiter::class,static fn(ServiceContainer $c)=>new RateLimiter($c->get(RateLimitStoreInterface::class),(string)$c->get(Config::class)->get('rate_limit.prefix','df5')));
        $c->singleton(RateLimiterInterface::class,static fn(ServiceContainer $c)=>$c->get(RateLimiter::class));
    }
    public function boot(ServiceContainer $c):void{}
}
