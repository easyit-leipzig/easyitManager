<?php
declare(strict_types=1);
namespace DataForm5\Mail\Transports;
use DataForm5\Mail\Contracts\MailerInterface;use DataForm5\Mail\Core\Message;use DataForm5\Mail\Exceptions\MailException;
final class NativeMailer implements MailerInterface
{
 public function send(Message $m):void { $to=implode(', ',array_map(fn($a)=>$a->format(),$m->recipients())); if($to==='')throw new MailException('Mindestens ein Empfänger ist erforderlich.'); $headers=[]; if($m->fromAddress())$headers[]='From: '.$m->fromAddress()->format(); if($m->htmlBody()!==''){$headers[]='MIME-Version: 1.0';$headers[]='Content-Type: text/html; charset=UTF-8';$body=$m->htmlBody();}else{$headers[]='Content-Type: text/plain; charset=UTF-8';$body=$m->textBody();} foreach($m->headers() as $k=>$v)$headers[]=$k.': '.$v; if(!mail($to,$m->getSubject(),$body,implode("\r\n",$headers)))throw new MailException('PHP mail() konnte die Nachricht nicht versenden.'); }
}
