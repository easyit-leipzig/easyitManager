<?php
declare(strict_types=1);
namespace DataForm5\Queue\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Kernel;
use DataForm5\Queue\Contracts\QueueInterface;
use DataForm5\Queue\Core\QueueDispatcher;
use DataForm5\Queue\Core\QueueManager;
use DataForm5\Queue\Core\QueueWorker;
final class QueueServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void
    {
        $c->singleton(QueueManager::class,function(ServiceContainer $c):QueueManager{$config=$c->get(Config::class);$kernel=$c->get(Kernel::class);return new QueueManager($c,(array)$config->get('queue',[]),$kernel->basePath());});
        $c->singleton(QueueInterface::class,fn(ServiceContainer $c)=>$c->get(QueueManager::class)->connection());
        $c->singleton(QueueDispatcher::class,fn(ServiceContainer $c)=>new QueueDispatcher($c->get(QueueManager::class)));
        $c->singleton(QueueWorker::class,fn(ServiceContainer $c)=>new QueueWorker($c));
    }
    public function boot(ServiceContainer $c):void{}
}
