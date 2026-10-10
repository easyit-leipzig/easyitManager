<?php
declare(strict_types=1);
namespace DataForm5\Help\Core;
use DataForm5\Help\Contracts\HelpProviderInterface;use RuntimeException;
final class FileHelpProvider implements HelpProviderInterface
{
    public function __construct(private string $directory){}
    public function topics():array{$topics=[];foreach(glob(rtrim($this->directory,'/\\').'/*.php')?:[] as $file){$data=require $file;if(!is_array($data))throw new RuntimeException("Ungültige Hilfedatei: {$file}");$topics[]=$data;}return $topics;}
}
