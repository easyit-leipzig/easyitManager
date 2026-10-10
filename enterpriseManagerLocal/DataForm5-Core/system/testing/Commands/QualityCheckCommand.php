<?php
declare(strict_types=1);
namespace DataForm5\Testing\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Testing\Core\QualityGate;
final class QualityCheckCommand extends AbstractCommand
{
    public function __construct(private QualityGate $gate,private string $defaultReport){}
    public function name():string{return 'quality:check';} public function description():string{return 'Führt Syntax- und Regressionstests als Abnahme-Gate aus.';}
    public function options():array{return ['filter'=>['description'=>'Testfilter','default'=>null,'requires_value'=>true],'report'=>['description'=>'JSON-Berichtspfad','default'=>$this->defaultReport,'requires_value'=>true],'skip-syntax'=>['description'=>'Syntaxprüfung überspringen','default'=>false,'requires_value'=>false]];}
    public function execute(Input $input,Output $output):int{$results=[];if(!$this->option($input,'skip-syntax')){$output->line('PHP-Syntaxprüfung ...');$results=array_merge($results,$this->gate->syntaxCheck());}$output->line('Regressionstests ...');$filter=$this->option($input,'filter');$results=array_merge($results,$this->gate->regression(is_string($filter)?$filter:null));$report=$this->gate->report($results);$path=(string)$this->option($input,'report');$this->gate->writeReport($path,$report);$output->table(['Gesamt','Erfolgreich','Fehlgeschlagen'],[[$report['total'],$report['passed'],$report['failed']]]);$output->line('Bericht: '.$path);if($report['failed']>0){$output->error('Abnahme-Gate fehlgeschlagen.');return 1;}$output->success('Abnahme-Gate bestanden.');return 0;}
}
