<?php
declare(strict_types=1);
namespace DataForm5\Mail\Contracts;
use DataForm5\Mail\Core\Message;
interface NotificationInterface { public function toMail(array $notifiable): Message; }
