<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Core;
use DataForm5\Scheduler\Exceptions\SchedulerException;
final class CronExpression
{
    /** @var list<string> */ private array $fields;
    public function __construct(private readonly string $expression)
    {
        $this->fields=preg_split('/\s+/',trim($expression))?:[];
        if(count($this->fields)!==5) throw new SchedulerException("Cron-Ausdruck muss aus fünf Feldern bestehen: {$expression}");
    }
    public function isDue(\DateTimeInterface $time):bool
    {
        [$min,$hour,$day,$month,$weekday]=$this->fields;
        return $this->matches($min,(int)$time->format('i'),0,59)
            && $this->matches($hour,(int)$time->format('G'),0,23)
            && $this->matches($day,(int)$time->format('j'),1,31)
            && $this->matches($month,(int)$time->format('n'),1,12)
            && $this->matches($weekday,(int)$time->format('w'),0,6,true);
    }
    public function expression():string{return $this->expression;}
    private function matches(string $field,int $value,int $min,int $max,bool $weekday=false):bool
    {
        foreach(explode(',',$field) as $part){
            $part=trim($part); if($part==='')continue;
            $step=1;
            if(str_contains($part,'/')){[$part,$rawStep]=explode('/',$part,2);$step=(int)$rawStep;if($step<1)throw new SchedulerException('Cron-Schritt muss größer als 0 sein.');}
            if($part==='*'){$start=$min;$end=$max;}
            elseif(str_contains($part,'-')){[$a,$b]=array_map('intval',explode('-',$part,2));$start=$a;$end=$b;}
            else{$start=$end=(int)$part;}
            if($weekday){if($start===7)$start=0;if($end===7)$end=0;}
            if($start<$min||$start>$max||$end<$min||$end>$max||$start>$end)throw new SchedulerException("Ungültiges Cron-Feld: {$field}");
            if($value>=$start&&$value<=$end&&(($value-$start)%$step===0))return true;
        }
        return false;
    }
}
