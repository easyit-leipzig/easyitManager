<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;
use DataForm5\Secrets\Contracts\{EncrypterInterface,SecretStoreInterface};use DataForm5\Secrets\Core\{Encrypter,KeyRing,SecretManager};use DataForm5\Secrets\Stores\{EncryptedFileSecretStore,InMemorySecretStore};use DataForm5\Secrets\Exceptions\SecretException;
final class SecretServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void
 {
  $c->singleton(KeyRing::class,static function(ServiceContainer $c):KeyRing{$cfg=$c->get(Config::class);$active=(string)$cfg->get('secrets.active_key','main');$keys=(array)$cfg->get('secrets.keys',[]);return new KeyRing($active,array_map('strval',$keys));});
  $c->singleton(Encrypter::class,static fn(ServiceContainer $c)=>new Encrypter($c->get(KeyRing::class)));$c->singleton(EncrypterInterface::class,static fn(ServiceContainer $c)=>$c->get(Encrypter::class));
  $c->singleton(SecretStoreInterface::class,static function(ServiceContainer $c):SecretStoreInterface{$cfg=$c->get(Config::class);$driver=(string)$cfg->get('secrets.driver','file');return match($driver){'memory'=>new InMemorySecretStore(),'file'=>new EncryptedFileSecretStore($c->get(Path::class)->base((string)$cfg->get('secrets.path','storage/framework/secrets/secrets.json')),$c->get(EncrypterInterface::class)),default=>throw new SecretException("Unbekannter Secret-Treiber '{$driver}'.")};});
  $c->singleton(SecretManager::class,static fn(ServiceContainer $c)=>new SecretManager($c->get(SecretStoreInterface::class)));
 }
 public function boot(ServiceContainer $c):void{}
}
