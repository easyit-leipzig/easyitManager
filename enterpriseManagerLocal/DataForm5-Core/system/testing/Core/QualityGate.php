<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use RecursiveDirectoryIterator;use RecursiveIteratorIterator;use FilesystemIterator;
final class QualityGate
{
    public function __construct(private string $basePath,private LegacyScriptRunner $legacyRunner){}
    public function syntaxCheck(): array
    {
        $results=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->basePath,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;$start=hrtime(true);$out=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1',$out,$code);$results[]=new TestResult('syntax:'.substr($file->getPathname(),strlen($this->basePath)+1),$code===0?'passed':'failed',(hrtime(true)-$start)/1e6,$code===0?null:implode(PHP_EOL,$out));}
        return $results;
    }
    public function regression(?string $filter=null): array { return $this->legacyRunner->runDirectory($this->basePath.'/tests',$filter,['testing_layer']); }
    public function report(array $results): array { $passed=count(array_filter($results,fn(TestResult $r)=>$r->passed()));return ['build'=>'build0045','generated_at'=>gmdate(DATE_ATOM),'total'=>count($results),'passed'=>$passed,'failed'=>count($results)-$passed,'results'=>array_map(fn(TestResult $r)=>$r->toArray(),$results)]; }
    public function writeReport(string $file,array $report):void{if(!is_dir(dirname($file)))mkdir(dirname($file),0775,true);file_put_contents($file,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,LOCK_EX);}
}
