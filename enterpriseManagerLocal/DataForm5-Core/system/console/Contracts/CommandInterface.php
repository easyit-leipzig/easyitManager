<?php
declare(strict_types=1);
namespace DataForm5\Console\Contracts;
use DataForm5\Console\Core\{Input,Output};
interface CommandInterface
{
    public function name(): string;
    public function description(): string;
    /** @return array<string,string> */
    public function arguments(): array;
    /** @return array<string,array{description:string,default:mixed,requires_value:bool}> */
    public function options(): array;
    public function execute(Input $input, Output $output): int;
}
