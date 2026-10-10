<?php
declare(strict_types=1);
namespace DataForm5\Mail\Transports;
use DataForm5\Logging\Contracts\LoggerInterface; use DataForm5\Mail\Contracts\MailerInterface; use DataForm5\Mail\Core\Message;
final class LogMailer implements MailerInterface
{
 public function __construct(private readonly LoggerInterface $logger){}
 public function send(Message $m):void{$this->logger->info('Mail: {subject}',['subject'=>$m->getSubject(),'to'=>array_map(fn($a)=>$a->email,$m->recipients()),'text'=>$m->textBody(),'html'=>$m->htmlBody()]);}
}
