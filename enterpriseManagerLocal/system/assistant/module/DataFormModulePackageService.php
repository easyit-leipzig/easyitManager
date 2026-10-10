<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

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

final class DataFormModulePackageService
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
        $this->transferRoot = $this->root . '/storage/assistant/module-transfers';
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @param list<string> $rootDataForms @return array<string,mixed> */
    public function export(string $projectId, string $moduleName, array $rootDataForms, bool $includeTransitive = true, bool $includeDatasourceSnapshot = true): array
    {
        $this->assertProject($projectId);
        $moduleName = trim($moduleName);
        if ($moduleName === '') { throw new \InvalidArgumentException('Modulname fehlt.'); }
        $rootDataForms = $this->normalizeDataForms($rootDataForms);
        if ($rootDataForms === []) { throw new \InvalidArgumentException('Mindestens ein Start-DataForm ist erforderlich.'); }

        [$modules,$graph] = $this->collectModules($projectId, $rootDataForms, $includeTransitive);
        $entries = [];
        foreach ($modules as $dataFormId => $bundle) {
            foreach ($bundle as $assistantId => $state) {
                $entries['modules/' . $dataFormId . '/config/' . str_replace('.', '-', $assistantId) . '.json'] = $this->json($state);
            }
        }
        $entries['module/graph.json'] = $this->json($graph);
        $dependency = $this->dependencyDescriptor($projectId, $modules, $graph);
        $entries['module/dependencies.json'] = $this->json($dependency);
        $release = ['schema'=>'easyit.dataform.module-release.v1','version'=>'1.0.0','dependencies'=>[],'migrations'=>[]];
        $entries['module/release.json'] = $this->json($release);
        if ($includeDatasourceSnapshot) {
            $ds = $this->store->getForProject('datasource.configure', $projectId, $projectId);
            if ($ds !== []) { $entries['dependencies/datasource.json'] = $this->json($this->sanitizer->sanitize($ds)); }
        }

        $files=[];
        foreach ($entries as $entry=>$content) { $files[$entry]=['sha256'=>hash('sha256',$content),'size'=>strlen($content)]; }
        $manifest=[
            'schema'=>'easyit.dataform.module-package.v1',
            'createdAt'=>gmdate('c'),
            'module'=>['name'=>$moduleName,'sourceProject'=>$projectId,'rootDataForms'=>$rootDataForms,'dataForms'=>array_keys($modules),'transitive'=>$includeTransitive],
            'graph'=>['nodeCount'=>count($graph['nodes']),'edgeCount'=>count($graph['edges'])],
            'containsDatasourceSnapshot'=>isset($entries['dependencies/datasource.json']),
            'release'=>['version'=>'1.0.0','dependencyCount'=>0,'migrationCount'=>0],
            'files'=>$files,
        ];
        $entries['easyit-dataform-module.json']=$this->json($manifest);

        $dir=$this->transferRoot.'/exports'; $this->ensureDir($dir);
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_', $projectId.'-'.$moduleName) ?: 'module';
        $path=$dir.'/'.$safe.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.dataform-module.zip';
        $phar=new \PharData($path,0,null,\Phar::ZIP);
        foreach($entries as $entry=>$content){$phar->addFromString($entry,$content);} unset($phar);
        $sha=(string)hash_file('sha256',$path);
        file_put_contents($path.'.sha256',$sha.'  '.basename($path)."\n",LOCK_EX);
        return ['ok'=>true,'path'=>$path,'fileName'=>basename($path),'shaFileName'=>basename($path).'.sha256','sha256'=>$sha,'manifest'=>$manifest,'graph'=>$graph,'dependencies'=>$dependency,'moduleCount'=>count($modules),'fileCount'=>count($files)];
    }

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $upload @return array<string,mixed> */
    public function stageUpload(array $upload): array
    {
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new \RuntimeException('Modulpaket wurde nicht korrekt hochgeladen (Upload-Code '.$error.').');
        $tmp=(string)($upload['tmp_name']??''); if($tmp===''||!is_file($tmp))throw new \RuntimeException('Temporäre Upload-Datei fehlt.');
        if((int)($upload['size']??filesize($tmp)?:0)>50*1024*1024)throw new \RuntimeException('Modulpaket ist größer als 50 MiB.');
        if(!str_ends_with(strtolower((string)($upload['name']??'')),'.zip'))throw new \RuntimeException('Modulpaket muss ein ZIP-Archiv sein.');
        return $this->stagePath($tmp);
    }

    /** @return array<string,mixed> */
    public function stagePath(string $sourcePath): array
    {
        if(!is_file($sourcePath))throw new \RuntimeException('Modulpaket wurde nicht gefunden.');
        if((int)filesize($sourcePath)>50*1024*1024)throw new \RuntimeException('Modulpaket ist größer als 50 MiB.');
        $dir=$this->transferRoot.'/imports';$this->ensureDir($dir);$token=bin2hex(random_bytes(8));$path=$dir.'/'.$token.'.dataform-module.zip';
        if(!copy($sourcePath,$path))throw new \RuntimeException('Modulpaket konnte nicht in den Prüfbereich kopiert werden.');
        $inspection=$this->inspect($path);if(!($inspection['ok']??false)){@unlink($path);throw new \RuntimeException('Paketprüfung fehlgeschlagen: '.implode(' ',(array)($inspection['errors']??[])));}
        return ['token'=>$token,'path'=>$path,'inspection'=>$inspection];
    }

    /** @return array<string,mixed> */
    public function inspect(string $path): array
    {
        $errors=[];$manifest=[];$modules=[];$graph=[];$dependencies=[];$datasource=[];$release=['schema'=>'easyit.dataform.module-release.v1','version'=>'1.0.0','dependencies'=>[],'migrations'=>[]];$releaseGate=$this->legacyReleaseGate();$readPath='';
        try{
            if(!is_file($path))throw new \RuntimeException('Paketdatei wurde nicht gefunden.');
            $readPath=$this->freshArchivePath($path);
            $phar=new \PharData($readPath);
            if(!isset($phar['easyit-dataform-module.json']))throw new \RuntimeException('Modulmanifest fehlt.');
            $manifest=json_decode((string)$phar['easyit-dataform-module.json']->getContent(),true);
            if(!is_array($manifest)||($manifest['schema']??'')!=='easyit.dataform.module-package.v1')throw new \RuntimeException('Modulmanifest hat ein ungültiges Schema.');
            $files=is_array($manifest['files']??null)?$manifest['files']:[];
            if(count($files)>512)throw new \RuntimeException('Modulpaket enthält zu viele Dateien.');
            $allowed=array_fill_keys(array_merge(['easyit-dataform-module.json'],array_map('strval',array_keys($files))),true);
            foreach($this->archiveEntries($phar,$readPath) as $entry){$this->assertSafeEntry($entry);if(!isset($allowed[$entry]))throw new \RuntimeException('Unerwarteter ZIP-Eintrag: '.$entry);}
            foreach($files as $entry=>$meta){
                $entry=(string)$entry;$this->assertSafeEntry($entry);if(!isset($phar[$entry]))throw new \RuntimeException('Manifest-Datei fehlt: '.$entry);
                $content=(string)$phar[$entry]->getContent();if(strlen($content)>5*1024*1024)throw new \RuntimeException('Paketdatei ist zu groß: '.$entry);
                $expected=strtolower((string)($meta['sha256']??''));if($expected===''||!hash_equals($expected,hash('sha256',$content)))throw new \RuntimeException('SHA-256 stimmt nicht: '.$entry);
                if(preg_match('#^modules/([^/]+)/config/([^/]+)\.json$#',$entry,$m)){
                    $dataFormId=$m[1];$this->assertDataFormId($dataFormId);$assistantId=str_replace('-','.',$m[2]);
                    if(in_array($assistantId,self::PARTS,true)){$decoded=json_decode($content,true);if(!is_array($decoded))throw new \RuntimeException('Ungültiges JSON: '.$entry);$modules[$dataFormId][$assistantId]=$this->sanitizer->sanitize($decoded);}
                }elseif($entry==='module/graph.json'){$d=json_decode($content,true);if(is_array($d))$graph=$d;}
                elseif($entry==='module/dependencies.json'){$d=json_decode($content,true);if(is_array($d))$dependencies=$d;}
                elseif($entry==='module/release.json'){$d=json_decode($content,true);if(!is_array($d))throw new \RuntimeException('Ungültige Modul-Release-Metadaten.');$release=$this->validateRelease($d);}
                elseif($entry==='module/release-gate.json'){$d=json_decode($content,true);if(!is_array($d))throw new \RuntimeException('Ungültige Release-Gate-Metadaten.');$releaseGate=$this->validateReleaseGate($d);}
                elseif($entry==='dependencies/datasource.json'){$d=json_decode($content,true);if(is_array($d))$datasource=$this->sanitizer->sanitize($d);}
            }
            $listed=array_map('strval',(array)($manifest['module']['dataForms']??[]));sort($listed);$actual=array_keys($modules);sort($actual);
            if($listed!==$actual)throw new \RuntimeException('Manifest-DataForms und Paketinhalt stimmen nicht überein.');
            foreach($modules as $id=>$bundle){if(!isset($bundle['dataform.create']))throw new \RuntimeException('DataForm-Konfiguration fehlt für '.$id.'.');}
            $this->validateGraph($graph,$actual);
            unset($phar);
        }catch(\Throwable $e){$errors[]=$e->getMessage();}finally{if($readPath!=='')@unlink($readPath);}
        return ['ok'=>$errors===[],'errors'=>$errors,'manifest'=>$manifest,'modules'=>$modules,'graph'=>$graph,'dependencies'=>$dependencies,'release'=>$release,'releaseGate'=>$releaseGate,'datasource'=>$datasource,'sha256'=>is_file($path)?hash_file('sha256',$path):null,'releaseFingerprint'=>$errors===[]?$this->releaseFingerprint($path):null];
    }

    /** @param array<string,string> $dataFormMapping @param array<string,string> $referenceMapping @return array<string,mixed> */
    public function preview(string $token,string $targetProject,array $dataFormMapping,array $referenceMapping=[],bool $allowOverwrite=false,bool $applyDatasourceSnapshot=false):array
    {
        $this->assertProject($targetProject);$inspection=$this->inspect($this->tokenPath($token));if(!($inspection['ok']??false))return $this->report((array)$inspection['errors']);
        $sourceIds=array_keys((array)$inspection['modules']);$mapping=[];$errors=[];$warnings=[];$checks=[];
        foreach($sourceIds as $source){$target=trim((string)($dataFormMapping[$source]??$source));try{$this->assertDataFormId($target);$mapping[$source]=$target;}catch(\Throwable $e){$errors[]=$source.': '.$e->getMessage();}}
        if(count(array_unique(array_values($mapping)))!==count($mapping))$errors[]='Mehrere Quell-DataForms dürfen nicht auf dasselbe Ziel-DataForm gemappt werden.';
        $allMapping=$referenceMapping;foreach($mapping as $from=>$to)$allMapping[$from]=$to;
        $mappedModules=[];$existing=[];
        foreach((array)$inspection['modules'] as $sourceId=>$bundle){
            $targetId=$mapping[$sourceId]??$sourceId;$template=['source'=>['dataFormId'=>$sourceId],'bundle'=>$bundle];$mapped=(new DataFormTemplateMapper())->map($template,$targetId,$allMapping);$mappedModules[$targetId]=$mapped;
            foreach(self::PARTS as $assistantId){if(!isset($mapped[$assistantId]))continue;$scope=$targetProject.'|'.$targetId;$state=$this->store->getForProject($assistantId,$targetProject,$scope);if($state!==[])$existing[]=$targetId.':'.$assistantId;}
            $this->validateMapped($targetId,$mapped,$errors,$warnings,$checks);
        }
        if($existing!==[]&&!$allowOverwrite)$errors[]='Am Ziel existieren bereits Konfigurationen: '.implode(', ',$existing).'.';elseif($existing!==[])$warnings[]='Vorhandene Zielkonfigurationen werden historisiert überschrieben: '.implode(', ',$existing).'.';
        $checks[]=['id'=>'target.conflicts','status'=>$existing===[]?'PASS':($allowOverwrite?'WARN':'FAIL'),'details'=>['existing'=>$existing]];

        $mappedGraph=$this->walk((array)$inspection['graph'],$allMapping);
        $this->validateMappedGraph($mappedGraph,array_keys($mappedModules),$errors,$warnings,$checks);
        $dsPreview=null;$requiredProfiles=$this->mappedProfiles($mappedModules);$requiredSources=$this->mappedSources($mappedModules,$mappedGraph);
        if($applyDatasourceSnapshot){
            if(($inspection['datasource']??[])===[])$errors[]='Paket enthält keinen Datenquellen-Snapshot.';
            else{$ds=$this->walk((array)$inspection['datasource'],$allMapping);$v=(new DataSourceDraftValidator($this->dataSources))->validate(new DataSourceDraft($ds),null);foreach($v['errors'] as $e)$errors[]='datasource: '.$e;foreach($v['warnings'] as $w)$warnings[]='datasource: '.$w;$dsPreview=$ds;$checks[]=['id'=>'datasource.snapshot','status'=>$v['errors']===[]?($v['warnings']===[]?'PASS':'WARN'):'FAIL','errors'=>$v['errors'],'warnings'=>$v['warnings']];}
        }else{
            $targetDs=$this->store->getForProject('datasource.configure',$targetProject,$targetProject);
            if($targetDs===[]){$errors[]='Zielprojekt besitzt kein persistiertes Datenquellenprofil.';$checks[]=['id'=>'dependency.datasource','status'=>'FAIL'];}
            else{
                $actualProfile=trim((string)($targetDs['profile']['name']??''));$discovered=array_map(static fn($d)=>(string)($d['name']??''),(array)($targetDs['discovery']??[]));$depErrors=[];
                foreach($requiredProfiles as $p){if($p!==''&&$actualProfile!==$p)$depErrors[]='Zielprofil "'.$actualProfile.'" entspricht nicht "'.$p.'".';}
                foreach($requiredSources as $s){if($s!==''&&!in_array($s,$discovered,true))$depErrors[]='Zielquelle "'.$s.'" fehlt in der Discovery-Liste.';}
                foreach($depErrors as $e)$errors[]='datasource dependency: '.$e;$checks[]=['id'=>'dependency.datasource','status'=>$depErrors===[]?'PASS':'FAIL','details'=>['requiredProfiles'=>$requiredProfiles,'requiredSources'=>$requiredSources,'actualProfile'=>$actualProfile,'discovered'=>$discovered]];
            }
        }
        return $this->report($errors,$warnings,$checks,$mappedModules,['token'=>$token,'targetProject'=>$targetProject,'dataFormMapping'=>$mapping,'referenceMapping'=>$referenceMapping,'allowOverwrite'=>$allowOverwrite,'applyDatasourceSnapshot'=>$applyDatasourceSnapshot,'datasourceSnapshot'=>$dsPreview,'mappedGraph'=>$mappedGraph,'inspection'=>['manifest'=>$inspection['manifest'],'sha256'=>$inspection['sha256']]]);
    }

    /** @param array<string,string> $dataFormMapping @param array<string,string> $referenceMapping @return array<string,mixed> */
    public function apply(string $token,string $targetProject,array $dataFormMapping,array $referenceMapping=[],bool $allowOverwrite=false,bool $applyDatasourceSnapshot=false):array
    {
        $preview=$this->preview($token,$targetProject,$dataFormMapping,$referenceMapping,$allowOverwrite,$applyDatasourceSnapshot);if(($preview['verdict']??'')==='FAIL')return $preview+['applied'=>false];
        foreach((array)($preview['mappedModules']??[]) as $targetDataForm=>$bundle){foreach((array)$bundle as $assistantId=>$state){if(!is_array($state))continue;$scope=$targetProject.'|'.$targetDataForm;$this->store->putForProject((string)$assistantId,$targetProject,$state,$scope);}}
        if($applyDatasourceSnapshot&&is_array($preview['target']['datasourceSnapshot']??null))$this->store->putForProject('datasource.configure',$targetProject,$preview['target']['datasourceSnapshot'],$targetProject);
        $diagnostics=[];foreach(array_keys((array)($preview['mappedModules']??[])) as $df){$diagnostics[$df]=$this->diagnostics->diagnose($targetProject,(string)$df,['runtimeDataSource'=>false,'sampleParentValue'=>42])->jsonSerialize();}
        return $preview+['applied'=>true,'appliedAt'=>gmdate('c'),'diagnosticReports'=>$diagnostics];
    }

    /** @param array<string,mixed> $release */
    public function withReleaseMetadata(string $sourcePath,array $release,?array $releaseGate=null):string
    {
        if(!is_file($sourcePath))throw new \RuntimeException('Modulpaket für Release-Metadaten wurde nicht gefunden.');
        $release=$this->validateRelease($release);$inspection=$this->inspect($sourcePath);if(!($inspection['ok']??false))throw new \RuntimeException('Modulpaket ist vor Release-Aktualisierung ungültig.');
        $fresh=$this->freshArchivePath($sourcePath);$old=new \PharData($fresh);$entries=[];foreach($this->archiveEntries($old,$fresh) as $entry){if(in_array($entry,['easyit-dataform-module.json','module/release.json','module/release-gate.json'],true))continue;$entries[$entry]=(string)$old[$entry]->getContent();}unset($old);@unlink($fresh);
        $entries['module/release.json']=$this->json($release);if($releaseGate!==null)$entries['module/release-gate.json']=$this->json($this->validateReleaseGate($releaseGate));elseif(empty(($inspection['releaseGate']['legacy']??true)))$entries['module/release-gate.json']=$this->json((array)$inspection['releaseGate']);$manifest=(array)$inspection['manifest'];$files=[];foreach($entries as $entry=>$content)$files[$entry]=['sha256'=>hash('sha256',$content),'size'=>strlen($content)];$manifest['files']=$files;$manifest['release']=['version'=>$release['version'],'dependencyCount'=>count((array)$release['dependencies']),'migrationCount'=>count((array)$release['migrations']),'gateStatus'=>$releaseGate['status']??($inspection['releaseGate']['status']??'LEGACY_RELEASED')];$entries['easyit-dataform-module.json']=$this->json($manifest);
        $dir=$this->transferRoot.'/exports';$this->ensureDir($dir);$path=$dir.'/release-'.bin2hex(random_bytes(8)).'.dataform-module.zip';$phar=new \PharData($path,0,null,\Phar::ZIP);foreach($entries as $entry=>$content)$phar->addFromString($entry,$content);unset($phar);
        $check=$this->inspect($path);if(!($check['ok']??false)){@unlink($path);throw new \RuntimeException('Release-aktualisiertes Modulpaket ist ungültig: '.implode(' ',(array)($check['errors']??[])));}return $path;
    }

    /** @param array<string,mixed> $gate */
    public function withReleaseGateMetadata(string $sourcePath,array $gate):string
    {
        if(!is_file($sourcePath))throw new \RuntimeException('Modulpaket für Release-Gate-Metadaten wurde nicht gefunden.');
        $inspection=$this->inspect($sourcePath);if(!($inspection['ok']??false))throw new \RuntimeException('Modulpaket ist vor Gate-Aktualisierung ungültig.');$gate=$this->validateReleaseGate($gate);
        $fresh=$this->freshArchivePath($sourcePath);$old=new \PharData($fresh);$entries=[];foreach($this->archiveEntries($old,$fresh) as $entry){if(in_array($entry,['easyit-dataform-module.json','module/release-gate.json'],true))continue;$entries[$entry]=(string)$old[$entry]->getContent();}unset($old);@unlink($fresh);
        $entries['module/release-gate.json']=$this->json($gate);$manifest=(array)$inspection['manifest'];$files=[];foreach($entries as $entry=>$content)$files[$entry]=['sha256'=>hash('sha256',$content),'size'=>strlen($content)];$manifest['files']=$files;$manifest['release']=array_merge((array)($manifest['release']??[]),['gateStatus'=>$gate['status'],'installable'=>$gate['installable']]);$entries['easyit-dataform-module.json']=$this->json($manifest);
        $dir=$this->transferRoot.'/exports';$this->ensureDir($dir);$path=$dir.'/gate-'.bin2hex(random_bytes(8)).'.dataform-module.zip';$phar=new \PharData($path,0,null,\Phar::ZIP);foreach($entries as $entry=>$content)$phar->addFromString($entry,$content);unset($phar);$check=$this->inspect($path);if(!($check['ok']??false)){@unlink($path);throw new \RuntimeException('Gate-aktualisiertes Modulpaket ist ungültig.');}return $path;
    }

    public function releaseFingerprint(string $path):string
    {
        if(!is_file($path))throw new \RuntimeException('Modulpaket wurde für Fingerprint nicht gefunden.');$fresh=$this->freshArchivePath($path);try{$phar=new \PharData($fresh);$parts=[];foreach($this->archiveEntries($phar,$fresh) as $entry){if(in_array($entry,['easyit-dataform-module.json','module/release-gate.json'],true))continue;$parts[]=$entry."\0".hash('sha256',(string)$phar[$entry]->getContent());}sort($parts,SORT_STRING);return hash('sha256',implode("\n",$parts));}finally{@unlink($fresh);}
    }

    /** @return array<string,mixed> */
    private function legacyReleaseGate():array{return ['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true,'releaseVersion'=>null,'reportId'=>null,'reportSha256'=>null,'releaseFingerprint'=>null,'checkedAt'=>null,'releasedAt'=>null];}

    /** @param array<string,mixed> $gate @return array<string,mixed> */
    private function validateReleaseGate(array $gate):array
    {
        $status=strtoupper(trim((string)($gate['status']??'DRAFT')));$allowed=['DRAFT','GATE_FAILED','GATE_PASSED','RELEASED','LEGACY_RELEASED'];if(!in_array($status,$allowed,true))throw new \InvalidArgumentException('Ungültiger Release-Gate-Status: '.$status);$legacy=$status==='LEGACY_RELEASED'||!empty($gate['legacy']);$installable=$status==='RELEASED'||$status==='LEGACY_RELEASED';$fingerprint=trim((string)($gate['releaseFingerprint']??''));if($fingerprint!==''&&!preg_match('/^[a-f0-9]{64}$/',$fingerprint))throw new \InvalidArgumentException('Ungültiger Release-Fingerprint.');$reportSha=trim((string)($gate['reportSha256']??''));if($reportSha!==''&&!preg_match('/^[a-f0-9]{64}$/',$reportSha))throw new \InvalidArgumentException('Ungültige Gate-Berichtsprüfsumme.');return ['schema'=>'easyit.dataform.module-release-gate.v1','status'=>$status,'installable'=>$installable,'legacy'=>$legacy,'releaseVersion'=>isset($gate['releaseVersion'])?(string)$gate['releaseVersion']:null,'reportId'=>$gate['reportId']??null,'reportSha256'=>$reportSha!==''?$reportSha:null,'releaseFingerprint'=>$fingerprint!==''?$fingerprint:null,'validationProject'=>$gate['validationProject']??null,'checkedAt'=>$gate['checkedAt']??null,'releasedAt'=>$gate['releasedAt']??null,'warningsAccepted'=>!empty($gate['warningsAccepted'])];}

    /** @param array<string,mixed> $release @return array<string,mixed> */
    private function validateRelease(array $release):array
    {
        $version=SemVersion::normalize((string)($release['version']??'1.0.0'));$deps=[];
        foreach((array)($release['dependencies']??[]) as $dep){if(!is_array($dep))throw new \InvalidArgumentException('Ungültige Modulabhängigkeit.');$id=trim((string)($dep['moduleId']??''));if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Abhängigkeits-Modul-ID: '.$id);$constraint=trim((string)($dep['constraint']??'*'));if(!SemVersion::isValidConstraint($constraint))throw new \InvalidArgumentException('Ungültige Versionsbedingung für '.$id.': '.$constraint);$deps[]=['moduleId'=>$id,'constraint'=>$constraint===''?'*':$constraint,'optional'=>!empty($dep['optional'])];}
        $migrations=[];$seen=[];$allowed=['config.set','config.unset','field.add','field.rename','field.remove','csv.create_table','csv.add_column','csv.rename_column','csv.remove_column','csv.map_values','database.sql'];
        foreach((array)($release['migrations']??[]) as $i=>$migration){if(!is_array($migration))throw new \InvalidArgumentException('Ungültige Migration an Position '.($i+1).'.');$id=trim((string)($migration['id']??''));if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Migrations-ID: '.$id);if(isset($seen[$id]))throw new \InvalidArgumentException('Migrations-ID ist doppelt: '.$id);$seen[$id]=true;$type=trim((string)($migration['type']??''));if(!in_array($type,$allowed,true))throw new \InvalidArgumentException('Nicht unterstützter Migrationstyp: '.$type);$from=trim((string)($migration['from']??'*'));$to=trim((string)($migration['to']??$version));if(!SemVersion::isValidConstraint($from))throw new \InvalidArgumentException('Ungültige from-Bedingung in Migration '.$id.': '.$from);if(!SemVersion::isValidConstraint($to))throw new \InvalidArgumentException('Ungültige to-Bedingung in Migration '.$id.': '.$to);$phase=strtolower(trim((string)($migration['phase']??'pre')));if(!in_array($phase,['pre','post'],true))throw new \InvalidArgumentException('Ungültige Migrationsphase in '.$id.'.');$destructive=!empty($migration['destructive']);if(in_array($type,['field.remove','csv.remove_column'],true)&&!$destructive)throw new \InvalidArgumentException('Destruktive Migration '.$id.' muss destructive=true deklarieren.');$payload=is_array($migration['payload']??null)?$migration['payload']:[];$migrations[]=['id'=>$id,'from'=>$from===''?'*':$from,'to'=>$to===''?$version:$to,'order'=>(int)($migration['order']??(($i+1)*10)),'phase'=>$phase,'type'=>$type,'destructive'=>$destructive,'description'=>trim((string)($migration['description']??'')),'dataForm'=>trim((string)($migration['dataForm']??'')),'payload'=>$payload];}
        usort($migrations,static fn($a,$b)=>[$a['phase']==='post'?1:0,$a['order'],$a['id']]<=>[$b['phase']==='post'?1:0,$b['order'],$b['id']]);
        return ['schema'=>'easyit.dataform.module-release.v1','version'=>$version,'dependencies'=>$deps,'migrations'=>$migrations];
    }

    /** @param list<string> $roots @return array{0:array<string,array<string,mixed>>,1:array<string,mixed>} */
    private function collectModules(string $projectId,array $roots,bool $transitive):array
    {
        $modules=[];$edges=[];$queue=$roots;$seen=[];
        while($queue!==[]){$id=array_shift($queue);if(isset($seen[$id]))continue;$seen[$id]=true;$bundle=$this->bundle($projectId,$id);if(!isset($bundle['dataform.create']))throw new \RuntimeException('Keine persistente DataForm-Konfiguration für "'.$id.'" gefunden.');$modules[$id]=$bundle;
            $relation=(array)($bundle['dataform.relations']??[]);if($relation!==[]){$parent=trim((string)($relation['parent']['dataForm']??$id));$child=trim((string)($relation['child']['dataForm']??''));if($parent!==''&&$child!==''){$edges[]=['type'=>(string)($relation['relation']['type']??'one_to_many'),'name'=>(string)($relation['relation']['name']??''),'from'=>$parent,'to'=>$child,'parentSource'=>(string)($relation['parent']['source']??''),'childSource'=>(string)($relation['child']['source']??''),'junctionSource'=>(string)($relation['manyToMany']['junctionSource']??'')];if($transitive&&!isset($seen[$child])&&$this->hasDataForm($projectId,$child))$queue[]=$child;}}
        }
        ksort($modules);$nodes=[];foreach($modules as $id=>$bundle){$df=(array)$bundle['dataform.create'];$nodes[]=['id'=>$id,'source'=>(string)($df['source']['name']??''),'profile'=>(string)($df['source']['profile']??''),'driver'=>(string)($df['source']['driver']??'')];}
        return [$modules,['schema'=>'easyit.dataform.module-graph.v1','nodes'=>$nodes,'edges'=>$edges,'roots'=>$roots]];
    }

    private function hasDataForm(string $projectId,string $dataFormId):bool{return $this->dataFormState($projectId,$dataFormId)!==[];}
    /** @return array<string,mixed> */
    private function dataFormState(string $projectId,string $dataFormId):array
    {
        $scoped=$this->store->getForProject('dataform.create',$projectId,$projectId.'|'.$dataFormId);if($scoped!==[])return $scoped;
        $legacy=$this->store->getForProject('dataform.create',$projectId,$projectId);return trim((string)($legacy['identity']['dataFormName']??''))===$dataFormId?$legacy:[];
    }
    /** @return array<string,array<string,mixed>> */
    private function bundle(string $projectId,string $dataFormId):array
    {
        $out=[];$df=$this->dataFormState($projectId,$dataFormId);if($df!==[])$out['dataform.create']=$this->sanitizer->sanitize($df);
        foreach(array_slice(self::PARTS,1) as $assistantId){$s=$this->store->getForProject($assistantId,$projectId,$projectId.'|'.$dataFormId);if($s!==[])$out[$assistantId]=$this->sanitizer->sanitize($s);}return $out;
    }
    /** @param array<string,array<string,mixed>> $modules */
    private function dependencyDescriptor(string $projectId,array $modules,array $graph):array
    {
        return ['projectId'=>$projectId,'profiles'=>$this->mappedProfiles($modules),'sources'=>$this->mappedSources($modules,$graph),'dataForms'=>array_keys($modules)];
    }
    /** @param array<string,array<string,mixed>> $modules @return list<string> */
    private function mappedProfiles(array $modules):array{$v=[];foreach($modules as $b){$p=trim((string)($b['dataform.create']['source']['profile']??''));if($p!=='')$v[]=$p;}return array_values(array_unique($v));}
    /** @param array<string,array<string,mixed>> $modules @return list<string> */
    private function mappedSources(array $modules,array $graph):array{$v=[];foreach($modules as $b){$s=trim((string)($b['dataform.create']['source']['name']??''));if($s!=='')$v[]=$s;}foreach((array)($graph['edges']??[]) as $e){foreach(['parentSource','childSource','junctionSource'] as $k){$s=trim((string)($e[$k]??''));if($s!=='')$v[]=$s;}}return array_values(array_unique($v));}

    /** @param array<string,array<string,mixed>> $mapped */
    private function validateMapped(string $dataFormId,array $mapped,array &$errors,array &$warnings,array &$checks):void
    {
        $validators=['dataform.create'=>fn($s)=>(new DataFormDraftValidator())->validate(new DataFormDraft($s),null),'dataform.fields'=>fn($s)=>(new FieldDraftValidator($this->fieldTypes))->validate(new FieldDraft($s),null),'dataform.relations'=>fn($s)=>(new RelationDraftValidator())->validate(new RelationDraft($s),null),'dataform.events'=>fn($s)=>(new EventDraftValidator())->validate(new EventDraft($s),null),'dataform.actions'=>fn($s)=>(new ActionDraftValidator($this->actions))->validate(new ActionDraft($s),null)];
        foreach($validators as $id=>$validator){if(!isset($mapped[$id]))continue;$v=$validator($mapped[$id]);foreach($v['errors'] as $e)$errors[]=$dataFormId.'/'.$id.': '.$e;foreach($v['warnings'] as $w)$warnings[]=$dataFormId.'/'.$id.': '.$w;$checks[]=['id'=>$dataFormId.'.'.$id.'.validation','status'=>$v['errors']===[]?($v['warnings']===[]?'PASS':'WARN'):'FAIL','errors'=>$v['errors'],'warnings'=>$v['warnings']];}
    }
    private function validateGraph(array $graph,array $moduleIds):void
    {
        if(($graph['schema']??'')!=='easyit.dataform.module-graph.v1')throw new \RuntimeException('Modulgraph hat ein ungültiges Schema.');$nodes=[];foreach((array)($graph['nodes']??[]) as $n)$nodes[]=(string)($n['id']??'');sort($nodes);$expected=$moduleIds;sort($expected);if($nodes!==$expected)throw new \RuntimeException('Modulgraph und DataForm-Bestand stimmen nicht überein.');
    }
    private function validateMappedGraph(array $graph,array $moduleIds,array &$errors,array &$warnings,array &$checks):void
    {
        $set=array_fill_keys($moduleIds,true);$external=[];foreach((array)($graph['edges']??[]) as $e){$from=(string)($e['from']??'');$to=(string)($e['to']??'');if($from!==''&&!isset($set[$from]))$external[]=$from;if($to!==''&&!isset($set[$to]))$external[]=$to;}$external=array_values(array_unique($external));if($external!==[])$warnings[]='Modulgraph enthält externe DataForm-Abhängigkeiten: '.implode(', ',$external).'.';$checks[]=['id'=>'module.graph','status'=>$external===[]?'PASS':'WARN','details'=>['externalDataForms'=>$external,'nodes'=>$moduleIds,'edges'=>$graph['edges']??[]]];
    }

    /** @param list<string> $errors @param list<string> $warnings @param list<array<string,mixed>> $checks @param array<string,mixed> $mapped @param array<string,mixed> $target */
    private function report(array $errors=[],array $warnings=[],array $checks=[],array $mapped=[],array $target=[]):array{return ['schema'=>'easyit.dataform.module-preview.v1','verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks,'mappedModules'=>$mapped,'target'=>$target];}
    /** @param list<string> $items @return list<string> */
    private function normalizeDataForms(array $items):array{$out=[];foreach($items as $id){$id=trim((string)$id);if($id==='')continue;$this->assertDataFormId($id);$out[]=$id;}return array_values(array_unique($out));}
    /** @return array<string,mixed> */
    private function walk(array $data,array $mapping):array{foreach($data as $k=>$v){if(is_array($v))$data[$k]=$this->walk($v,$mapping);elseif(is_string($v)&&array_key_exists($v,$mapping))$data[$k]=$mapping[$v];}return $data;}
    private function tokenPath(string $token):string{if(!preg_match('/^[a-f0-9]{16}$/',$token))throw new \InvalidArgumentException('Ungültiger Import-Token.');$path=$this->transferRoot.'/imports/'.$token.'.dataform-module.zip';if(!is_file($path))throw new \RuntimeException('Gestagtes Modulpaket wurde nicht gefunden.');return $path;}
    private function assertProject(string $projectId):void{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$projectId)||!is_dir($this->root.'/projects/'.$projectId))throw new \InvalidArgumentException('Projekt wurde nicht gefunden oder Projekt-ID ist ungültig: '.$projectId);}
    private function assertDataFormId(string $id):void{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige DataForm-ID: '.$id);}
    private function ensureDir(string $dir):void{if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden: '.$dir);}
    private function json(array $data):string{$j=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false)throw new \RuntimeException('JSON konnte nicht erzeugt werden.');return $j."\n";}
    private function assertSafeEntry(string $entry):void{if($entry===''||str_contains($entry,"\0")||str_starts_with($entry,'/')||str_starts_with($entry,'\\')||preg_match('#^[A-Za-z]:[\\\\/]#',$entry)||in_array('..',preg_split('#[\\\\/]#',$entry)?:[],true))throw new \RuntimeException('Unsicherer ZIP-Pfad: '.$entry);}
    private function freshArchivePath(string $source):string{$dir=$this->transferRoot.'/inspect';$this->ensureDir($dir);$tmp=$dir.'/'.bin2hex(random_bytes(12)).'.dataform-module.zip';if(!@copy($source,$tmp))throw new \RuntimeException('Modulpaket konnte nicht für frische Prüfung kopiert werden.');return $tmp;}
    /** @return list<string> */
    private function archiveEntries(\PharData $phar,string $path):array{$prefix='phar://'.str_replace('\\','/',$path).'/';$out=[];$it=new \RecursiveIteratorIterator($phar);foreach($it as $f){$p=str_replace('\\','/',$f->getPathname());if(str_starts_with($p,$prefix))$p=substr($p,strlen($prefix));if($p!==''&&!str_ends_with($p,'/'))$out[]=$p;}return $out;}
}
