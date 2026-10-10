<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Contracts;
use Closure;
interface MessageMiddlewareInterface { public function process(MessageInterface $message, Closure $next): mixed; }
