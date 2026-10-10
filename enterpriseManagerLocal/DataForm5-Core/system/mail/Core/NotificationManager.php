<?php
declare(strict_types=1);
namespace DataForm5\Mail\Core;
use DataForm5\Mail\Contracts\NotificationInterface;
final class NotificationManager
{
 public function __construct(private readonly MailManager $mail){}
 public function send(array $notifiable,NotificationInterface $notification,?string $mailer=null):void{$this->mail->send($notification->toMail($notifiable),$mailer);}
 public function sendMany(iterable $notifiables,NotificationInterface $notification,?string $mailer=null):void{foreach($notifiables as $n)$this->send((array)$n,$notification,$mailer);}
}
