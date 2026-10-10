<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Contracts;
interface MessageInterface { public function messageName(): string; public function payload(): array; }
