<?php
declare(strict_types=1);
namespace DataForm5\Logging\Core;
use DataForm5\Core\Config;
use DataForm5\Core\Support\Path;
use DataForm5\Logging\Contracts\LoggerInterface;
use DataForm5\Logging\Exceptions\LoggingException;
final class LogManager
{
    private array $channels=[];
    public function __construct(private readonly Config $config, private readonly Path $path) {}
    public function channel(?string $name=null):LoggerInterface
    {
        $name=$name?: (string)$this->config->get('logging.default','app'); if(isset($this->channels[$name]))return $this->channels[$name];
        $cfg=$this->config->get('logging.channels.'.$name); if(!is_array($cfg))throw new LoggingException("Log-Kanal '{$name}' ist nicht konfiguriert.");
        $driver=(string)($cfg['driver']??'file');
        return $this->channels[$name]=match($driver){'null'=>new NullLogger(),'file'=>new FileLogger($this->resolvePath((string)($cfg['path']??'storage/logs/'.$name.'.log')),(string)($cfg['level']??'debug'),(int)($cfg['max_bytes']??5242880),(int)($cfg['retained_files']??5),$name),default=>throw new LoggingException("Unbekannter Logging-Treiber '{$driver}'.")};
    }
    private function resolvePath(string $file):string { if(str_starts_with($file,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$file))return $file; return $this->path->base($file); }
}
