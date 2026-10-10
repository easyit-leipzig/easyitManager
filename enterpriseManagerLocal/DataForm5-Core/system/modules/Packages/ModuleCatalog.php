<?php
declare(strict_types=1);
namespace DataForm5\Modules\Packages;
use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Modules\Core\ModuleManifest;
use DataForm5\Modules\Versioning\ModuleCompatibility;
use ZipArchive;
final class ModuleCatalog
{
    public function __construct(private readonly ModulePackageRegistry $registry,private readonly ModuleManager $manager,private readonly string $packagePath,private readonly ?ModuleCompatibility $compatibility=null) {}
    /** @return list<array<string,mixed>> */
    public function installed(): array
    {
        $records=$this->registry->all(); $status=[]; foreach($this->manager->status() as $row)$status[(string)$row['name']]=$row;
        $packages=$this->availablePackages(); $best=[]; foreach($packages as $pkg){$n=(string)($pkg['name']??'');if($n==='')continue;if(!isset($best[$n])||version_compare((string)$pkg['version'],(string)$best[$n]['version'],'>'))$best[$n]=$pkg;}
        $out=[];
        foreach($records as $name=>$record){$row=$status[$name]??[];$current=(string)($record['version']??'0.0.0');$candidate=$best[$name]??null;$out[]=[
            'name'=>$name,'version'=>$current,'source'=>(string)($record['source']??'unknown'),'enabled'=>(bool)($row['enabled']??$record['enabled']??true),
            'installed_at'=>$record['installed_at']??null,'updated_at'=>$record['updated_at']??null,'missing_dependencies'=>$row['missing_dependencies']??[],'path'=>$record['path']??null,
            'update_available'=>$candidate!==null&&($candidate['compatible']??true)&&version_compare((string)$candidate['version'],$current,'>'),
            'available_version'=>$candidate['version']??null,'core_compatible'=>$candidate['core_compatible']??true
        ];}
        usort($out,fn(array $a,array $b)=>strcmp((string)$a['name'],(string)$b['name']));return $out;
    }
    /** @return list<array<string,mixed>> */
    public function availablePackages(): array
    {
        if(!is_dir($this->packagePath))return [];$files=glob(rtrim($this->packagePath,'/\\').'/*.zip')?:[];$out=[];
        foreach($files as $file){$entry=['file'=>basename($file),'path'=>$file,'size'=>filesize($file)?:0,'name'=>null,'version'=>null,'core_version'=>'*','readable'=>false,'compatible'=>null,'core_compatible'=>null,'problems'=>[]];
            if(class_exists(ZipArchive::class)){$zip=new ZipArchive();if($zip->open($file)===true){for($i=0;$i<$zip->numFiles;$i++){$n=$zip->getNameIndex($i);if($n!==false&&basename($n)==='module.json'){$raw=$zip->getFromIndex($i);$d=is_string($raw)?json_decode($raw,true):null;if(is_array($d)){$entry['name']=$d['name']??null;$entry['version']=$d['version']??null;$entry['core_version']=$d['core_version']??'*';$entry['readable']=true; if($this->compatibility!==null){$tmp=tempnam(sys_get_temp_dir(),'modmanifest-');if($tmp!==false){file_put_contents($tmp,json_encode($d));try{$m=ModuleManifest::fromFile($tmp);$c=$this->compatibility->check($m,$this->registry->all());$entry=array_merge($entry,$c);}finally{@unlink($tmp);}}}}break;}}$zip->close();}}
            $out[]=$entry;
        }
        usort($out,fn(array $a,array $b)=>strcmp((string)$a['file'],(string)$b['file']));return $out;
    }
}
