<?php
declare(strict_types=1);
namespace DataForm5\Testing\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Testing\Core\{LegacyScriptRunner,TestResult};
final class TestCommand extends AbstractCommand
{
    public function __construct(private LegacyScriptRunner $runner,private string $testsPath){}
    public function name():string{return 'test';} public function description():string{return 'Führt die Core-Regressionstests aus.';}
    public function options():array{return ['filter'=>['description'=>'Nur passende Tests','default'=>null,'requires_value'=>true],'report'=>['description'=>'JSON-Berichtspfad','default'=>null,'requires_value'=>true]];}
    public function execute(Input $input,Output $output):int{$filter=$this->option($input,'filter');$results=$this->runner->runDirectory($this->testsPath,is_string($filter)?$filter:null,['testing_layer']);$failed=0;$rows=[];foreach($results as $r){$rows[]=[strtoupper($r->status),$r->name,number_format($r->durationMs,2).' ms'];if(!$r->passed())$failed++;}$output->table(['Status','Test','Dauer'],$rows);$output->line();$failed===0?$output->success(count($results).' Tests erfolgreich.'):$output->error($failed.' Tests fehlgeschlagen.');$report=$this->option($input,'report');if(is_string($report)&&$report!==''){file_put_contents($report,json_encode(['total'=>count($results),'failed'=>$failed,'results'=>array_map(fn(TestResult $r)=>$r->toArray(),$results)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL);}$this->printFailures($results,$output);return $failed===0?0:1;}
    private function printFailures(array $results,Output $output):void{foreach($results as $r)if(!$r->passed()){$output->error($r->name.': '.($r->message??'unbekannt'));}}
}
