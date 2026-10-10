<?php
declare(strict_types=1);
namespace DataForm5\Help\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Help\Core\{FileHelpProvider,HelpRegistry,HelpRenderer,HelpResolver};
final class HelpServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void{$base=dirname(__DIR__,3);$c->singleton(HelpRegistry::class,function()use($base){$r=new HelpRegistry();$r->addProvider(new FileHelpProvider($base.'/resources/help/de_DE'));return $r;});$c->singleton(HelpResolver::class,fn(ServiceContainer $c)=>new HelpResolver($c->get(HelpRegistry::class)));$c->singleton(HelpRenderer::class,fn()=>new HelpRenderer());}
    public function boot(ServiceContainer $c):void{}
}
