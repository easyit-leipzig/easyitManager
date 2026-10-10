<?php
declare(strict_types=1);
namespace DataForm5\Events\Contracts;
interface StoppableEventInterface
{
    public function isPropagationStopped(): bool;
}
