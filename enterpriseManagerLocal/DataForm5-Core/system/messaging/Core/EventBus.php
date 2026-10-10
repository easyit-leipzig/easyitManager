<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Core;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Messaging\Contracts\MessageInterface;
final class EventBus
{
 private array $subscribers=[];
 public function __construct(private ServiceContainer $container) {}
 public function subscribe(string $eventName, callable|string $subscriber): self { $this->subscribers[$eventName][]=$subscriber; return $this; }
 public function publish(MessageInterface $event): array
 {
  $results=[]; foreach($this->subscribers[$event->messageName()]??[] as $subscriber){$resolved=is_string($subscriber)?$this->container->get($subscriber):$subscriber;$results[]=is_callable($resolved)?$resolved($event,$this->container):$resolved->handle($event);} return $results;
 }
}
