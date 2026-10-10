<?php
declare(strict_types=1);
namespace DataForm5\ReleaseCandidate\Core;
use DataForm5\ReleaseCandidate\Contracts\ReleaseCandidateManagerInterface;
use DataForm5\ReleaseCandidate\Exceptions\ReleaseCandidateException;
final class ReleaseCandidateManager implements ReleaseCandidateManagerInterface
{
    public function __construct(
        private string $basePath,
        private string $version,
        private string $build,
        private string $reportPath,
        private array $requiredFiles = [],
        private array $requiredDirectories = []
    ) {}
    public function inspect(): array
    {
        $checks=[];
        $checks[]=$this->check('version.rc', str_contains($this->version, '-rc.'), 'Version muss als Release Candidate gekennzeichnet sein.', ['version'=>$this->version]);
        $checks[]=$this->check('build.fixed', preg_match('/^build\d{4}$/', $this->build)===1, 'Buildkennung muss dem Muster buildNNNN entsprechen.', ['build'=>$this->build]);
        foreach($this->requiredFiles as $relative){$checks[]=$this->check('file:'.$relative,is_file($this->basePath.'/'.$relative),'Pflichtdatei fehlt.',['path'=>$relative]);}
        foreach($this->requiredDirectories as $relative){$checks[]=$this->check('directory:'.$relative,is_dir($this->basePath.'/'.$relative),'Pflichtverzeichnis fehlt.',['path'=>$relative]);}
        $phpVersion=PHP_VERSION;
        $checks[]=$this->check('php.version',version_compare($phpVersion,'8.1.0','>='),'PHP 8.1 oder neuer ist erforderlich.',['php'=>$phpVersion]);
        $checks[]=$this->check('storage.writable',is_dir($this->basePath.'/storage')&&is_writable($this->basePath.'/storage'),'Storage muss vorhanden und beschreibbar sein.');
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
                'version_frozen'=>true,
                'new_features_allowed'=>false,
                'bugfixes_allowed'=>true,
                'breaking_changes_allowed'=>false,
                'target'=>'1.0.0',
            ],
        ];
    }
    public function assertReady(): array
    {
        $report=$this->inspect();
        if(!$report['ready']) throw new ReleaseCandidateException($report);
        return $report;
    }
    public function writeReport(?string $path = null): array
    {
        $report=$this->inspect();$path=$path?:$this->reportPath;
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0775,true)&&!is_dir(dirname($path))) throw new \RuntimeException('Berichtsverzeichnis konnte nicht erstellt werden.');
        $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false||file_put_contents($path,$json.PHP_EOL,LOCK_EX)===false) throw new \RuntimeException('RC-Bericht konnte nicht geschrieben werden.');
        return $report+['report_path'=>$path];
    }
    private function check(string $name,bool $ok,string $message,array $meta=[]):array{return ['name'=>$name,'status'=>$ok?'passed':'failed','message'=>$ok?'OK':$message,'metadata'=>$meta];}
}
