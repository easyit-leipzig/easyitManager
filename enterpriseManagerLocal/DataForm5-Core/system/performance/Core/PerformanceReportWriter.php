<?php
declare(strict_types=1);
namespace DataForm5\Performance\Core;
use DataForm5\Performance\Contracts\ProfilerInterface;
final class PerformanceReportWriter
{
    public function __construct(private ProfilerInterface $profiler) {}
    public function write(string $file):array
    {
        $report=$this->profiler->report();$dir=dirname($file);if(!is_dir($dir))mkdir($dir,0775,true);
        $tmp=$file.'.tmp.'.bin2hex(random_bytes(4));file_put_contents($tmp,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,LOCK_EX);rename($tmp,$file);return $report;
    }
}
