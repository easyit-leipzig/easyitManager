<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};
use DataForm5\Modules\SDK\SdkGenerator;
final class MakeArtifactCommand extends AbstractCommand
{
    public function __construct(private readonly SdkGenerator $generator,private readonly string $kind){}
    public function name():string{return 'make:'.$this->kind;}
    public function description():string{return 'Erzeugt ein '.$this->kind.'-Gerüst in einem Modul.';}
    public function arguments():array{return ['name'=>'Artefaktname'];}
    public function options():array{return ['module'=>['description'=>'Zielmodul','default'=>null,'requires_value'=>true]];}
    public function execute(Input $input,Output $output):int
    {
        $result=$this->generator->generate($this->kind,(string)($this->argument($input,'name')??''),is_string($this->option($input,'module'))?(string)$this->option($input,'module'):null);
        $output->success($result['relative_file'].' erzeugt.');
        return 0;
    }
}
