<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Contracts;
use DataForm5\Core\Container\ServiceContainer;
interface ScheduledTaskInterface
{
    public function name(): string;
    public function expression(): string;
    public function run(ServiceContainer $container): void;
    public function preventsOverlapping(): bool;
    public function lockTtl(): int;
}
