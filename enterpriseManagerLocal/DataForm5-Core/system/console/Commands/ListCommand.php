<?php
declare(strict_types=1);
namespace DataForm5\Console\Commands;
use DataForm5\Console\Core\{AbstractCommand,CommandRegistry,Input,Output};
final class ListCommand extends AbstractCommand
{
    public function __construct(private CommandRegistry $registry){} public function name():string{return 'list';} public function description():string{return 'Zeigt alle verfügbaren Konsolenbefehle.';}
    public function execute(Input $input,Output $output):int{$rows=[];foreach($this->registry->all() as $command)$rows[]=[$command->name(),$command->description()];$output->line('DataForm5-Core Konsole');$output->table(['Befehl','Beschreibung'],$rows);return 0;}
}
