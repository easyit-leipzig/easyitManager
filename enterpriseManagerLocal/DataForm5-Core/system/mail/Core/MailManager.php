<?php
declare(strict_types=1);
namespace DataForm5\Mail\Core;
use DataForm5\Core\Config;use DataForm5\Logging\Contracts\LoggerInterface;use DataForm5\Mail\Contracts\MailerInterface;use DataForm5\Mail\Exceptions\MailException;use DataForm5\Mail\Transports\{LogMailer,NativeMailer,NullMailer};
final class MailManager
{
 private array $mailers=[];
 public function __construct(private readonly Config $config,private readonly LoggerInterface $logger){}
 public function mailer(?string $name=null):MailerInterface{$name??=(string)$this->config->get('mail.default','log');return $this->mailers[$name]??=$this->create($name);}
 public function send(Message $message,?string $mailer=null):void{$from=$message->fromAddress();if(!$from){$message->from((string)$this->config->get('mail.from.address','noreply@example.test'),(string)$this->config->get('mail.from.name','DataForm5'));}$this->mailer($mailer)->send($message);}
 private function create(string $name):MailerInterface{return match($name){'log'=>new LogMailer($this->logger),'native'=>new NativeMailer(),'null'=>new NullMailer(),default=>throw new MailException("Unbekannter Mail-Treiber: {$name}")};}
}
