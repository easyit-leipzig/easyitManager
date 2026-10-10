<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

use EasyIT\Assistant\Recovery\ProjectArchiveService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleReleaseGateService
{
    private string $root;

    public function __construct(
        private AssistantStateStore $state,
        private DataFormModuleLibraryStore $store,
        private DataFormModuleLibraryService $library,
        private DataFormModulePackageService $packages,
        private ModuleInstallationRegistry $installations,
        private ModuleDependencyService $dependencies,
        private ModuleUpdateService $updates,
        private ModuleMigrationService $migrations,
        private ModuleMigrationAuditService $audit,
        private ProjectArchiveService $archives,
        string $root,
        private ?ReleaseCatalogService $releaseCatalog = null
    ) { $this->root = rtrim($root, '/\\'); }

    /** @return array<string,mixed> */
    public function gate(string $locator, string $validationProject): array
    {
        [$visibility,$owner,$moduleId] = $this->parseLocator($locator);
        $this->assertProject($validationProject);
        $meta = $this->store->getInScope($moduleId,$visibility,$owner);
        if ($meta === []) throw new \RuntimeException('Modul wurde nicht gefunden: '.$locator);
        $packagePath = $this->store->packagePath($moduleId,$visibility,$owner);
        $inspection = $this->packages->inspect($packagePath);
        $checks=[];$errors=[];$warnings=[];
        $this->check($checks,$errors,'package.integrity',!empty($inspection['ok']),'Modulpaket ist vollständig und SHA-geprüft.',implode(' ',(array)($inspection['errors']??[])));
        $release=(array)($inspection['release']??[]);$version=SemVersion::normalize((string)($release['version']??'1.0.0'));
        $fingerprint=$this->packages->releaseFingerprint($packagePath);
        $this->check($checks,$errors,'release.version',true,'Release-Version '.$version.' ist SemVer-gültig.');

        $reportId='gate-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $clone=$this->cloneId($validationProject);
        $sourceBackup=null;$cloneCreated=false;$mode='unknown';$schemaDiff=['count'=>0,'changes'=>[],'summary'=>[]];$rollbackVerification=null;$execution=null;$checkpoint=null;$installedBefore=$this->installations->get($validationProject,$moduleId);
        try {
            $sourceBackup=$this->archives->backup($validationProject,true,false);
            $this->check($checks,$errors,'reference.backup',$sourceBackup->isOk(),'Referenzprojekt wurde vor der Gate-Prüfung gesichert.',$sourceBackup->getMessage());
            if(!$sourceBackup->isOk()) throw new \RuntimeException('Referenzprojekt konnte nicht für isolierte Prüfung geklont werden.');
            $restore=$this->archives->restore((string)$sourceBackup->getPath(),$clone);
            $this->check($checks,$errors,'sandbox.clone',$restore->isOk(),'Isolierter Projektklon wurde erzeugt.',$restore->getMessage());
            if(!$restore->isOk()) throw new \RuntimeException('Gate-Projektklon konnte nicht erzeugt werden.');
            $cloneCreated=true;

            $ds=$this->state->getForProject('datasource.configure',$clone,$clone);$driver=strtolower((string)($ds['profile']['driver']??$ds['driver']??''));
            if(in_array($driver,['mysql','pgsql','oracle','mssql'],true)){
                $this->check($checks,$errors,'sandbox.datasource-isolation',false,'','Automatische Release-Freigabe für externe '.$driver.'-Datenbanken ist blockiert. Verwenden Sie für das Gate eine lokal rücksetzbare CSV-/SQLite-Prüfumgebung.');
                throw new \RuntimeException('Externe Datenbank ist für vollautomatischen Gate-Rollback nicht isoliert genug.');
            }
            $this->check($checks,$errors,'sandbox.datasource-isolation',true,'Prüfdatenquelle '.$driver.' ist lokal und rollbackfähig.');

            $cloneInstalled=$this->installations->get($clone,$moduleId);
            if($cloneInstalled===[]){
                $mode='INITIAL_INSTALL';
                if(count((array)($release['migrations']??[]))>0){
                    $this->check($checks,$errors,'upgrade.path',false,'','Release enthält Migrationen, aber das Referenzprojekt besitzt keine installierte Vorgängerversion.');
                    throw new \RuntimeException('Für migrationspflichtige Releases ist eine installierte Vorgängerversion im Referenzprojekt erforderlich.');
                }
                $rootLocator=$this->cloneLocator($visibility,$owner,$moduleId,$validationProject,$clone);
                $prepared=$this->dependencies->prepare([$rootLocator],$clone,['global'=>[],'modules'=>[]],['global'=>[],'modules'=>[]],false,false,true);
                $this->planChecks($prepared,$checks,$errors,$warnings,'dependency');
                $this->check($checks,$errors,'dry-run',!empty($prepared['prepared'])&&($prepared['verdict']??'')!=='FAIL','Clean-Install-Dry-Run ist ausführbar.','Clean-Install-Dry-Run ist fehlgeschlagen.');
                if(empty($prepared['prepared'])) throw new \RuntimeException('Clean-Install-Dry-Run ist nicht ausführbar.');
                $before=$this->audit->captureSnapshot($clone);$cp=$this->archives->backup($clone,true,false);$checkpoint=$cp->jsonSerialize();
                $this->checkpointCheck($checkpoint,$checks,$errors);
                $execution=$this->dependencies->applyPrepared($prepared);
                $this->check($checks,$errors,'execution.install',!empty($execution['applied']),'Clean-Installation im Projektklon war erfolgreich.','Clean-Installation im Projektklon ist fehlgeschlagen.');
                if(empty($execution['applied'])) throw new \RuntimeException('Clean-Installation im Gate-Klon ist fehlgeschlagen.');
                $after=$this->audit->captureSnapshot($clone);$schemaDiff=$this->audit->diffSnapshots($before,$after);
                $this->check($checks,$errors,'schema.diff',isset($schemaDiff['count']),'Vorher-/Nachher-Schema-Diff wurde erzeugt.');
                $rb=$this->archives->restoreInPlace((string)($checkpoint['path']??''),$clone);if(!$rb->isOk()){$this->check($checks,$errors,'rollback',false,'',$rb->getMessage());throw new \RuntimeException('Gate-Rollback ist fehlgeschlagen.');}
                $rolled=$this->audit->captureSnapshot($clone);$rollbackVerification=$this->audit->diffSnapshots($before,$rolled);
                $this->check($checks,$errors,'rollback',($rollbackVerification['count']??-1)===0,'Rollback wurde exakt gegen den Vorzustand verifiziert.','Rollback weicht vom Vorzustand ab.');
            } else {
                $from=SemVersion::normalize((string)($cloneInstalled['version']??'1.0.0'));$mode='UPGRADE';
                $this->check($checks,$errors,'upgrade.path',SemVersion::compare($version,$from)>0,'Upgradepfad '.$from.' → '.$version.' ist vorwärtsgerichtet.','Release-Version ist nicht höher als die installierte Referenzversion.');
                if(SemVersion::compare($version,$from)<=0) throw new \RuntimeException('Kein gültiger Vorwärts-Upgradepfad.');
                if(count((array)($release['migrations']??[]))>0){
                    $prepared=$this->migrations->prepare($clone,[$moduleId],[],[],true,false,true);
                    $this->planChecks($prepared,$checks,$errors,$warnings,'migration');
                    $this->check($checks,$errors,'dry-run',!empty($prepared['prepared'])&&($prepared['verdict']??'')!=='FAIL','Migrations-Dry-Run ist ausführbar.','Migrations-Dry-Run ist fehlgeschlagen.');
                    if(empty($prepared['prepared'])) throw new \RuntimeException('Migrations-Dry-Run ist nicht ausführbar.');
                    $execution=$this->migrations->apply($prepared);$checkpoint=(array)($execution['rollbackCheckpoint']??[]);$this->checkpointCheck($checkpoint,$checks,$errors);
                    $this->check($checks,$errors,'execution.migration',!empty($execution['applied']),'Migration und Upgrade im Projektklon waren erfolgreich.','Migration/Upgrade im Projektklon ist fehlgeschlagen.');
                    if(empty($execution['applied'])) throw new \RuntimeException('Migration/Upgrade im Gate-Klon ist fehlgeschlagen.');
                    $runId=(string)($execution['migrationRunId']??'');$run=$runId!==''?$this->audit->getRun($clone,$runId):[];$schemaDiff=(array)($run['schemaDiff']??[]);
                    $this->check($checks,$errors,'schema.diff',$run!==[]&&isset($schemaDiff['count']),'Phase-23-Schema-Diff wurde für den Migrationslauf erzeugt.','Schema-Diff des Migrationslaufs fehlt.');
                    $rb=$runId!==''?$this->audit->rollback($clone,$runId,$clone):['ok'=>false,'message'=>'Migrations-Run-ID fehlt.'];$rollbackVerification=(array)($rb['verificationDiff']??[]);
                    $this->check($checks,$errors,'rollback',!empty($rb['ok'])&&($rollbackVerification['count']??-1)===0,'Migrationsrollback wurde exakt verifiziert.',(string)($rb['message']??'Rollback nicht verifiziert.'));
                } else {
                    $prepared=$this->updates->prepare($clone,[$moduleId],[],[],true,false,true);
                    $this->planChecks($prepared,$checks,$errors,$warnings,'update');
                    $this->check($checks,$errors,'dry-run',!empty($prepared['prepared'])&&($prepared['verdict']??'')!=='FAIL','Upgrade-Dry-Run ist ausführbar.','Upgrade-Dry-Run ist fehlgeschlagen.');
                    if(empty($prepared['prepared'])) throw new \RuntimeException('Upgrade-Dry-Run ist nicht ausführbar.');
                    $before=$this->audit->captureSnapshot($clone);$execution=$this->updates->apply($prepared);$checkpoint=(array)($execution['rollbackCheckpoint']??[]);$this->checkpointCheck($checkpoint,$checks,$errors);
                    $this->check($checks,$errors,'execution.update',!empty($execution['applied']),'Upgrade im Projektklon war erfolgreich.','Upgrade im Projektklon ist fehlgeschlagen.');
                    if(empty($execution['applied'])) throw new \RuntimeException('Upgrade im Gate-Klon ist fehlgeschlagen.');
                    $after=$this->audit->captureSnapshot($clone);$schemaDiff=$this->audit->diffSnapshots($before,$after);$this->check($checks,$errors,'schema.diff',isset($schemaDiff['count']),'Vorher-/Nachher-Schema-Diff wurde erzeugt.');
                    $rb=$this->archives->restoreInPlace((string)($checkpoint['path']??''),$clone);if(!$rb->isOk()){$this->check($checks,$errors,'rollback',false,'',$rb->getMessage());throw new \RuntimeException('Gate-Rollback ist fehlgeschlagen.');}
                    $rolled=$this->audit->captureSnapshot($clone);$rollbackVerification=$this->audit->diffSnapshots($before,$rolled);$this->check($checks,$errors,'rollback',($rollbackVerification['count']??-1)===0,'Rollback wurde exakt gegen den Vorzustand verifiziert.','Rollback weicht vom Vorzustand ab.');
                }
            }
        } catch (\Throwable $e) {
            if(!in_array($e->getMessage(),$errors,true))$errors[]=$e->getMessage();
        } finally {
            if($cloneCreated)$this->removeTree($this->root.'/projects/'.$clone);
            $this->cleanupBackups($clone);$this->cleanupRecovery($clone);
            if($sourceBackup!==null){$path=(string)$sourceBackup->getPath();if($path!==''){@unlink($path);@unlink($path.'.sha256');}}
        }

        $verdict=$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS');
        $report=[
            'schema'=>'easyit.assistant.module-release-gate-report.v1','reportId'=>$reportId,'moduleId'=>$moduleId,'locator'=>$locator,'releaseVersion'=>$version,'releaseFingerprint'=>$fingerprint,'validationProject'=>$validationProject,'validationMode'=>$mode,'verdict'=>$verdict,'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'checks'=>$checks,'schemaDiff'=>$schemaDiff,'rollbackVerification'=>$rollbackVerification,'executionSummary'=>$this->compactExecution($execution),'installedBefore'=>$installedBefore,'checkedAt'=>gmdate('c')
        ];
        $reportPath=$this->writeReport($visibility,$owner,$moduleId,$report);$reportSha=(string)hash_file('sha256',$reportPath);
        $gate=['schema'=>'easyit.dataform.module-release-gate.v1','status'=>$errors===[]?'GATE_PASSED':'GATE_FAILED','installable'=>false,'legacy'=>false,'releaseVersion'=>$version,'reportId'=>$reportId,'reportSha256'=>$reportSha,'releaseFingerprint'=>$fingerprint,'validationProject'=>$validationProject,'checkedAt'=>gmdate('c'),'releasedAt'=>null,'warningsAccepted'=>false];
        $saved=$this->library->updateReleaseGate($moduleId,$visibility,$owner,$gate,$errors===[]?'release-gate-pass':'release-gate-fail');
        return $report+['reportPath'=>$reportPath,'reportSha256'=>$reportSha,'releaseGate'=>$saved['releaseGate']??$gate,'installable'=>false];
    }

    /** @return array<string,mixed> */
    public function release(string $locator,string $reportId,string $confirmation,bool $acceptWarnings=false):array
    {
        [$visibility,$owner,$moduleId]=$this->parseLocator($locator);$meta=$this->store->getInScope($moduleId,$visibility,$owner);if($meta===[])throw new \RuntimeException('Modul wurde nicht gefunden.');$version=SemVersion::normalize((string)($meta['release']['version']??'1.0.0'));if(trim($confirmation)!==$moduleId.'@'.$version)throw new \RuntimeException('Freigabe abgebrochen: Bestätigung muss exakt '.$moduleId.'@'.$version.' lauten.');
        $report=$this->getReport($visibility,$owner,$moduleId,$reportId);if($report===[])throw new \RuntimeException('Gate-Bericht wurde nicht gefunden.');if(!in_array((string)($report['verdict']??''),['PASS','PASS_WITH_WARNINGS'],true))throw new \RuntimeException('Nur ein bestandenes Gate kann freigegeben werden.');if(($report['verdict']??'')==='PASS_WITH_WARNINGS'&&!$acceptWarnings)throw new \RuntimeException('Gate enthält Warnungen. Diese müssen für die Freigabe ausdrücklich akzeptiert werden.');
        $path=$this->reportPath($visibility,$owner,$moduleId,$reportId);$sha=(string)hash_file('sha256',$path);$currentGate=(array)($meta['releaseGate']??[]);if(($currentGate['reportId']??null)!==$reportId||!hash_equals((string)($currentGate['reportSha256']??''),$sha))throw new \RuntimeException('Gate-Bericht stimmt nicht mehr mit dem Release-Status überein.');$package=$this->store->packagePath($moduleId,$visibility,$owner);$fingerprint=$this->packages->releaseFingerprint($package);if(!hash_equals((string)($report['releaseFingerprint']??''),$fingerprint))throw new \RuntimeException('Release-Inhalt wurde nach dem Gate verändert. Gate muss erneut ausgeführt werden.');
        $gate=['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'RELEASED','installable'=>true,'legacy'=>false,'releaseVersion'=>$version,'reportId'=>$reportId,'reportSha256'=>$sha,'releaseFingerprint'=>$fingerprint,'validationProject'=>$report['validationProject']??null,'checkedAt'=>$report['checkedAt']??gmdate('c'),'releasedAt'=>gmdate('c'),'warningsAccepted'=>$acceptWarnings];$saved=$this->library->updateReleaseGate($moduleId,$visibility,$owner,$gate,'release-approved');$catalogEntry=$this->releaseCatalog?->catalogReleased($moduleId,$visibility,$owner);return ['schema'=>'easyit.assistant.module-release-approval.v1','released'=>true,'moduleId'=>$moduleId,'version'=>$version,'reportId'=>$reportId,'releaseGate'=>$saved['releaseGate']??$gate,'releaseCatalog'=>$catalogEntry,'releasedAt'=>gmdate('c')];
    }

    /** @return list<array<string,mixed>> */
    public function reports(string $locator):array{[$v,$p,$id]=$this->parseLocator($locator);$dir=$this->reportDirectory($v,$p,$id,false);if($dir===null||!is_dir($dir))return [];$out=[];foreach(glob($dir.'/gate-*.json')?:[] as $f){$d=json_decode((string)@file_get_contents($f),true);if(is_array($d))$out[]=$d;}usort($out,static fn($a,$b)=>strcmp((string)($b['checkedAt']??''),(string)($a['checkedAt']??'')));return $out;}

    /** @return array<string,mixed> */
    public function getReport(string $visibility,?string $owner,string $moduleId,string $reportId):array{$this->assertReportId($reportId);$path=$this->reportPath($visibility,$owner,$moduleId,$reportId);if(!is_file($path))return [];$d=json_decode((string)@file_get_contents($path),true);return is_array($d)?$d:[];}

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $checks @param list<string> $errors @param list<string> $warnings */
    private function planChecks(array $plan,array &$checks,array &$errors,array &$warnings,string $prefix):void{foreach((array)($plan['errors']??[]) as $e)$errors[]=(string)$e;foreach((array)($plan['warnings']??[]) as $w)$warnings[]=(string)$w;$checks[]=['id'=>$prefix.'.plan','status'=>($plan['verdict']??'')==='FAIL'?'FAIL':(($plan['verdict']??'')==='PASS_WITH_WARNINGS'?'WARN':'PASS'),'message'=>'Planstatus: '.(string)($plan['verdict']??'UNKNOWN'),'details'=>['prepared'=>!empty($plan['prepared']),'errors'=>$plan['errors']??[],'warnings'=>$plan['warnings']??[]]];}
    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function checkpointCheck(array $checkpoint,array &$checks,array &$errors):void{$path=(string)($checkpoint['path']??'');$sha=(string)($checkpoint['sha256']??'');$ok=$path!==''&&is_file($path)&&preg_match('/^[a-f0-9]{64}$/',$sha)&&hash_equals($sha,(string)hash_file('sha256',$path));$this->check($checks,$errors,'recovery.checkpoint',$ok,'Recovery-Checkpoint existiert und SHA-256 stimmt.','Recovery-Checkpoint fehlt oder Prüfsumme stimmt nicht.');}
    /** @param list<array<string,mixed>> $checks @param list<string> $errors */
    private function check(array &$checks,array &$errors,string $id,bool $ok,string $pass,string $fail=''):void{$message=$ok?$pass:($fail!==''?$fail:$pass);$checks[]=['id'=>$id,'status'=>$ok?'PASS':'FAIL','message'=>$message];if(!$ok&&$message!=='')$errors[]=$message;}
    /** @return array<string,mixed>|null */
    private function compactExecution(?array $e):?array{if($e===null)return null;return ['applied'=>!empty($e['applied']),'message'=>$e['message']??null,'migrationRunId'=>$e['migrationRunId']??null,'rollbackReady'=>$e['rollbackReady']??null];}
    private function cloneLocator(string $v,?string $owner,string $id,string $sourceProject,string $clone):string{return $v==='project'&&$owner===$sourceProject?'project::'.$clone.'::'.$id:($v==='project'?'project::'.$owner.'::'.$id:'system::'.$id);}
    /** @return array{0:string,1:?string,2:string} */
    private function parseLocator(string $locator):array{$p=explode('::',trim($locator));if(($p[0]??'')==='system'&&count($p)===2)return['system',null,$this->assertId($p[1])];if(($p[0]??'')==='project'&&count($p)===3)return['project',$this->assertId($p[1]),$this->assertId($p[2])];throw new \InvalidArgumentException('Ungültiger Modullocator: '.$locator);}
    private function assertProject(string $id):void{$this->assertId($id);if(!is_dir($this->root.'/projects/'.$id))throw new \InvalidArgumentException('Validierungsprojekt wurde nicht gefunden: '.$id);}
    private function assertId(string $id):string{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Modul-/Projekt-ID: '.$id);return $id;}
    private function assertReportId(string $id):void{if(!preg_match('/^gate-[A-Za-z0-9._-]{8,80}$/',$id))throw new \InvalidArgumentException('Ungültige Gate-Berichts-ID.');}
    private function cloneId(string $project):string{return substr('gate-'.$project.'-'.bin2hex(random_bytes(4)),0,120);}
    private function reportDirectory(string $v,?string $p,string $id,bool $create):?string{$scope=$v==='project'?'project-'.$p:'system';$dir=$this->root.'/storage/assistant/module-release-gates/'.$scope.'/'.$id;if($create&&!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))return null;return $dir;}
    private function reportPath(string $v,?string $p,string $id,string $reportId):string{$this->assertReportId($reportId);$dir=$this->reportDirectory($v,$p,$id,false);return ($dir??'').'/'.$reportId.'.json';}
    /** @param array<string,mixed> $report */
    private function writeReport(string $v,?string $p,string $id,array $report):string{$dir=$this->reportDirectory($v,$p,$id,true);if($dir===null)throw new \RuntimeException('Gate-Berichtsverzeichnis kann nicht angelegt werden.');$path=$dir.'/'.$report['reportId'].'.json';$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new \RuntimeException('Gate-Bericht kann nicht serialisiert werden.');if(@file_put_contents($path,$json."\n",LOCK_EX)===false)throw new \RuntimeException('Gate-Bericht kann nicht gespeichert werden.');return $path;}
    private function cleanupBackups(string $project):void{foreach(glob($this->root.'/backups/projects/'.$project.'-*.zip*')?:[] as $f)@unlink($f);$this->removeTree($this->root.'/storage/assistant/module-update-rollbacks/'.$project);}
    private function cleanupRecovery(string $project):void{foreach(glob($this->root.'/storage/recovery/'.$project.'-*')?:[] as $p)$this->removeTree($p);}
    private function removeTree(string $dir):void{if(!is_dir($dir))return;foreach(array_diff(scandir($dir)?:[],['.','..']) as $n){$p=$dir.'/'.$n;if(is_dir($p)&&!is_link($p))$this->removeTree($p);else @unlink($p);}@rmdir($dir);}
}
