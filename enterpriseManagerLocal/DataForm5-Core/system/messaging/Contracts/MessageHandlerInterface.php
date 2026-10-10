<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Contracts;
interface MessageHandlerInterface { public function handle(MessageInterface $message): mixed; }
