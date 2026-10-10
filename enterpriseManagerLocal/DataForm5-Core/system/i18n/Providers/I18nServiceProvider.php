<?php
declare(strict_types=1);
namespace DataForm5\I18n\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\I18n\Contracts\TranslatorInterface;use DataForm5\I18n\Core\{LocaleFormatter,Translator};
final class I18nServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{
  $c->singleton(Translator::class,fn(ServiceContainer $c)=>new Translator((string)$c->get(Config::class)->get('i18n.path'),(string)$c->get(Config::class)->get('i18n.locale','de_DE'),(string)$c->get(Config::class)->get('i18n.fallback_locale','en_US')));
  $c->singleton(TranslatorInterface::class,fn(ServiceContainer $c)=>$c->get(Translator::class));
  $c->singleton(LocaleFormatter::class,fn(ServiceContainer $c)=>new LocaleFormatter((string)$c->get(Config::class)->get('i18n.locale','de_DE'),(string)$c->get(Config::class)->get('app.timezone','Europe/Berlin')));
 }
 public function boot(ServiceContainer $container):void{}
}
