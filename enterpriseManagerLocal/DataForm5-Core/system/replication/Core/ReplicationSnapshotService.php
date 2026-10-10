<?php
declare(strict_types=1);

namespace DataForm5\Replication\Core;

final class ReplicationSnapshotService
{
    public function __construct(private readonly string $basePath) {}

    public function moduleMetadata(string $modulesPath): array
    {
        $rows=[];
        foreach(glob(rtrim($modulesPath,'/\\').'/*/module.json')?:[] as $file){
            $data=json_decode((string)@file_get_contents($file),true);
            if(!is_array($data)) continue;
            $name=(string)($data['name']??basename(dirname($file)));
            $rows[$name]=[
                'name'=>$name,
                'version'=>(string)($data['version']??''),
                'enabled'=>(bool)($data['enabled']??true),
                'checksum'=>hash_file('sha256',$file),
            ];
        }
        ksort($rows);
        return $rows;
    }

    public function files(array $relativePaths): array
    {
        $rows=[];
        foreach($relativePaths as $relative){
            if(!is_string($relative)||$relative==='') continue;
            $file=rtrim($this->basePath,'/\\').'/'.ltrim($relative,'/\\');
            if(!is_file($file)) continue;
            $rows[$relative]=[
                'checksum'=>hash_file('sha256',$file),
                'size'=>filesize($file)?:0,
                'modified_at'=>date(DATE_ATOM,filemtime($file)?:time()),
            ];
        }
        ksort($rows);
        return $rows;
    }
}
