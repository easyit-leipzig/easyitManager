<?php
declare(strict_types=1);
namespace DataForm5\Console\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Health\Core\HealthManager;
final class HealthCommand extends AbstractCommand
{public function __construct(private HealthManager $health){}public function name():string{return 'health';}public function description():string{return 'Führt die vollständige Systemprüfung aus.';}public function options():array{return ['mode'=>['description'=>'all, readiness oder liveness','default'=>'all','requires_value'=>true]];}public function execute(Input $input,Output $output):int{$mode=(string)$this->option($input,'mode');$report=match($mode){'readiness'=>$this->health->readiness(),'liveness'=>$this->health->liveness(),default=>$this->health->check()};$rows=[];foreach($report->checks as $check)$rows[]=[$check->name,$check->status->value,$check->message];$output->table(['Check','Status','Meldung'],$rows);$output->line('Gesamtstatus: '.$report->status->value);return $report->status->value==='down'?1:0;}}
