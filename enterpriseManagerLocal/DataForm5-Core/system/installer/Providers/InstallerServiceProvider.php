<?php
declare(strict_types=1);
namespace DataForm5\Installer\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;use DataForm5\Installer\Contracts\InstallerInterface;use DataForm5\Installer\Core\InstallationLock;use DataForm5\Installer\Core\Installer;use DataForm5\Installer\Core\SystemInspector;use DataForm5\Installer\Core\EnvironmentWriter;use DataForm5\Installer\Core\AdminDatabaseInstaller;use DataForm5\Installer\Core\InstallationHealthGate;use DataForm5\Installer\Core\EnterpriseInstaller;
final class InstallerServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void
 {
  $c->singleton(SystemInspector::class,function(ServiceContainer $c){$cfg=$c->get(Config::class);return new SystemInspector($c->get(Path::class)->base(),(string)$cfg->get('installer.minimum_php','8.2.0'));});
  $c->singleton(InstallationLock::class,function(ServiceContainer $c){$cfg=$c->get(Config::class);return new InstallationLock((string)$cfg->get('installer.lock_file',$c->get(Path::class)->storage('framework/installer/installed.json')));});
  $c->singleton(Installer::class,fn(ServiceContainer $c)=>new Installer($c->get(Path::class)->base(),$c->get(SystemInspector::class),$c->get(InstallationLock::class)));
  $c->singleton(InstallerInterface::class,fn(ServiceContainer $c)=>$c->get(Installer::class));
  $c->singleton(EnvironmentWriter::class,fn(ServiceContainer $c)=>new EnvironmentWriter($c->get(Path::class)->base('.env')));
  $c->singleton(AdminDatabaseInstaller::class);
  $c->singleton(InstallationHealthGate::class);
  $c->singleton(EnterpriseInstaller::class,fn(ServiceContainer $c)=>new EnterpriseInstaller(
      dirname($c->get(Path::class)->base()),
      $c->get(Path::class)->base(),
      $c->get(SystemInspector::class),
      $c->get(InstallationLock::class),
      $c->get(EnvironmentWriter::class),
      $c->get(AdminDatabaseInstaller::class),
      $c->get(InstallationHealthGate::class)
  ));
 }
 public function boot(ServiceContainer $c):void{}
}
