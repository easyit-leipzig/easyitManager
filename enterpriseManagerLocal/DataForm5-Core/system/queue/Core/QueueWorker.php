<?php
declare(strict_types=1);
namespace DataForm5\Queue\Core;
use DataForm5\Queue\Contracts\QueueInterface;
use DataForm5\Core\Developer\ProfilerHub;
final class QueueWorker
{
    public function runNext(QueueInterface $queue): bool
    {
        $span=ProfilerHub::start('queue','run_next',['queue'=>get_debug_type($queue)]);
        $envelope=$queue->pop(); if($envelope===null){ProfilerHub::stop($span,['empty'=>true]);return false;}
        try { $envelope->job->handle($this->container); $queue->acknowledge($envelope); }
        catch(\Throwable $e){$attempt=$envelope->attempts+1;if($attempt<$envelope->job->maxAttempts())$queue->release($envelope,$envelope->job->retryDelay());else{$failed=$envelope->nextAttempt(time());$queue->fail($failed,$e);}}
        ProfilerHub::stop($span,['job'=>get_debug_type($envelope->job)]);
        return true;
    }
    public function __construct(private readonly \DataForm5\Core\Container\ServiceContainer $container) {}
    public function work(QueueInterface $queue,int $maxJobs=0):int{$count=0;while(($maxJobs===0||$count<$maxJobs)&&$this->runNext($queue))$count++;return $count;}
}
