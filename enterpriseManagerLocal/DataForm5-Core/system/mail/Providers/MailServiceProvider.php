<?php
declare(strict_types=1);
namespace DataForm5\Mail\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Logging\Contracts\LoggerInterface;use DataForm5\Mail\Contracts\MailerInterface;use DataForm5\Mail\Core\{MailManager,NotificationManager,TemplateRenderer};
final class MailServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(MailManager::class,fn(ServiceContainer $c)=>new MailManager($c->get(Config::class),$c->get(LoggerInterface::class)));$c->singleton(MailerInterface::class,fn(ServiceContainer $c)=>$c->get(MailManager::class)->mailer());$c->singleton(NotificationManager::class,fn(ServiceContainer $c)=>new NotificationManager($c->get(MailManager::class)));$c->singleton(TemplateRenderer::class,fn()=>new TemplateRenderer());}
 public function boot(ServiceContainer $container):void{}
}
