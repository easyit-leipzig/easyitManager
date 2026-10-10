<?php
declare(strict_types=1);
namespace DataForm5\Events\Core;
use DataForm5\Events\Contracts\StoppableEventInterface;
class Event implements StoppableEventInterface
{
    private bool $propagationStopped = false;
    public function stopPropagation(): void { $this->propagationStopped = true; }
    public function isPropagationStopped(): bool { return $this->propagationStopped; }
}
