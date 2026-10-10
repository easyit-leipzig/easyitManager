<?php
declare(strict_types=1);
namespace DataForm5\Mail\Contracts;
use DataForm5\Mail\Core\Message;
interface MailerInterface { public function send(Message $message): void; }
