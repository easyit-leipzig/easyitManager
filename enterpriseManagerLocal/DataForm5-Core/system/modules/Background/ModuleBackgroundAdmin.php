<?php
declare(strict_types=1);

namespace DataForm5\Modules\Background;

use DataForm5\Queue\Core\QueueManager;
use DataForm5\Queue\Core\QueueWorker;
use DataForm5\Queue\Drivers\FileQueue;
use DataForm5\Scheduler\Core\ScheduleHistory;
use DataForm5\Scheduler\Core\Scheduler;

final class ModuleBackgroundAdmin
{
    public function __construct(
        private readonly ModuleBackgroundRegistry $registry,
        private readonly QueueManager $queues,
        private readonly QueueWorker $worker,
        private readonly Scheduler $scheduler,
        private readonly ScheduleHistory $history
    ) {}

    public function overview(string $connection='file'): array
    {
        $queue=$this->queues->connection($connection);
        $stats=['pending'=>$queue->size(),'processing'=>0,'failed'=>0];
        $failed=[];
        if($queue instanceof FileQueue){
            $stats=$queue->statistics();
            $failed=$queue->failedJobs(100);
        }
        $tasks=[];
        foreach($this->scheduler->tasks() as $task){
            $tasks[]=[
                'name'=>$task->name(),
                'cron'=>$task->expression(),
                'due'=>in_array($task,$this->scheduler->due(),true),
                'without_overlapping'=>$task->preventsOverlapping(),
                'lock_ttl'=>$task->lockTtl(),
            ];
        }
        return [
            'connection'=>$connection,
            'stats'=>$stats,
            'failed'=>$failed,
            'jobs'=>$this->registry->jobs(),
            'schedules'=>$this->registry->schedules(),
            'tasks'=>$tasks,
            'history'=>$this->history->recent(100),
        ];
    }

    public function retryFailed(string $id,string $connection='file'): bool
    {
        $queue=$this->queues->connection($connection);
        return $queue instanceof FileQueue && $queue->retryFailed($id);
    }

    public function deleteFailed(string $id,string $connection='file'): bool
    {
        $queue=$this->queues->connection($connection);
        return $queue instanceof FileQueue && $queue->deleteFailed($id);
    }

    public function work(string $connection='file',int $maxJobs=25): int
    {
        return $this->worker->work($this->queues->connection($connection),max(1,$maxJobs));
    }

    public function runDue(): array
    {
        return $this->scheduler->runDue();
    }

    public function dispatch(string $job,array $payload=[]): string
    {
        return $this->registry->dispatch($job,$payload);
    }
}
