<?php
declare(strict_types=1);
namespace DataForm5\ReleaseCandidate\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\ReleaseCandidate\Contracts\ReleaseCandidateManagerInterface;
use DataForm5\ReleaseCandidate\Core\ReleaseCandidateManager;
final class ReleaseCandidateServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(ReleaseCandidateManager::class,function(ServiceContainer $c):ReleaseCandidateManager{
            $cfg=$c->get(Config::class);$base=(string)$cfg->get('app.base_path',dirname(__DIR__,3));
            return new ReleaseCandidateManager(
                $base,
                trim((string)file_get_contents($base.'/VERSION')),
                (string)$cfg->get('release_candidate.build','build0044'),
                $base.'/'.ltrim((string)$cfg->get('release_candidate.report_path','storage/releases/rc-report.json'),'/'),
                (array)$cfg->get('release_candidate.required_files',[]),
                (array)$cfg->get('release_candidate.required_directories',[])
            );
        });
        $c->singleton(ReleaseCandidateManagerInterface::class,fn(ServiceContainer $c)=>$c->get(ReleaseCandidateManager::class));
    }
    public function boot(ServiceContainer $c): void {}
}
