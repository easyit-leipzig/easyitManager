<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Core;
use Closure;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Messaging\Contracts\{MessageHandlerInterface,MessageInterface,MessageMiddlewareInterface};
use DataForm5\Messaging\Exceptions\MessagingException;
final class MessageBus
{
 private array $handlers=[]; private array $middleware=[];
 public function __construct(private ServiceContainer $container) {}
 public function register(string $messageName, callable|string $handler): self { $this->handlers[$messageName]=$handler; return $this; }
 public function middleware(MessageMiddlewareInterface|callable $middleware): self { $this->middleware[]=$middleware; return $this; }
 public function dispatch(MessageInterface $message): mixed
 {
  $handler=$this->handlers[$message->messageName()]??null;
  if($handler===null) throw new MessagingException("Kein Handler für '{$message->messageName()}' registriert.");
  $core=function(MessageInterface $m) use($handler): mixed {
   $resolved=is_string($handler)?$this->container->get($handler):$handler;
   if($resolved instanceof MessageHandlerInterface) return $resolved->handle($m);
   if(is_callable($resolved)) return $resolved($m,$this->container);
   throw new MessagingException('Registrierter Handler ist nicht aufrufbar.');
  };
  $pipeline=array_reduce(array_reverse($this->middleware),function(Closure $next,$mw):Closure{return function(MessageInterface $m) use($mw,$next):mixed { return $mw instanceof MessageMiddlewareInterface?$mw->process($m,$next):$mw($m,$next); };},$core);
  return $pipeline($message);
 }
}
