<?php
declare(strict_types=1);
namespace DataForm5\Modules\Lifecycle;
use DataForm5\Events\Core\Event;
final class ModuleLifecycleEvent extends Event
{
    public readonly string $occurredAt;
    public function __construct(
        public readonly string $module,
        public readonly string $hook,
        public readonly string $phase,
        public readonly array $context = [],
        public readonly ?string $eventId = null
    ) { $this->occurredAt=gmdate('c'); }
}
