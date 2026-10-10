<?php
declare(strict_types=1);

namespace DataForm5\Testing\Core;

final class DeveloperQualityCenter
{
    public function __construct(private readonly string $enterpriseRoot) {}

    public function run(?string $group=null): array
    {
        $groups=$this->discoverGroups();
        if($group!==null && $group!=='' && isset($groups[$group])){
            $groups=[$group=>$groups[$group]];
        }

        $results=[];
        foreach($groups as $name=>$files){
            $groupResults=[];
            foreach($files as $file){
                $groupResults[]=$this->runFile($file);
            }
            $failed=count(array_filter($groupResults,static fn(array $r):bool=>$r['status']==='FAIL'));
            $warn=count(array_filter($groupResults,static fn(array $r):bool=>$r['status']==='WARN'));
            $results[$name]=[
                'status'=>$failed>0?'FAIL':($warn>0?'WARN':'PASS'),
                'total'=>count($groupResults),
                'passed'=>count(array_filter($groupResults,static fn(array $r):bool=>$r['status']==='PASS')),
                'warnings'=>$warn,
                'failed'=>$failed,
                'tests'=>$groupResults,
            ];
        }

        $failedGroups=count(array_filter($results,static fn(array $r):bool=>$r['status']==='FAIL'));
        $warnGroups=count(array_filter($results,static fn(array $r):bool=>$r['status']==='WARN'));

        return [
            'generated_at'=>date(DATE_ATOM),
            'status'=>$failedGroups>0?'FAIL':($warnGroups>0?'WARN':'PASS'),
            'groups'=>$results,
            'summary'=>[
                'groups'=>count($results),
                'failed_groups'=>$failedGroups,
                'warning_groups'=>$warnGroups,
                'tests'=>array_sum(array_column($results,'total')),
                'failed_tests'=>array_sum(array_column($results,'failed')),
                'warning_tests'=>array_sum(array_column($results,'warnings')),
            ],
        ];
    }

    public function groups(): array
    {
        return array_keys($this->discoverGroups());
    }

    private function discoverGroups(): array
    {
        $root=$this->enterpriseRoot;
        $phaseFiles=glob($root.'/tests_phase_*.php')?:[];
        $rcFiles=glob($root.'/tests_rc18_phase*.php')?:[];
        sort($phaseFiles,SORT_STRING);
        sort($rcFiles,SORT_STRING);

        $groups=[
            'core'=>[],
            'modules'=>[],
            'installer'=>[],
            'cluster'=>[],
            'storage'=>[],
            'replication'=>[],
            'sdk'=>[],
            'developer'=>[],
            'master'=>[],
        ];

        foreach(array_merge($phaseFiles,$rcFiles) as $file){
            $name=basename($file);
            $group=$this->groupFor($name);
            $groups[$group][]=$file;
        }

        $master=$root.'/tests_master_state.php';
        if(is_file($master))$groups['master'][]=$master;

        return array_filter($groups,static fn(array $files):bool=>$files!==[]);
    }

    private function groupFor(string $name): string
    {
        $lower=strtolower($name);
        if(str_contains($lower,'installer'))return 'installer';
        if(str_contains($lower,'cluster'))return 'cluster';
        if(str_contains($lower,'storage'))return 'storage';
        if(str_contains($lower,'replication'))return 'replication';
        if(str_contains($lower,'sdk')||str_contains($lower,'module_validation')||str_contains($lower,'module_packaging')||str_contains($lower,'code_generator'))return 'sdk';
        if(str_contains($lower,'developer')||str_contains($lower,'container_inspector')||str_contains($lower,'event_inspector')||str_contains($lower,'hook_inspector')||str_contains($lower,'profiler'))return 'developer';
        if(str_contains($lower,'module'))return 'modules';
        return 'core';
    }

    private function runFile(string $file): array
    {
        $start=hrtime(true);
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1';
        $output=[];$code=0;
        exec($command,$output,$code);
        $duration=(hrtime(true)-$start)/1e6;
        $text=trim(implode(PHP_EOL,$output));
        $status=$code===0?'PASS':'FAIL';
        if($code===0 && preg_match('/\bWARN(?:ING)?\b/i',$text))$status='WARN';
        return [
            'name'=>basename($file),
            'status'=>$status,
            'exit_code'=>$code,
            'duration_ms'=>round($duration,3),
            'output'=>$text,
        ];
    }
}
