<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Core;
final class MetricsStorage
{
    public function __construct(private readonly string $directory){}
    public function store(array $metrics): void
    {
        if(!is_dir($this->directory)) @mkdir($this->directory,0775,true);
        @file_put_contents(rtrim($this->directory,'/\\').'/metrics.json',json_encode($metrics,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
        @file_put_contents(rtrim($this->directory,'/\\').'/metrics-'.date('Y-m-d').'.log',json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
    public function latest(): array
    {
        $file=rtrim($this->directory,'/\\').'/metrics.json';
        $row=json_decode((string)@file_get_contents($file),true); return is_array($row)?$row:[];
    }
}
