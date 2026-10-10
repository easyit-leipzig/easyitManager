<?php
declare(strict_types=1);
namespace DataForm5\Console\Core;
use DataForm5\Console\Exceptions\ConsoleException;use Throwable;
final class ConsoleApplication
{
    public function __construct(private CommandRegistry $registry,private string $name='DataForm5 Core',private string $version='build0025'){}
    /** @param list<string>|null $argv */ public function run(?array $argv=null,?Output $output=null):int{$argv??=$_SERVER['argv']??[];$output??=new Output();$input=new Input(array_slice($argv,1));try{$command=$this->registry->get($input->command());return $command->execute($input,$output);}catch(ConsoleException $e){$output->error($e->getMessage());return 2;}catch(Throwable $e){$output->error($e->getMessage());return 1;}}
    public function registry():CommandRegistry{return $this->registry;} public function name():string{return $this->name;} public function version():string{return $this->version;}
}
