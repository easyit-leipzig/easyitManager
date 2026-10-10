<?php
declare(strict_types=1);
namespace DataForm5\Events\Core;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Events\Contracts\EventSubscriberInterface;
use DataForm5\Events\Contracts\StoppableEventInterface;
use DataForm5\Events\Exceptions\EventException;
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string,array<int,list<callable>>> */
    private array $listeners = [];
    /** @var array<string,list<callable>> */
    private array $sorted = [];

    public function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][$priority][] = $listener;
        unset($this->sorted[$eventName]);
    }

    public function subscribe(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventName => $definition) {
            $priority = 0;
            $listener = $definition;
            if (is_string($definition)) {
                $listener = [$subscriber, $definition];
            } elseif (is_array($definition) && count($definition) === 2 && is_int($definition[1])) {
                $priority = $definition[1];
                $listener = is_string($definition[0]) ? [$subscriber, $definition[0]] : $definition[0];
            }
            if (!is_callable($listener)) {
                throw new EventException("Ungültiger Listener für Event {$eventName}.");
            }
            $this->listen((string)$eventName, $listener, $priority);
        }
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $names = [];
        if ($eventName !== null && $eventName !== '') $names[] = $eventName;
        $class = $event::class;
        $names[] = $class;
        foreach (class_parents($event) ?: [] as $parent) $names[] = $parent;
        foreach (class_implements($event) ?: [] as $interface) $names[] = $interface;
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            foreach ($this->listenersFor($name) as $listener) {
                $listener($event, $name, $this);
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) return $event;
            }
        }
        return $event;
    }

    /** @return list<callable> */
    public function listenersFor(string $eventName): array
    {
        if (isset($this->sorted[$eventName])) return $this->sorted[$eventName];
        $groups = $this->listeners[$eventName] ?? [];
        if ($groups === []) return $this->sorted[$eventName] = [];
        krsort($groups, SORT_NUMERIC);
        $result = [];
        foreach ($groups as $listeners) foreach ($listeners as $listener) $result[] = $listener;
        return $this->sorted[$eventName] = $result;
    }

    public function describeListeners(): array
    {
        $result=[];
        foreach($this->listeners as $name=>$groups){
            $count=0;
            $priorities=[];
            foreach($groups as $priority=>$listeners){
                $count+=count($listeners);
                $priorities[(string)$priority]=count($listeners);
            }
            $result[$name]=['listeners'=>$count,'priorities'=>$priorities];
        }
        ksort($result);
        return $result;
    }

    public function forget(string $eventName): void
    {
        unset($this->listeners[$eventName], $this->sorted[$eventName]);
    }
}
