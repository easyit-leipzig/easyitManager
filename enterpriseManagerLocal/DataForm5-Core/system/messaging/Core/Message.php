<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Core;
use DataForm5\Messaging\Contracts\MessageInterface;
class Message implements MessageInterface
{
 public function __construct(private string $name, private array $data=[]) { if(trim($name)==='') throw new \InvalidArgumentException('Nachrichtenname darf nicht leer sein.'); }
 public function messageName(): string { return $this->name; }
 public function payload(): array { return $this->data; }
}
