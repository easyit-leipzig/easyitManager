<?php
declare(strict_types=1);

namespace DataForm5\Replication\Providers;

use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Filesystem\StorageManager;
use DataForm5\Core\Kernel;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Replication\Contracts\ReplicationTransportInterface;
use DataForm5\Replication\Core\{ClusterSynchronizer,ReplicationSnapshotService,ReplicationStateStore};
use DataForm5\Replication\Drivers\SharedStorageReplicationTransport;
use DataForm5\Cluster\Security\{NodeSigner,NodeAuthenticator};

final class ReplicationServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(ReplicationTransportInterface::class,function(ServiceContainer $c): ReplicationTransportInterface {
            $cfg=(array)$c->get(Config::class)->get('replication',[]);
            $driver=(string)($cfg['driver']??'shared-storage');
            if($driver!=='shared-storage') throw new \RuntimeException("Unbekannter Replication-Driver '{$driver}'.");
            $disk=(string)($cfg['storage_disk']??'shared');
            return new SharedStorageReplicationTransport(
                $c->get(StorageManager::class)->disk($disk),
                (string)($cfg['base_path']??'replication')
            );
        });
        $c->singleton(ReplicationStateStore::class,function(ServiceContainer $c): ReplicationStateStore {
            $base=$c->get(Kernel::class)->basePath();
            return new ReplicationStateStore($base.'/storage/framework/replication/state.json');
        });
        $c->singleton(ReplicationSnapshotService::class,fn(ServiceContainer $c)=>new ReplicationSnapshotService($c->get(Kernel::class)->basePath()));
        $c->singleton(ClusterSynchronizer::class,function(ServiceContainer $c): ClusterSynchronizer {
            $cfg=(array)$c->get(Config::class)->get('replication',[]);
            return new ClusterSynchronizer(
                $c->get(ReplicationTransportInterface::class),
                $c->get(ReplicationStateStore::class),
                $c->get(EventDispatcherInterface::class),
                (string)($cfg['node_id']??'node-local'),
                (string)($cfg['channel']??'enterprise'),
                (bool)($cfg['security_enabled']??false)?$c->get(NodeSigner::class):null,
                $c->get(NodeAuthenticator::class),
                (bool)($cfg['security_enabled']??false)
            );
        });
    }

    public function boot(ServiceContainer $c): void {}
}
