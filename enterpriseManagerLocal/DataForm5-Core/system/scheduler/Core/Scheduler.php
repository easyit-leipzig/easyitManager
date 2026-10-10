<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Core;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Scheduler\Contracts\ScheduledTaskInterface;
use DataForm5\Scheduler\Contracts\MutexInterface;
use DataForm5\Core\Developer\ProfilerHub;
final class Scheduler
{
    /** @var array<string,ScheduledTaskInterface> */ private array $tasks=[];
    public function __construct(private readonly ServiceContainer $container,private readonly MutexInterface $mutex,private readonly ScheduleHistory $history){}
    public function task(string $name,string $expression,callable $callback):ScheduledTask
    {
        $closure=$callback instanceof \Closure?$callback:\Closure::fromCallable($callback);$task=new ScheduledTask($name,$expression,$closure);$this->add($task);return $task;
    }
    public function add(ScheduledTaskInterface $task):self{$this->tasks[$task->name()]=$task;return $this;}
    public function due(?\DateTimeInterface $time=null):array
    {$time??=new \DateTimeImmutable();return array_values(array_filter($this->tasks,fn($t)=>(new CronExpression($t->expression()))->isDue($time)));}
    public function runDue(?\DateTimeInterface $time=null):array
    {
        $span=ProfilerHub::start('scheduler','run_due');
        $results=[]; foreach($this->due($time) as $task){$locked=false;$started=new \DateTimeImmutable();
            if($task->preventsOverlapping()){if(!$this->mutex->acquire($task->name(),$task->lockTtl())){$results[$task->name()]='skipped';continue;}$locked=true;}
            try{$task->run($this->container);$this->history->record($task->name(),'success',$started);$results[$task->name()]='success';}
            catch(\Throwable $e){$this->history->record($task->name(),'failed',$started,$e);$results[$task->name()]='failed';}
            finally{if($locked)$this->mutex->release($task->name());}
        } ProfilerHub::stop($span,['tasks'=>count($results)]); return $results;
    }
    public function tasks():array{return array_values($this->tasks);}
}
