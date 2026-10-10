<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use RuntimeException;
final class LegacyScriptRunner
{
    public function __construct(private string $phpBinary=PHP_BINARY) {}
    /** @return list<TestResult> */
    public function runDirectory(string $directory, ?string $filter=null, array $exclude=[]): array
    {
        if(!is_dir($directory)) throw new RuntimeException("Testverzeichnis fehlt: {$directory}");
        $files=glob(rtrim($directory,'/\\').DIRECTORY_SEPARATOR.'*.php')?:[]; sort($files);$results=[];
        foreach($files as $file){$name=basename($file,'.php');if(in_array($name,$exclude,true))continue;if($filter && stripos($name,$filter)===false)continue;$results[]=$this->runFile($file);}
        return $results;
    }
    public function runFile(string $file): TestResult
    {
        $start=hrtime(true);$command=escapeshellarg($this->phpBinary).' -d zend.assertions=1 -d assert.exception=1 '.escapeshellarg($file).' 2>&1';$output=[];$code=0;exec($command,$output,$code);$duration=(hrtime(true)-$start)/1e6;$text=trim(implode(PHP_EOL,$output));return new TestResult(basename($file,'.php'),$code===0?'passed':'failed',$duration,$code===0?null:($text?:"Exit-Code {$code}"),$code===0?null:$text);
    }
}
