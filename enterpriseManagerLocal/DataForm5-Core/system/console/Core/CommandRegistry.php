<?php
declare(strict_types=1);
namespace DataForm5\Console\Core;
use DataForm5\Console\Contracts\CommandInterface;use DataForm5\Console\Exceptions\ConsoleException;
final class CommandRegistry
{
    /** @var array<string,CommandInterface> */ private array $commands=[];
    public function add(CommandInterface $command):void{$name=trim($command->name());if($name==='')throw new ConsoleException('Befehlsname darf nicht leer sein.');$this->commands[$name]=$command;ksort($this->commands);}
    public function get(string $name):CommandInterface{if(!isset($this->commands[$name]))throw new ConsoleException("Befehl '{$name}' wurde nicht gefunden.");return $this->commands[$name];}
    /** @return array<string,CommandInterface> */ public function all():array{return $this->commands;}
}
