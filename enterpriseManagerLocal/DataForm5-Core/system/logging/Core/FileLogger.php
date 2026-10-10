<?php
declare(strict_types=1);
namespace DataForm5\Logging\Core;
use DataForm5\Logging\Exceptions\LoggingException;
final class FileLogger extends AbstractLogger
{
    public function __construct(private readonly string $file, private readonly string $minimumLevel='debug', private readonly int $maxBytes=5242880, private readonly int $retainedFiles=5, private readonly string $channel='app') { LogLevel::validate($minimumLevel); }
    public function log(string $level,string $message,array $context=[]):void
    {
        $level=LogLevel::validate($level); if(!LogLevel::allows($this->minimumLevel,$level)) return;
        $this->rotateIfNeeded(); $dir=dirname($this->file); if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)) throw new LoggingException("Logverzeichnis '{$dir}' konnte nicht erstellt werden.");
        $record=['time'=>(new \DateTimeImmutable())->format(DATE_ATOM),'level'=>strtoupper($level),'channel'=>$this->channel,'message'=>$this->interpolate($message,$context),'context'=>$this->normalize($context),'pid'=>getmypid()];
        $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        if(file_put_contents($this->file,$line,FILE_APPEND|LOCK_EX)===false) throw new LoggingException("Logdatei '{$this->file}' konnte nicht geschrieben werden.");
    }
    private function normalize(array $context):array { foreach($context as $k=>$v){ if($v instanceof \Throwable)$context[$k]=['class'=>$v::class,'message'=>$v->getMessage(),'file'=>$v->getFile(),'line'=>$v->getLine()]; elseif(is_object($v))$context[$k]=method_exists($v,'__toString')?(string)$v:$v::class; elseif(is_resource($v))$context[$k]=get_resource_type($v); } return $context; }
    private function rotateIfNeeded():void { if($this->maxBytes<=0||!is_file($this->file)||filesize($this->file)<$this->maxBytes)return; for($i=$this->retainedFiles-1;$i>=1;$i--){$old=$this->file.'.'.$i;$new=$this->file.'.'.($i+1);if(is_file($old))rename($old,$new);} if($this->retainedFiles>0)rename($this->file,$this->file.'.1'); else unlink($this->file); $excess=$this->file.'.'.($this->retainedFiles+1);if(is_file($excess))unlink($excess); }
}
