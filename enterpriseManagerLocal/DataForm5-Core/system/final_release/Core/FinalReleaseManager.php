<?php
declare(strict_types=1);
namespace DataForm5\FinalRelease\Core;
use DataForm5\FinalRelease\Contracts\FinalReleaseManagerInterface;
use DataForm5\FinalRelease\Exceptions\FinalReleaseException;
final class FinalReleaseManager implements FinalReleaseManagerInterface
{
    public function __construct(
        private string $basePath,
        private string $version,
        private string $build,
        private string $reportPath,
        private string $manifestPath,
        private array $requiredFiles = [],
        private array $requiredDirectories = [],
        private array $excludedPaths = []
    ) {}
    public function inspect(): array
    {
        $checks=[];
        $checks[]=$this->check('version.stable',$this->version==='1.0.0','Die finale Version muss exakt 1.0.0 lauten.',['version'=>$this->version]);
        $checks[]=$this->check('version.no_prerelease',!str_contains($this->version,'-'),'Die finale Version darf keine Vorabkennzeichnung enthalten.');
        $checks[]=$this->check('build.fixed',preg_match('/^build\d{4}$/',$this->build)===1,'Buildkennung muss dem Muster buildNNNN entsprechen.',['build'=>$this->build]);
        foreach($this->requiredFiles as $relative){$checks[]=$this->check('file:'.$relative,is_file($this->basePath.'/'.$relative),'Pflichtdatei fehlt.',['path'=>$relative]);}
        foreach($this->requiredDirectories as $relative){$checks[]=$this->check('directory:'.$relative,is_dir($this->basePath.'/'.$relative),'Pflichtverzeichnis fehlt.',['path'=>$relative]);}
        $checks[]=$this->check('php.version',version_compare(PHP_VERSION,'8.1.0','>='),'PHP 8.1 oder neuer ist erforderlich.',['php'=>PHP_VERSION]);
        $checks[]=$this->check('storage.writable',is_dir($this->basePath.'/storage')&&is_writable($this->basePath.'/storage'),'Storage muss vorhanden und beschreibbar sein.');
        $checks[]=$this->check('rc.marker.removed',trim((string)@file_get_contents($this->basePath.'/VERSION'))==='1.0.0','VERSION enthält noch eine Vorabversion.');
        $failed=array_values(array_filter($checks,fn(array $c):bool=>$c['status']==='failed'));
        return [
            'ready'=>$failed===[],
            'version'=>$this->version,
            'build'=>$this->build,
            'generated_at'=>gmdate(DATE_ATOM),
            'checks'=>$checks,
            'failed'=>count($failed),
            'passed'=>count($checks)-count($failed),
            'release_policy'=>[
                'channel'=>'stable',
                'lts'=>true,
                'breaking_changes_allowed'=>false,
                'security_fixes_allowed'=>true,
                'bugfixes_allowed'=>true,
                'new_core_layers_allowed'=>false,
            ],
        ];
    }
    public function assertReady(): array
    {
        $report=$this->inspect();
        if(!$report['ready']) throw new FinalReleaseException($report);
        return $report;
    }
    public function writeReport(?string $path=null): array
    {
        $report=$this->assertReady();
        $path=$path?:$this->reportPath;
        $this->writeJson($path,$report);
        return $report+['report_path'=>$path];
    }
    public function writeManifest(?string $path=null): array
    {
        $this->assertReady();
        $files=[];
        $root=rtrim(str_replace('\\','/',$this->basePath),'/');
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file->isFile()) continue;
            $absolute=str_replace('\\','/',$file->getPathname());
            $relative=ltrim(substr($absolute,strlen($root)),'/');
            if($this->excluded($relative)) continue;
            $files[$relative]=['sha256'=>hash_file('sha256',$absolute),'bytes'=>$file->getSize()];
        }
        ksort($files,SORT_STRING);
        $manifest=['version'=>$this->version,'build'=>$this->build,'generated_at'=>gmdate(DATE_ATOM),'algorithm'=>'sha256','file_count'=>count($files),'files'=>$files];
        $manifest['manifest_sha256']=hash('sha256',(string)json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $path=$path?:$this->manifestPath;
        $this->writeJson($path,$manifest);
        return $manifest+['manifest_path'=>$path];
    }
    private function excluded(string $relative): bool
    {
        foreach($this->excludedPaths as $pattern){
            $pattern=trim(str_replace('\\','/',(string)$pattern),'/');
            if($pattern!==''&&($relative===$pattern||str_starts_with($relative,$pattern.'/'))) return true;
        }
        return false;
    }
    private function writeJson(string $path,array $data): void
    {
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0775,true)&&!is_dir(dirname($path))) throw new \RuntimeException('Release-Berichtsverzeichnis konnte nicht erstellt werden.');
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false||file_put_contents($path,$json.PHP_EOL,LOCK_EX)===false) throw new \RuntimeException('Release-Datei konnte nicht geschrieben werden.');
    }
    private function check(string $name,bool $ok,string $message,array $meta=[]):array{return ['name'=>$name,'status'=>$ok?'passed':'failed','message'=>$ok?'OK':$message,'metadata'=>$meta];}
}
