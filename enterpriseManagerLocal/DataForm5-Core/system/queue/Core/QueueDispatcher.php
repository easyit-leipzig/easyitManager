<?php
declare(strict_types=1);
namespace DataForm5\Queue\Core;
use DataForm5\Queue\Contracts\JobInterface;
final class QueueDispatcher
{
    public function __construct(private readonly QueueManager $manager) {}
    public function dispatch(JobInterface $job,int $delay=0,?string $connection=null):string{return $this->manager->connection($connection)->push($job,$delay);}
}
