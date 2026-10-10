<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Core;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Scheduler\Contracts\ScheduledTaskInterface;
final class ScheduledTask implements ScheduledTaskInterface
{
    private bool $preventOverlap=false; private int $ttl=3600;
    public function __construct(private readonly string $taskName,private string $cron,private readonly \Closure $callback){}
    public function name():string{return $this->taskName;}
    public function expression():string{return $this->cron;}
    public function run(ServiceContainer $container):void{($this->callback)($container);}
    public function withoutOverlapping(int $ttl=3600):self{$this->preventOverlap=true;$this->ttl=max(1,$ttl);return $this;}
    public function preventsOverlapping():bool{return $this->preventOverlap;}
    public function lockTtl():int{return $this->ttl;}
    public function cron(string $expression):self{$this->cron=$expression;return $this;}
    public function everyMinute():self{return $this->cron('* * * * *');}
    public function hourly():self{return $this->cron('0 * * * *');}
    public function dailyAt(string $time):self{[$h,$m]=array_map('intval',explode(':',$time,2));return $this->cron("{$m} {$h} * * *");}
}
