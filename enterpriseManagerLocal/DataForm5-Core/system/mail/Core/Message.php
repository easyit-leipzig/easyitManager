<?php
declare(strict_types=1);
namespace DataForm5\Mail\Core;
final class Message
{
 /** @var list<Address> */ private array $to=[]; /** @var list<Address> */ private array $cc=[]; /** @var list<Address> */ private array $bcc=[]; private ?Address $from=null; private string $subject=''; private string $text=''; private string $html=''; private array $headers=[];
 public function from(string $email,string $name=''):self{$this->from=new Address($email,$name);return $this;}
 public function to(string $email,string $name=''):self{$this->to[]=new Address($email,$name);return $this;}
 public function cc(string $email,string $name=''):self{$this->cc[]=new Address($email,$name);return $this;}
 public function bcc(string $email,string $name=''):self{$this->bcc[]=new Address($email,$name);return $this;}
 public function subject(string $subject):self{$this->subject=$subject;return $this;}
 public function text(string $text):self{$this->text=$text;return $this;}
 public function html(string $html):self{$this->html=$html;return $this;}
 public function header(string $name,string $value):self{$this->headers[$name]=$value;return $this;}
 public function fromAddress():?Address{return $this->from;} public function recipients():array{return $this->to;} public function ccRecipients():array{return $this->cc;} public function bccRecipients():array{return $this->bcc;} public function getSubject():string{return $this->subject;} public function textBody():string{return $this->text;} public function htmlBody():string{return $this->html;} public function headers():array{return $this->headers;}
}
