<?php
declare(strict_types=1);
namespace DataForm5\Testing\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Testing\Core\{LegacyScriptRunner,QualityGate,TestRunner,DeveloperQualityCenter};
final class TestingServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void{$c->singleton(TestRunner::class,fn()=>new TestRunner());$c->singleton(LegacyScriptRunner::class,fn()=>new LegacyScriptRunner());$c->singleton(QualityGate::class,fn(ServiceContainer $c)=>new QualityGate(dirname(__DIR__,3),$c->get(LegacyScriptRunner::class)));$c->singleton(DeveloperQualityCenter::class,fn()=>new DeveloperQualityCenter(dirname(__DIR__,4)));}
    public function boot(ServiceContainer $c):void{}
}
