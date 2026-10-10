<?php
declare(strict_types=1);
namespace DataForm5\Logging\Core;
use DataForm5\Logging\Contracts\LoggerInterface;
abstract class AbstractLogger implements LoggerInterface
{
    public function emergency(string $m,array $c=[]):void{$this->log(LogLevel::EMERGENCY,$m,$c);} public function alert(string $m,array $c=[]):void{$this->log(LogLevel::ALERT,$m,$c);} public function critical(string $m,array $c=[]):void{$this->log(LogLevel::CRITICAL,$m,$c);} public function error(string $m,array $c=[]):void{$this->log(LogLevel::ERROR,$m,$c);} public function warning(string $m,array $c=[]):void{$this->log(LogLevel::WARNING,$m,$c);} public function notice(string $m,array $c=[]):void{$this->log(LogLevel::NOTICE,$m,$c);} public function info(string $m,array $c=[]):void{$this->log(LogLevel::INFO,$m,$c);} public function debug(string $m,array $c=[]):void{$this->log(LogLevel::DEBUG,$m,$c);}
    protected function interpolate(string $message,array $context):string { $replace=[]; foreach($context as $k=>$v){if(is_scalar($v)||$v===null)$replace['{'.$k.'}']=(string)$v;} return strtr($message,$replace); }
}
