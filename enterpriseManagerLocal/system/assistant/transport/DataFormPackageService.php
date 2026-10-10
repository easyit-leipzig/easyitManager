<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Transport;

use EasyIT\Assistant\Action\ActionDraft;
use EasyIT\Assistant\Action\ActionDraftValidator;
use EasyIT\Assistant\Action\DataFormActionRegistry;
use EasyIT\Assistant\DataForm\DataFormDraft;
use EasyIT\Assistant\DataForm\DataFormDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceDraft;
use EasyIT\Assistant\DataSource\DataSourceDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceRegistry;
use EasyIT\Assistant\Diagnostic\DataFormDiagnosticService;
use EasyIT\Assistant\Event\EventDraft;
use EasyIT\Assistant\Event\EventDraftValidator;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\Relation\RelationDraft;
use EasyIT\Assistant\Relation\RelationDraftValidator;
use EasyIT\Assistant\State\AssistantStateSanitizer;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Template\DataFormTemplateMapper;

final class DataFormPackageService
{
    /** @var list<string> */
    private const PARTS = ['dataform.create','dataform.fields','dataform.relations','dataform.events','dataform.actions'];
    private string $root;
    private string $transferRoot;
    private AssistantStateSanitizer $sanitizer;

    public function __construct(
        private AssistantStateStore $store,
        private DataSourceRegistry $dataSources,
        private FieldTypeRegistry $fieldTypes,
        private DataFormActionRegistry $actions,
        private DataFormDiagnosticService $diagnostics,
        string $enterpriseRoot
    ) {
        $this->root = rtrim($enterpriseRoot, '/\\');
        $this->transferRoot = $this->root . '/storage/assistant/transfers';
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @return array<string,mixed> */
    public function export(string $projectId, string $dataFormId, bool $includeDatasourceSnapshot = true): array
    {
        $this->assertProject($projectId);
        $this->assertDataFormId($dataFormId);
        $bundle = $this->bundle($projectId, $dataFormId);
        if (!isset($bundle['dataform.create'])) {
            throw new \RuntimeException('Keine persistente DataForm-Konfiguration gefunden.');
        }
        $actual = trim((string)($bundle['dataform.create']['identity']['dataFormName'] ?? ''));
        if ($actual !== '' && $actual !== $dataFormId) {
            throw new \RuntimeException('Persistierte DataForm-Konfiguration gehört zu "' . $actual . '".');
        }

        $dependency = $this->dependencyDescriptor($projectId, $bundle['dataform.create']);
        $entries = [];
        foreach ($bundle as $assistantId => $state) {
            $entries['config/' . str_replace('.', '-', $assistantId) . '.json'] = $this->json($state);
        }
        if ($includeDatasourceSnapshot && $dependency['profile'] !== '') {
            $ds = $this->store->getForProject('datasource.configure', $projectId, $projectId);
            if ($ds !== []) {
                $entries['dependencies/datasource.json'] = $this->json($this->sanitizer->sanitize($ds));
            }
        }
        $entries['dependencies/descriptor.json'] = $this->json($dependency);

        $files = [];
        foreach ($entries as $entry => $content) {
            $files[$entry] = ['sha256'=>hash('sha256', $content), 'size'=>strlen($content)];
        }
        $manifest = [
            'schema'=>'easyit.dataform.package.v1',
            'createdAt'=>gmdate('c'),
            'source'=>['projectId'=>$projectId,'dataFormId'=>$dataFormId],
            'parts'=>array_keys($bundle),
            'dependency'=>$dependency,
            'containsDatasourceSnapshot'=>isset($entries['dependencies/datasource.json']),
            'files'=>$files,
        ];
        $entries['easyit-dataform-package.json'] = $this->json($manifest);

        $dir = $this->transferRoot . '/exports';
        $this->ensureDir($dir);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $projectId . '-' . $dataFormId) ?: 'dataform';
        $path = $dir . '/' . $safe . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.dataform-package.zip';
        $phar = new \PharData($path, 0, null, \Phar::ZIP);
        foreach ($entries as $entry => $content) { $phar->addFromString($entry, $content); }
        unset($phar);
        $sha = (string)hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $sha . '  ' . basename($path) . "\n", LOCK_EX);
        return ['ok'=>true,'path'=>$path,'fileName'=>basename($path),'shaFileName'=>basename($path).'.sha256','sha256'=>$sha,'manifest'=>$manifest,'fileCount'=>count($files)];
    }

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $upload @return array<string,mixed> */
    public function stageUpload(array $upload): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) { throw new \RuntimeException('DataForm-Paket wurde nicht korrekt hochgeladen (Upload-Code ' . $error . ').'); }
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) { throw new \RuntimeException('Temporäre Upload-Datei fehlt.'); }
        if ((int)($upload['size'] ?? filesize($tmp) ?: 0) > 20 * 1024 * 1024) { throw new \RuntimeException('DataForm-Paket ist größer als 20 MiB.'); }
        $name = strtolower((string)($upload['name'] ?? ''));
        if (!str_ends_with($name, '.zip')) { throw new \RuntimeException('DataForm-Paket muss ein ZIP-Archiv sein.'); }
        $dir = $this->transferRoot . '/imports'; $this->ensureDir($dir);
        $token = bin2hex(random_bytes(8));
        $path = $dir . '/' . $token . '.dataform-package.zip';
        if (!copy($tmp, $path)) { throw new \RuntimeException('Upload konnte nicht in den Prüfbereich kopiert werden.'); }
        $inspection = $this->inspect($path);
        if (!($inspection['ok'] ?? false)) { @unlink($path); throw new \RuntimeException('Paketprüfung fehlgeschlagen: ' . implode(' ', (array)($inspection['errors'] ?? []))); }
        return ['token'=>$token,'path'=>$path,'inspection'=>$inspection];
    }

    /** @return array<string,mixed> */
    public function inspect(string $path): array
    {
        $errors=[]; $warnings=[]; $manifest=[]; $bundle=[]; $datasource=[]; $descriptor=[];
        try {
            if (!is_file($path)) { throw new \RuntimeException('Paketdatei wurde nicht gefunden.'); }
            $phar = new \PharData($path);
            if (!isset($phar['easyit-dataform-package.json'])) { throw new \RuntimeException('Paketmanifest fehlt.'); }
            $manifest = json_decode((string)$phar['easyit-dataform-package.json']->getContent(), true);
            if (!is_array($manifest) || ($manifest['schema'] ?? '') !== 'easyit.dataform.package.v1') { throw new \RuntimeException('Paketmanifest hat ein ungültiges Schema.'); }
            $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
            if (count($files) > 64) { throw new \RuntimeException('Paket enthält zu viele Dateien.'); }
            $allowedEntries = array_fill_keys(array_merge(['easyit-dataform-package.json'], array_map('strval', array_keys($files))), true);
            foreach ($this->archiveEntries($phar, $path) as $archiveEntry) {
                $this->assertSafeEntry($archiveEntry);
                if (!isset($allowedEntries[$archiveEntry])) { throw new \RuntimeException('Unerwarteter ZIP-Eintrag: ' . $archiveEntry); }
            }
            foreach ($files as $entry=>$meta) {
                $entry=(string)$entry; $this->assertSafeEntry($entry);
                if (!isset($phar[$entry])) { throw new \RuntimeException('Manifest-Datei fehlt: ' . $entry); }
                $content=(string)$phar[$entry]->getContent();
                if (strlen($content) > 5*1024*1024) { throw new \RuntimeException('Paketdatei ist zu groß: ' . $entry); }
                $expected=strtolower((string)($meta['sha256'] ?? ''));
                if ($expected==='' || !hash_equals($expected, hash('sha256',$content))) { throw new \RuntimeException('SHA-256 stimmt nicht: ' . $entry); }
                if (str_starts_with($entry,'config/')) {
                    $decoded=json_decode($content,true); if (!is_array($decoded)) throw new \RuntimeException('Ungültiges JSON: '.$entry);
                    $base=basename($entry,'.json'); $assistantId=str_replace('-','.',$base);
                    if (in_array($assistantId,self::PARTS,true)) $bundle[$assistantId]=$this->sanitizer->sanitize($decoded);
                } elseif ($entry==='dependencies/datasource.json') {
                    $decoded=json_decode($content,true); if (is_array($decoded)) $datasource=$this->sanitizer->sanitize($decoded);
                } elseif ($entry==='dependencies/descriptor.json') {
                    $decoded=json_decode($content,true); if (is_array($decoded)) $descriptor=$decoded;
                }
            }
            if (!isset($bundle['dataform.create'])) { throw new \RuntimeException('DataForm-Konfiguration fehlt im Paket.'); }
            unset($phar);
        } catch (\Throwable $e) { $errors[]=$e->getMessage(); }
        return ['ok'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings,'manifest'=>$manifest,'bundle'=>$bundle,'datasource'=>$datasource,'descriptor'=>$descriptor,'sha256'=>is_file($path)?hash_file('sha256',$path):null];
    }

    /** @param array<string,string> $mapping @return array<string,mixed> */
    public function preview(string $token, string $targetProject, string $targetDataForm, array $mapping=[], bool $allowOverwrite=false, bool $applyDatasourceSnapshot=false): array
    {
        $this->assertProject($targetProject); $this->assertDataFormId($targetDataForm);
        $path=$this->tokenPath($token); $inspection=$this->inspect($path);
        if (!($inspection['ok']??false)) return $this->report((array)$inspection['errors']);
        $sourceDataForm=(string)($inspection['manifest']['source']['dataFormId']??'');
        $template=['source'=>['dataFormId'=>$sourceDataForm],'bundle'=>$inspection['bundle']];
        $mapped=(new DataFormTemplateMapper())->map($template,$targetDataForm,$mapping);
        $errors=[]; $warnings=[]; $checks=[];
        $existing=[];
        foreach(self::PARTS as $assistantId){$scope=$targetProject.'|'.$targetDataForm; if($this->store->getForProject($assistantId,$targetProject,$scope)!==[])$existing[]=$assistantId;}
        if($existing!==[]&&!$allowOverwrite)$errors[]='Am Ziel existieren bereits Konfigurationen: '.implode(', ',$existing).'.';
        elseif($existing!==[])$warnings[]='Vorhandene Zielkonfigurationen werden historisiert überschrieben: '.implode(', ',$existing).'.';
        $checks[]=['id'=>'target.conflicts','status'=>$existing===[]?'PASS':($allowOverwrite?'WARN':'FAIL'),'details'=>['existing'=>$existing]];
        $this->validateMapped($mapped,$errors,$warnings,$checks);

        $dsPreview=null;
        $mappedDf = is_array($mapped['dataform.create'] ?? null) ? $mapped['dataform.create'] : [];
        $neededProfile = trim((string)($mappedDf['source']['profile'] ?? ''));
        $neededSource = trim((string)($mappedDf['source']['name'] ?? ''));
        if (!$applyDatasourceSnapshot) {
            $targetDs = $this->store->getForProject('datasource.configure', $targetProject, $targetProject);
            if ($targetDs === []) {
                $errors[] = 'Zielprojekt besitzt kein persistiertes Datenquellenprofil. Zielprofil anlegen/zuordnen oder Datenquellen-Snapshot ausdrücklich übernehmen.';
                $checks[] = ['id'=>'dependency.datasource','status'=>'FAIL','details'=>['requiredProfile'=>$neededProfile,'requiredSource'=>$neededSource]];
            } else {
                $actualProfile = trim((string)($targetDs['profile']['name'] ?? ''));
                $actualSource = trim((string)($targetDs['selection']['sourceName'] ?? ''));
                $depErrors=[];
                if($neededProfile!=='' && $actualProfile!==$neededProfile)$depErrors[]='Zielprofil "'.$actualProfile.'" entspricht nicht "'.$neededProfile.'".';
                if($neededSource!=='' && $actualSource!=='' && $actualSource!==$neededSource)$depErrors[]='Zielquelle "'.$actualSource.'" entspricht nicht "'.$neededSource.'".';
                foreach($depErrors as $e)$errors[]='datasource dependency: '.$e;
                $checks[]=['id'=>'dependency.datasource','status'=>$depErrors===[]?'PASS':'FAIL','details'=>['requiredProfile'=>$neededProfile,'requiredSource'=>$neededSource,'actualProfile'=>$actualProfile,'actualSource'=>$actualSource]];
            }
        }
        if($applyDatasourceSnapshot){
            if(($inspection['datasource']??[])===[]){$errors[]='Paket enthält keinen Datenquellen-Snapshot.';}
            else{
                $ds=$this->walk((array)$inspection['datasource'],$mapping);
                $v=(new DataSourceDraftValidator($this->dataSources))->validate(new DataSourceDraft($ds),null);
                foreach($v['errors'] as $e)$errors[]='datasource: '.$e;
                foreach($v['warnings'] as $w)$warnings[]='datasource: '.$w;
                $dsPreview=$ds;
                $checks[]=['id'=>'datasource.snapshot','status'=>$v['errors']===[]?($v['warnings']===[]?'PASS':'WARN'):'FAIL','errors'=>$v['errors'],'warnings'=>$v['warnings']];
            }
        }
        return $this->report($errors,$warnings,$checks,$mapped,[
            'token'=>$token,'targetProject'=>$targetProject,'targetDataForm'=>$targetDataForm,'mapping'=>$mapping,'allowOverwrite'=>$allowOverwrite,'applyDatasourceSnapshot'=>$applyDatasourceSnapshot,'datasourceSnapshot'=>$dsPreview,'inspection'=>['manifest'=>$inspection['manifest'],'descriptor'=>$inspection['descriptor'],'sha256'=>$inspection['sha256']]
        ]);
    }

    /** @param array<string,string> $mapping @return array<string,mixed> */
    public function apply(string $token,string $targetProject,string $targetDataForm,array $mapping=[],bool $allowOverwrite=false,bool $applyDatasourceSnapshot=false): array
    {
        $preview=$this->preview($token,$targetProject,$targetDataForm,$mapping,$allowOverwrite,$applyDatasourceSnapshot);
        if(($preview['verdict']??'')==='FAIL') return $preview+['applied'=>false];
        foreach((array)($preview['mappedBundle']??[]) as $assistantId=>$state){if(!is_array($state))continue;$scope=$targetProject.'|'.$targetDataForm;$this->store->putForProject((string)$assistantId,$targetProject,$state,$scope);if($assistantId==='dataform.create'&&$this->store->getForProject('dataform.create',$targetProject,$targetProject)===[])$this->store->putForProject('dataform.create',$targetProject,$state,$targetProject);}
        if($applyDatasourceSnapshot && is_array($preview['target']['datasourceSnapshot']??null)){$this->store->putForProject('datasource.configure',$targetProject,$preview['target']['datasourceSnapshot'],$targetProject);}
        $diag=$this->diagnostics->diagnose($targetProject,$targetDataForm,['runtimeDataSource'=>false,'sampleParentValue'=>42]);
        return $preview+['applied'=>true,'appliedAt'=>gmdate('c'),'diagnosticReport'=>$diag->jsonSerialize()];
    }

    /** @return array<string,mixed> */
    private function bundle(string $projectId,string $dataFormId):array{
        $out=[];
        $df=$this->store->getForProject('dataform.create',$projectId,$projectId.'|'.$dataFormId);
        if($df===[]){$legacy=$this->store->getForProject('dataform.create',$projectId,$projectId);if(trim((string)($legacy['identity']['dataFormName']??''))===$dataFormId)$df=$legacy;}
        if($df!==[])$out['dataform.create']=$this->sanitizer->sanitize($df);
        foreach(array_slice(self::PARTS,1) as $assistantId){$s=$this->store->getForProject($assistantId,$projectId,$projectId.'|'.$dataFormId);if($s!==[])$out[$assistantId]=$this->sanitizer->sanitize($s);} return $out;
    }
    /** @param array<string,mixed> $df */
    private function dependencyDescriptor(string $projectId,array $df):array{return ['projectId'=>$projectId,'profile'=>(string)($df['source']['profile']??''),'driver'=>(string)($df['source']['driver']??''),'sourceName'=>(string)($df['source']['name']??''),'connectionRef'=>(string)($df['source']['connection']??'')];}
    /** @param array<string,array<string,mixed>> $mapped @param list<string> $errors @param list<string> $warnings @param list<array<string,mixed>> $checks */
    private function validateMapped(array $mapped,array &$errors,array &$warnings,array &$checks):void{
        $validators=[
            'dataform.create'=>fn($s)=>(new DataFormDraftValidator())->validate(new DataFormDraft($s),null),
            'dataform.fields'=>fn($s)=>(new FieldDraftValidator($this->fieldTypes))->validate(new FieldDraft($s),null),
            'dataform.relations'=>fn($s)=>(new RelationDraftValidator())->validate(new RelationDraft($s),null),
            'dataform.events'=>fn($s)=>(new EventDraftValidator())->validate(new EventDraft($s),null),
            'dataform.actions'=>fn($s)=>(new ActionDraftValidator($this->actions))->validate(new ActionDraft($s),null),
        ];
        foreach($validators as $id=>$validator){if(!isset($mapped[$id]))continue;$v=$validator($mapped[$id]);foreach($v['errors'] as $e)$errors[]=$id.': '.$e;foreach($v['warnings'] as $w)$warnings[]=$id.': '.$w;$checks[]=['id'=>$id.'.validation','status'=>$v['errors']===[]?($v['warnings']===[]?'PASS':'WARN'):'FAIL','errors'=>$v['errors'],'warnings'=>$v['warnings']];}
    }
    /** @param list<string> $errors @param list<string> $warnings @param list<array<string,mixed>> $checks @param array<string,mixed> $mapped @param array<string,mixed> $target */
    private function report(array $errors=[],array $warnings=[],array $checks=[],array $mapped=[],array $target=[]):array{return ['schema'=>'easyit.dataform.package-preview.v1','verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks,'mappedBundle'=>$mapped,'target'=>$target];}
    private function tokenPath(string $token):string{if(!preg_match('/^[a-f0-9]{16}$/',$token))throw new \InvalidArgumentException('Ungültiges Import-Token.');$p=$this->transferRoot.'/imports/'.$token.'.dataform-package.zip';if(!is_file($p))throw new \RuntimeException('Import-Paket wurde nicht gefunden.');return $p;}
    private function assertProject(string $id):void{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id)||!is_dir($this->root.'/projects/'.$id))throw new \InvalidArgumentException('Projekt existiert nicht oder Projekt-ID ist ungültig: '.$id);}
    private function assertDataFormId(string $id):void{if($id===''||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('DataForm-ID ist ungültig.');}
    private function assertSafeEntry(string $entry):void{if($entry===''||str_contains($entry,"\0")||str_starts_with($entry,'/')||str_starts_with($entry,'\\')||preg_match('/^[A-Za-z]:[\\\\\/]/',$entry)||in_array('..',preg_split('#[\\\\/]#',$entry)?:[],true))throw new \RuntimeException('Unsicherer ZIP-Pfad: '.$entry);}
    /** @return list<string> */
    private function archiveEntries(\PharData $phar, string $archivePath): array
    {
        $entries=[]; $normalized=str_replace('\\','/',$archivePath); $marker=$normalized.'/';
        $it=new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach($it as $file){ if(!$file instanceof \SplFileInfo || !$file->isFile()) continue; $p=str_replace('\\','/',$file->getPathname()); $pos=strpos($p,$marker); if($pos===false){$zipPos=strpos($p,'.zip/'); if($zipPos===false) continue; $entry=substr($p,$zipPos+5);} else {$entry=substr($p,$pos+strlen($marker));} if($entry!==''&&!in_array($entry,$entries,true))$entries[]=$entry; }
        return $entries;
    }
    private function ensureDir(string $dir):void{if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden: '.$dir);}
    private function json(array $v):string{$j=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($j))throw new \RuntimeException('JSON konnte nicht erzeugt werden.');return $j."\n";}
    /** @param array<string,mixed> $value @param array<string,string> $mapping @return array<string,mixed> */
    private function walk(array $value,array $mapping):array{foreach($value as $k=>$item){if(is_array($item))$value[$k]=$this->walk($item,$mapping);elseif(is_string($item)&&array_key_exists($item,$mapping))$value[$k]=$mapping[$item];}return $value;}
}
