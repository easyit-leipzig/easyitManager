<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
final readonly class DiagnosticReport
{
    public function __construct(private HealthManager $health,private SystemInfo $system){}
    public function generate():array{return ['health'=>$this->health->report()->toArray(),'system'=>$this->system->collect()];}
}
