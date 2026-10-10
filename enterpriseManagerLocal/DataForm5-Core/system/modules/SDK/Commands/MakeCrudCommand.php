<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Modules\SDK\CrudScaffolder;
final class MakeCrudCommand extends AbstractCommand{
 public function __construct(private readonly CrudScaffolder $scaffolder){}
 public function name():string{return 'make:crud';}public function description():string{return 'Erzeugt Model, Controller, View, Test und Route.';}
 public function arguments():array{return ['name'=>'Entityname'];}public function options():array{return ['module'=>['description'=>'Zielmodul','default'=>null,'requires_value'=>true]];}
 public function execute(Input $input,Output $output):int{$r=$this->scaffolder->create((string)($this->option($input,'module')??''),(string)($this->argument($input,'name')??''));$output->success('CRUD '.$r['entity'].' erzeugt.');return 0;}
}
