<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};
use DataForm5\Modules\SDK\SdkGenerator;
final class MakeModuleCommand extends AbstractCommand
{
    public function __construct(private readonly SdkGenerator $generator){}
    public function name():string{return 'make:module';}
    public function description():string{return 'Erzeugt ein vollständiges easyIT-Modulgrundgerüst.';}
    public function arguments():array{return ['name'=>'Modulname'];}
    public function options():array{return [
        'namespace'=>['description'=>'PHP-Namespace','default'=>null,'requires_value'=>true],
        'description'=>['description'=>'Beschreibung','default'=>'','requires_value'=>true],
    ];}
    public function execute(Input $input,Output $output):int
    {
        $ns=$this->option($input,'namespace');
        $result=$this->generator->createModule((string)($this->argument($input,'name')??''),is_string($ns)?$ns:null,(string)($this->option($input,'description')??''));
        $output->success('Modul erzeugt: '.str_replace('\\','/',(string)$result['directory']));
        $output->line('Dateien: '.count($result['files']));
        return 0;
    }
}
