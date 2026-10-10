<?php
declare(strict_types=1);
namespace DataForm5\Health\Checks;
use DataForm5\Health\Contracts\HealthCheckInterface;use DataForm5\Health\Core\{HealthResult,HealthStatus};
final readonly class DirectoryWritableCheck implements HealthCheckInterface
{
    public function __construct(private string $checkName,private string $path){}
    public function name():string{return $this->checkName;}
    public function run():HealthResult{$start=microtime(true);$ok=is_dir($this->path)&&is_writable($this->path);return new HealthResult($this->checkName,$ok?HealthStatus::UP:HealthStatus::DOWN,$ok?'Verzeichnis beschreibbar':'Verzeichnis nicht beschreibbar',['path'=>$this->path],(microtime(true)-$start)*1000);}
}
