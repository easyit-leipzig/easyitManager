<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Providers;

use DataForm5\Cluster\Core\{ClusterElection,ClusterHeartbeat,ClusterHealth,ClusterManager,ClusterRegistry,ClusterLock};
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Kernel;
use DataForm5\Scheduler\Contracts\MutexInterface;
use DataForm5\Cluster\Security\{NodeTrustStore,ReplayGuard,NodeSigner,NodeAuthenticator};

final class ClusterServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(NodeTrustStore::class,function(ServiceContainer $c): NodeTrustStore {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            $base=$c->get(Kernel::class)->basePath();
            $path=(string)($cfg['trust_store_path']??'storage/framework/cluster/trusted-nodes.json');
            if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:[\\\\\\/]/',$path)) $path=$base.'/'.ltrim($path,'/\\');
            return new NodeTrustStore($path);
        });
        $c->singleton(ReplayGuard::class,function(ServiceContainer $c): ReplayGuard {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            $base=$c->get(Kernel::class)->basePath();
            $path=(string)($cfg['replay_store_path']??'storage/framework/cluster/replay.json');
            if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:[\\\\\\/]/',$path)) $path=$base.'/'.ltrim($path,'/\\');
            return new ReplayGuard($path,(int)($cfg['signature_ttl']??120));
        });
        $c->singleton(NodeSigner::class,function(ServiceContainer $c): NodeSigner {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            return new NodeSigner((string)($cfg['node_id']??'node-local'),(string)($cfg['node_secret']??''));
        });
        $c->singleton(NodeAuthenticator::class,function(ServiceContainer $c): NodeAuthenticator {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            return new NodeAuthenticator(
                $c->get(NodeTrustStore::class),
                $c->get(ReplayGuard::class),
                (bool)($cfg['security_enabled']??false)
            );
        });
        $c->singleton(ClusterRegistry::class,function(ServiceContainer $c): ClusterRegistry {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            $base=$c->get(Kernel::class)->basePath();
            $path=(string)($cfg['registry_path']??'storage/framework/cluster/nodes.json');
            if(!str_starts_with($path,'/') && !preg_match('/^[A-Za-z]:[\\\\\\/]/',$path)) $path=$base.'/'.ltrim($path,'/\\\\');
            return new ClusterRegistry($path);
        });
        $c->singleton(ClusterElection::class,fn(ServiceContainer $c)=>new ClusterElection((int)$c->get(Config::class)->get('cluster.heartbeat_ttl',90)));
        $c->singleton(ClusterHeartbeat::class,function(ServiceContainer $c): ClusterHeartbeat {
            $cfg=(array)$c->get(Config::class)->get('cluster',[]);
            $versionFile=$c->get(Kernel::class)->basePath().'/VERSION';
            return new ClusterHeartbeat(
                $c->get(ClusterRegistry::class),
                (string)($cfg['node_id']??'node-local'),
                (string)($cfg['node_name']??'Local Node'),
                is_file($versionFile)?(string)file_get_contents($versionFile):'dev',
                (bool)($cfg['security_enabled']??false)?$c->get(NodeSigner::class):null
            );
        });
        $c->singleton(ClusterHealth::class,fn(ServiceContainer $c)=>new ClusterHealth(
            $c->get(ClusterRegistry::class),
            $c->get(ClusterElection::class),
            (int)$c->get(Config::class)->get('cluster.heartbeat_ttl',90),
            $c->get(NodeAuthenticator::class)
        ));
        $c->singleton(ClusterLock::class,fn(ServiceContainer $c)=>new ClusterLock($c->get(MutexInterface::class)));
        $c->singleton(ClusterManager::class,fn(ServiceContainer $c)=>new ClusterManager(
            $c->get(ClusterHeartbeat::class),
            $c->get(ClusterHealth::class),
            $c->get(ClusterRegistry::class)
        ));
    }

    public function boot(ServiceContainer $c): void {}
}
