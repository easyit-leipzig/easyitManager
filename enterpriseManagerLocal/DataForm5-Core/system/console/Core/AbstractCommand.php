<?php
declare(strict_types=1);
namespace DataForm5\Console\Core;
use DataForm5\Console\Contracts\CommandInterface;
abstract class AbstractCommand implements CommandInterface
{
    public function arguments():array{return [];} public function options():array{return [];} protected function argument(Input $input,string $name):mixed{return $input->parseArguments($this->arguments())[$name]??null;} protected function option(Input $input,string $name):mixed{return $input->parseOptions($this->options())[$name]??null;}
}
