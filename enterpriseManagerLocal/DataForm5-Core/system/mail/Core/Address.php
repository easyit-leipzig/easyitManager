<?php
declare(strict_types=1);
namespace DataForm5\Mail\Core;
use DataForm5\Mail\Exceptions\MailException;
final class Address
{
 public function __construct(public readonly string $email, public readonly string $name='') { if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new MailException("Ungültige E-Mail-Adresse: {$email}"); }
 public function format():string{return $this->name!==''?'"'.addcslashes($this->name,'"\\').'" <'.$this->email.'>':$this->email;}
}
