<?php
declare(strict_types=1);

namespace DataForm5\Testing\Commands;

use DataForm5\Console\Core\{AbstractCommand,Input,Output};
use DataForm5\Testing\Core\DeveloperQualityCenter;

final class QualityCenterCommand extends AbstractCommand
{
    public function __construct(private readonly DeveloperQualityCenter $center) {}
    public function name(): string { return 'quality:center'; }
    public function description(): string { return 'Führt das Developer Quality Center aus.'; }
    public function options(): array
    {
        return [
            'group'=>['description'=>'Nur eine Testgruppe ausführen','default'=>null,'requires_value'=>true],
            'json'=>['description'=>'JSON-Ausgabe','default'=>false,'requires_value'=>false],
        ];
    }

    public function execute(Input $input,Output $output): int
    {
        $group=$this->option($input,'group');
        $result=$this->center->run(is_string($group)?$group:null);
        if((bool)$this->option($input,'json')){
            $output->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }else{
            $output->line('Quality Center: '.$result['status']);
            foreach($result['groups'] as $name=>$row){
                $output->line(sprintf('%-12s %s  %d Tests / %d Fail / %d Warn',$name,$row['status'],$row['total'],$row['failed'],$row['warnings']));
            }
        }
        return $result['status']==='FAIL'?1:0;
    }
}
