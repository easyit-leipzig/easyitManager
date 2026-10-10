<?php
declare(strict_types=1);
namespace DataForm5\Compatibility\Providers;
use DataForm5\Compatibility\Contracts\CompatibilityManagerInterface;
use DataForm5\Compatibility\Core\CompatibilityManager;
use DataForm5\Compatibility\Core\DeprecationRegistry;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
final class CompatibilityServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(DeprecationRegistry::class, function(ServiceContainer $c): DeprecationRegistry {
            $registry=new DeprecationRegistry();
            foreach((array)$c->get(Config::class)->get('compatibility.deprecations',[]) as $id=>$item){$registry->add((string)$id,(string)$item['since'],(string)($item['replacement']??''),isset($item['remove_in'])?(string)$item['remove_in']:null);} return $registry;
        });
        $c->singleton(CompatibilityManager::class, function(ServiceContainer $c): CompatibilityManager {
            $cfg=$c->get(Config::class); return new CompatibilityManager((string)$cfg->get('compatibility.current','1.0.0'),(string)$cfg->get('compatibility.minimum','0.1.0'),(array)$cfg->get('compatibility.upgrade_steps',[]),$c->get(DeprecationRegistry::class));
        });
        $c->singleton(CompatibilityManagerInterface::class,fn(ServiceContainer $c)=>$c->get(CompatibilityManager::class));
    }
    public function boot(ServiceContainer $c): void {}
}
