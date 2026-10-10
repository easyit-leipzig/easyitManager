<?php
declare(strict_types=1);
namespace DataForm5\Events\Contracts;
interface EventDispatcherInterface
{
    public function dispatch(object $event, ?string $eventName = null): object;
    public function listen(string $eventName, callable $listener, int $priority = 0): void;
    public function subscribe(EventSubscriberInterface $subscriber): void;
}
