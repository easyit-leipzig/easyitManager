<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

final class ProfilerHub
{
    private static ?RequestProfiler $profiler=null;

    public static function install(RequestProfiler $profiler): void
    {
        self::$profiler=$profiler;
    }

    public static function record(string $category,string $operation,float $durationMs=0.0,array $context=[]): void
    {
        self::$profiler?->record($category,$operation,$durationMs,$context);
    }

    public static function start(string $category,string $operation,array $context=[]): ?string
    {
        return self::$profiler?->start($category,$operation,$context);
    }

    public static function stop(?string $token,array $context=[]): void
    {
        if($token!==null) self::$profiler?->stop($token,$context);
    }
}
