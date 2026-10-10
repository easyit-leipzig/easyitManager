<?php
declare(strict_types=1);
namespace DataForm5\FinalRelease\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\FinalRelease\Contracts\FinalReleaseManagerInterface;
use DataForm5\FinalRelease\Core\FinalReleaseManager;
final class FinalReleaseServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(FinalReleaseManager::class,function(ServiceContainer $c):FinalReleaseManager{
            $cfg=$c->get(Config::class);$base=(string)$cfg->get('app.base_path',dirname(__DIR__,3));
            return new FinalReleaseManager(
                $base,
                trim((string)file_get_contents($base.'/VERSION')),
                (string)$cfg->get('final_release.build','build0045'),
                $base.'/'.ltrim((string)$cfg->get('final_release.report_path','storage/releases/final-report.json'),'/'),
                $base.'/'.ltrim((string)$cfg->get('final_release.manifest_path','storage/releases/final-manifest.json'),'/'),
                (array)$cfg->get('final_release.required_files',[]),
                (array)$cfg->get('final_release.required_directories',[]),
                (array)$cfg->get('final_release.excluded_paths',[])
            );
        });
        $c->singleton(FinalReleaseManagerInterface::class,fn(ServiceContainer $c)=>$c->get(FinalReleaseManager::class));
    }
    public function boot(ServiceContainer $c): void {}
}
