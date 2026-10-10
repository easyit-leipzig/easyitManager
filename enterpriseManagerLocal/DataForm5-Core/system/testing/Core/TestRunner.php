<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use Throwable;
final class TestRunner
{
    /** @return list<TestResult> */
    public function run(TestSuite $suite, ?string $filter=null): array
    {
        $results=[];
        foreach($suite->tests() as $test){
            if($filter!==null && $filter!=='' && stripos($test->name(),$filter)===false) continue;
            $start=hrtime(true);
            try{$test->run();$results[]=new TestResult($test->name(),'passed',(hrtime(true)-$start)/1e6);}catch(Throwable $e){$results[]=new TestResult($test->name(),'failed',(hrtime(true)-$start)/1e6,$e->getMessage(),$e->getTraceAsString());}
        }
        return $results;
    }
    public function summary(array $results): array { $passed=count(array_filter($results,fn(TestResult $r)=>$r->passed())); return ['total'=>count($results),'passed'=>$passed,'failed'=>count($results)-$passed,'duration_ms'=>round(array_sum(array_map(fn(TestResult $r)=>$r->durationMs,$results)),3)]; }
}
