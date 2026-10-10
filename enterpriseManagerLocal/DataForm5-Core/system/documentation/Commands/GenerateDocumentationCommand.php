<?php
declare(strict_types=1);
namespace DataForm5\Documentation\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Documentation\Core\DocumentationGenerator;
final class GenerateDocumentationCommand extends AbstractCommand
{
    public function __construct(private DocumentationGenerator $generator){}
    public function name():string{return 'docs:generate';}
    public function description():string{return 'Erzeugt Komponenten-Katalog und Entwicklerdokumentation.';}
    public function options():array{return ['target'=>['description'=>'Zielverzeichnis','default'=>null,'requires_value'=>true]];}
    public function execute(Input $input,Output $output):int{$target=$this->option($input,'target');$result=$this->generator->generate(is_string($target)?$target:null);$output->success('Dokumentation erzeugt: '.($result['summary']['total']??0).' Komponenten.');return 0;}
}
