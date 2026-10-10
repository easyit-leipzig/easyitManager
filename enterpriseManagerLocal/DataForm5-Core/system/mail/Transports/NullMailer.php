<?php
declare(strict_types=1);
namespace DataForm5\Mail\Transports;
use DataForm5\Mail\Contracts\MailerInterface; use DataForm5\Mail\Core\Message;
final class NullMailer implements MailerInterface { public function send(Message $message):void{} }
