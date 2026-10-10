<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Licensing\Contracts\LicenseManagerInterface;use DataForm5\Licensing\Contracts\LicenseProviderInterface;use DataForm5\Licensing\Core\ArrayLicenseProvider;use DataForm5\Licensing\Core\LicenseManager;
final class LicensingServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(LicenseProviderInterface::class,static fn(ServiceContainer $c)=>new ArrayLicenseProvider((array)$c->get(Config::class)->get('licensing',[])));$c->singleton(LicenseManager::class,static fn(ServiceContainer $c)=>new LicenseManager($c->get(LicenseProviderInterface::class),(bool)$c->get(Config::class)->get('licensing.allow_community',true)));$c->singleton(LicenseManagerInterface::class,static fn(ServiceContainer $c)=>$c->get(LicenseManager::class));}
 public function boot(ServiceContainer $c):void{}
}
