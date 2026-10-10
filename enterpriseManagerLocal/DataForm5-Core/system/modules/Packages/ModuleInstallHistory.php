<?php
declare(strict_types=1);
namespace DataForm5\Modules\Packages;
use DataForm5\Core\Filesystem\Filesystem;
final class ModuleInstallHistory
{
    public function __construct(private readonly string $file, private readonly Filesystem $fs) {}
    /** @param array<string,mixed> $context */
    public function add(string $action,string $module,string $status,array $context=[]): void
    {
        $rows=$this->all();
        $rows[]=['time'=>date(DATE_ATOM),'action'=>$action,'module'=>$module,'status'=>$status,'context'=>$context];
        if(count($rows)>500)$rows=array_slice($rows,-500);
        $this->fs->write($this->file,json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
    }
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if(!is_file($this->file))return [];
        $data=json_decode($this->fs->read($this->file),true);
        return is_array($data)?array_values(array_filter($data,'is_array')):[];
    }
    /** @return list<array<string,mixed>> */
    public function recent(int $limit=50): array { return array_slice(array_reverse($this->all()),0,max(1,$limit)); }
}
