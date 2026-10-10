<?php
declare(strict_types=1);
namespace DataForm5\Console\Core;
final class Output
{
    /** @var list<string> */ private array $buffer=[];
    /** @param resource|null $stream */ public function __construct(private $stream=null){$this->stream??=defined('STDOUT')?STDOUT:null;}
    public function write(string $text,bool $newline=false): void{$value=$text.($newline?PHP_EOL:'');$this->buffer[]=$value;if(is_resource($this->stream))fwrite($this->stream,$value);}
    public function line(string $text=''): void{$this->write($text,true);} public function success(string $text):void{$this->line('[OK] '.$text);} public function error(string $text):void{$this->line('[FEHLER] '.$text);} public function warning(string $text):void{$this->line('[WARNUNG] '.$text);}
    /** @param list<array<int|string,mixed>> $rows */ public function table(array $headers,array $rows):void{$widths=[];foreach($headers as $i=>$h)$widths[$i]=strlen((string)$h);foreach($rows as $row)foreach(array_values($row) as $i=>$v)$widths[$i]=max($widths[$i]??0,strlen((string)$v));$render=fn($row)=>implode(' | ',array_map(fn($v,$i)=>str_pad((string)$v,$widths[$i]??0),array_values($row),array_keys(array_values($row))));$this->line($render($headers));$this->line(implode('-+-',array_map(fn($w)=>str_repeat('-',$w),$widths)));foreach($rows as $row)$this->line($render($row));}
    public function contents():string{return implode('',$this->buffer);}
}
