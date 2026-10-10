<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

use EasyIT\Assistant\Recovery\ProjectArchiveService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleMigrationService
{
    private string $root;

    public function __construct(
        private AssistantStateStore $state,
        private DataFormModuleLibraryStore $libraryStore,
        private ModuleInstallationRegistry $installations,
        private ModuleUpdateService $updates,
        private ProjectArchiveService $archives,
        string $root,
        private ?ModuleMigrationAuditService $audit = null
    ) { $this->root = rtrim($root, '/\\'); }

    /**
     * @param list<string> $moduleIds
     * @param array<string,mixed> $dataFormMappings
     * @param array<string,mixed> $referenceMappings
     * @return array<string,mixed>
     */
    public function prepare(string $projectId,array $moduleIds=[],array $dataFormMappings=[],array $referenceMappings=[],bool $cascade=true,bool $applyDatasource=false,bool $allowUnreleased=false):array
    {
        $update=$this->updates->prepare($projectId,$moduleIds,$dataFormMappings,$referenceMappings,$cascade,$applyDatasource,$allowUnreleased);
        if(($update['verdict']??'')==='FAIL')return ['schema'=>'easyit.assistant.module-migration-plan.v1','verdict'=>'FAIL','errors'=>(array)($update['errors']??[]),'warnings'=>(array)($update['warnings']??[]),'projectId'=>$projectId,'updatePlan'=>$update,'steps'=>[],'prepared'=>false];
        $errors=[];$warnings=[];$steps=[];$locators=[];
        foreach((array)($update['rootLocators']??[]) as $loc){try{[$v,$p,$id]=$this->parseLocator((string)$loc);$locators[$id]=[$v,$p,(string)$loc];}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        foreach((array)($update['selected']??[]) as $moduleId){$moduleId=(string)$moduleId;$installed=$this->installations->get($projectId,$moduleId);if($installed===[])continue;$locator=$locators[$moduleId]??null;if($locator===null){$locator=$this->candidateLocator($moduleId,$projectId,(string)($installed['sourceLocator']??''));if($locator===null){$errors[]='Migrations-Release für '.$moduleId.' wurde nicht gefunden.';continue;}}
            [$visibility,$owner,$loc]=$locator;$meta=$this->libraryStore->getInScope($moduleId,$visibility,$owner);$release=$this->release($meta);$fromVersion=SemVersion::normalize((string)($installed['version']??'1.0.0'));$toVersion=(string)$release['version'];
            foreach((array)$release['migrations'] as $migration){if(!is_array($migration)||!$this->applies($migration,$fromVersion,$toVersion))continue;$mid=(string)$migration['id'];if($this->wasApplied($projectId,$moduleId,$toVersion,$mid)){$steps[]=$this->stepRecord($moduleId,$fromVersion,$toVersion,$migration,'SKIP','Migration wurde für diese Zielversion bereits protokolliert.',[],[]);continue;}
                $mapped=$migration;$mapped['dataForm']=$this->mapValue((string)($migration['dataForm']??''),$moduleId,(array)($update['dataFormMappings']??[]));$mapped['payload']=$this->mapRecursive((array)($migration['payload']??[]),$moduleId,(array)($update['referenceMappings']??[]),(array)($update['dataFormMappings']??[]));$sim=$this->simulateStep($projectId,$mapped);$steps[]=$this->stepRecord($moduleId,$fromVersion,$toVersion,$mapped,(string)$sim['status'],(string)$sim['message'],(array)$sim['details'],(array)$sim['warnings']);if($sim['status']==='FAIL')$errors[]=$moduleId.'/'.$mid.': '.$sim['message'];foreach((array)$sim['warnings'] as $w)$warnings[]=$moduleId.'/'.$mid.': '.$w;
            }
        }
        usort($steps,static fn($a,$b)=>[($a['phase']??'pre')==='post'?1:0,(int)($a['order']??0),(string)($a['moduleId']??''),(string)($a['id']??'')]<=>[($b['phase']??'pre')==='post'?1:0,(int)($b['order']??0),(string)($b['moduleId']??''),(string)($b['id']??'')]);
        $active=array_values(array_filter($steps,static fn($s)=>($s['status']??'')!=='SKIP'));
        return ['schema'=>'easyit.assistant.module-migration-plan.v1','verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'projectId'=>$projectId,'updatePlan'=>$update,'steps'=>$steps,'migrationCount'=>count($active),'destructiveCount'=>count(array_filter($active,static fn($s)=>!empty($s['destructive']))),'prepared'=>$errors===[]&&!empty($update['prepared']),'preparedAt'=>gmdate('c')];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function apply(array $plan):array
    {
        if(empty($plan['prepared'])||($plan['verdict']??'')==='FAIL')return $plan+['applied'=>false,'message'=>'Migrationsplan ist nicht ausführbar.'];
        $project=(string)$plan['projectId'];
        $backup=$this->archives->backup($project,true,false);
        $checkpoint=$backup->jsonSerialize();
        if(!$backup->isOk())return $plan+['applied'=>false,'message'=>'Rollback-Backup konnte nicht erzeugt werden: '.$backup->getMessage(),'rollbackCheckpoint'=>$checkpoint];
        if($this->requiresExternalDatabaseRollback((array)$plan['steps'],$project)&&empty($checkpoint['details']['manifest']['database']['included']))return $plan+['applied'=>false,'rollbackReady'=>true,'rollbackCheckpoint'=>$checkpoint,'message'=>'Externe SQL-Migration wurde blockiert: Das Recovery-Backup enthält keinen physischen Datenbank-Dump (backup.dumpPath konfigurieren).'];

        $runId=$this->audit?->beginRun($project,$plan,$checkpoint);
        $results=[];$upgrade=null;
        foreach(['pre','post'] as $phase){
            if($phase==='post'){
                $upgrade=$this->updates->applyWithExistingCheckpoint((array)$plan['updatePlan'],$checkpoint);
                if(empty($upgrade['applied'])){
                    $message='Modulupgrade ist nach den Pre-Migrationen fehlgeschlagen. Recovery-Backup steht bereit.';
                    if($runId!==null)$this->audit?->finishRun($project,$runId,'FAILED',$results,null,$message);
                    return $plan+['applied'=>false,'rollbackReady'=>true,'rollbackCheckpoint'=>$checkpoint,'migrationResults'=>$results,'moduleUpdateResult'=>$upgrade,'migrationRunId'=>$runId,'message'=>$message];
                }
            }
            foreach((array)$plan['steps'] as $step){
                if(($step['phase']??'pre')!==$phase||($step['status']??'')==='SKIP')continue;
                try{
                    $r=$this->executeStep($project,$step);$results[]=$r;
                    $this->recordApplied($project,(string)$step['moduleId'],(string)$step['toVersion'],(string)$step['id'],$step,$r);
                }catch(\Throwable $e){
                    $message='Migration '.$step['moduleId'].'/'.$step['id'].' ist fehlgeschlagen: '.$e->getMessage().'. Recovery-Backup steht bereit.';
                    if($runId!==null)$this->audit?->finishRun($project,$runId,'FAILED',$results,$step,$message);
                    return $plan+['applied'=>false,'rollbackReady'=>true,'rollbackCheckpoint'=>$checkpoint,'migrationResults'=>$results,'migrationRunId'=>$runId,'failedStep'=>$step,'message'=>$message];
                }
            }
        }
        $auditRun=$runId!==null?$this->audit?->finishRun($project,$runId,'PASS',$results):null;
        return $plan+['applied'=>true,'rollbackReady'=>true,'rollbackCheckpoint'=>$checkpoint,'migrationResults'=>$results,'moduleUpdateResult'=>$upgrade,'migrationRunId'=>$runId,'migrationAuditRun'=>$auditRun,'appliedAt'=>gmdate('c'),'message'=>'Migrationen und Modulupgrade wurden vollständig ausgeführt.'];
    }

    /** @return array<string,mixed> */
    public function history(string $projectId):array{$path=$this->historyPath($projectId);if(!is_file($path))return ['schema'=>'easyit.assistant.module-migrations.v1','projectId'=>$projectId,'applied'=>[]];$d=json_decode((string)@file_get_contents($path),true);return is_array($d)?$d:['schema'=>'easyit.assistant.module-migrations.v1','projectId'=>$projectId,'applied'=>[]];}

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function simulateStep(string $project,array $m):array
    {
        $type=(string)$m['type'];$df=(string)($m['dataForm']??'');$payload=(array)($m['payload']??[]);$warnings=[];
        try{
            if(in_array($type,['field.add','field.rename','field.remove'],true)){$fields=$this->fieldState($project,$df);if($fields===[])throw new \RuntimeException('Feldzustand des DataForms wurde nicht gefunden: '.$df);$list=(array)($fields['fields']??[]);$names=array_map(static fn($f)=>(string)($f['name']??''),array_filter($list,'is_array'));
                if($type==='field.add'){$field=(array)($payload['field']??[]);$name=trim((string)($field['name']??''));if($name===''||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name))throw new \RuntimeException('field.add benötigt payload.field.name.');if(in_array($name,$names,true))throw new \RuntimeException('Zielfeld existiert bereits: '.$name);}
                elseif($type==='field.rename'){$from=(string)($payload['from']??'');$to=(string)($payload['to']??'');if(!in_array($from,$names,true))throw new \RuntimeException('Quellfeld existiert nicht: '.$from);if($to===''||in_array($to,$names,true))throw new \RuntimeException('Zielfeld ist ungültig oder existiert bereits: '.$to);}
                else{$name=(string)($payload['name']??'');if($name==='id')throw new \RuntimeException('Pflichtfeld id darf nicht entfernt werden.');if(!in_array($name,$names,true))throw new \RuntimeException('Zu entfernendes Feld existiert nicht: '.$name);if(empty($m['destructive']))throw new \RuntimeException('field.remove verlangt destructive=true.');}
                return ['status'=>'PASS','message'=>'DataForm-Feldmigration kann angewendet werden.','details'=>['dataForm'=>$df,'fieldCount'=>count($list)],'warnings'=>[]];}
            if(in_array($type,['config.set','config.unset'],true)){$aid=trim((string)($payload['assistantId']??''));$path=trim((string)($payload['path']??''));if($aid===''||$path==='')throw new \RuntimeException($type.' benötigt assistantId und path.');$scope=$this->scopeFor($project,$df,$aid);$existing=$this->state->getForProject($aid,$project,$scope);if($existing===[])$warnings[]='Zielzustand existiert noch nicht und wird bei config.set neu angelegt.';return ['status'=>'PASS','message'=>'Konfigurationsmigration ist strukturell gültig.','details'=>['assistantId'=>$aid,'scope'=>$scope,'path'=>$path],'warnings'=>$warnings];}
            if(str_starts_with($type,'csv.'))return $this->simulateCsv($project,$df,$type,$payload,$m);
            if($type==='database.sql')return $this->simulateSql($project,$payload,$m);
            throw new \RuntimeException('Nicht unterstützter Migrationstyp: '.$type);
        }catch(\Throwable $e){return ['status'=>'FAIL','message'=>$e->getMessage(),'details'=>[],'warnings'=>$warnings];}
    }

    /** @param array<string,mixed> $step @return array<string,mixed> */
    private function executeStep(string $project,array $step):array
    {
        $type=(string)$step['type'];$df=(string)($step['dataForm']??'');$payload=(array)($step['payload']??[]);
        if($type==='config.set'||$type==='config.unset'){$aid=(string)$payload['assistantId'];$scope=$this->scopeFor($project,$df,$aid);$state=$this->state->getForProject($aid,$project,$scope);if($type==='config.set')$this->dotSet($state,(string)$payload['path'],$payload['value']??null);else $this->dotUnset($state,(string)$payload['path']);$this->state->putForProject($aid,$project,$state,$scope);return $this->result($step,'Konfiguration aktualisiert.');}
        if(in_array($type,['field.add','field.rename','field.remove'],true)){$this->applyField($project,$df,$type,$payload);return $this->result($step,'DataForm-Feldmigration ausgeführt.');}
        if(str_starts_with($type,'csv.')){$details=$this->applyCsv($project,$df,$type,$payload,$step);return $this->result($step,'CSV-Migration ausgeführt.',$details);}
        if($type==='database.sql'){$details=$this->applySql($project,$payload);return $this->result($step,'SQL-Migration ausgeführt.',$details);}
        throw new \RuntimeException('Nicht unterstützter Migrationstyp: '.$type);
    }

    private function applyField(string $project,string $df,string $type,array $payload):void
    {
        $scope=$project.'|'.$df;$fs=$this->state->getForProject('dataform.fields',$project,$scope);$dc=$this->state->getForProject('dataform.create',$project,$scope);if($fs===[])throw new \RuntimeException('Feldzustand fehlt: '.$df);
        if($type==='field.add'){$field=(array)$payload['field'];$field=array_merge(['label'=>(string)($field['name']??''),'type'=>'text','required'=>false,'readOnly'=>false,'default'=>null],$field);$fs['fields'][]=$field;if($dc!==[])$dc['fields'][]=$field;}
        elseif($type==='field.rename'){$from=(string)$payload['from'];$to=(string)$payload['to'];$fs=$this->replaceFieldName($fs,$from,$to);if($dc!==[])$dc=$this->replaceFieldName($dc,$from,$to);}
        else{$name=(string)$payload['name'];$fs['fields']=array_values(array_filter((array)($fs['fields']??[]),static fn($f)=>!is_array($f)||(string)($f['name']??'')!==$name));foreach(['lookups','derivedEnums'] as $k)$fs[$k]=array_values(array_filter((array)($fs[$k]??[]),static fn($x)=>!is_array($x)||(string)($x['field']??$x['name']??'')!==$name));if($dc!==[])$dc['fields']=array_values(array_filter((array)($dc['fields']??[]),static fn($f)=>!is_array($f)||(string)($f['name']??'')!==$name));}
        $this->state->putForProject('dataform.fields',$project,$fs,$scope);if($dc!==[])$this->state->putForProject('dataform.create',$project,$dc,$scope);
    }

    /** @return array<string,mixed> */
    private function simulateCsv(string $project,string $df,string $type,array $payload,array $migration):array
    {
        [$dir,$delimiter]=$this->csvDirectory($project);$source=trim((string)($payload['source']??''));if($source===''&&$df!==''){$dc=$this->state->getForProject('dataform.create',$project,$project.'|'.$df);$source=(string)($dc['source']['name']??'');}if($source==='')throw new \RuntimeException($type.' benötigt eine CSV-Quelle.');$file=$dir.'/'.$source.'.csv';
        if($type==='csv.create_table'){$cols=array_values(array_map('strval',(array)($payload['columns']??[])));if($cols===[]||!in_array('id',$cols,true))throw new \RuntimeException('csv.create_table benötigt columns einschließlich id.');if(is_file($file))throw new \RuntimeException('CSV-Tabelle existiert bereits: '.$source);return ['status'=>'PASS','message'=>'Neue CSV-Tabelle kann erstellt werden.','details'=>['file'=>$file,'columns'=>$cols],'warnings'=>[]];}
        if(!is_file($file))throw new \RuntimeException('CSV-Tabelle wurde nicht gefunden: '.$source);$header=$this->csvHeader($file,$delimiter);$column=(string)($payload['column']??'');
        if($type==='csv.add_column'&&($column===''||in_array($column,$header,true)))throw new \RuntimeException('Neue CSV-Spalte ist leer oder existiert bereits: '.$column);
        if($type==='csv.rename_column'){if(!in_array((string)($payload['from']??''),$header,true))throw new \RuntimeException('CSV-Quellspalte fehlt.');if(in_array((string)($payload['to']??''),$header,true))throw new \RuntimeException('CSV-Zielspalte existiert bereits.');}
        if($type==='csv.remove_column'){if($column==='id')throw new \RuntimeException('CSV-Pflichtspalte id darf nicht entfernt werden.');if(!in_array($column,$header,true))throw new \RuntimeException('CSV-Spalte fehlt: '.$column);if(empty($migration['destructive']))throw new \RuntimeException('csv.remove_column verlangt destructive=true.');}
        if($type==='csv.map_values'&&(!in_array($column,$header,true)||!is_array($payload['mapping']??null)))throw new \RuntimeException('csv.map_values benötigt vorhandene column und mapping.');
        return ['status'=>'PASS','message'=>'CSV-Migration kann atomar über eine Temporärdatei ausgeführt werden.','details'=>['file'=>$file,'columns'=>$header],'warnings'=>[]];
    }

    /** @return array<string,mixed> */
    private function applyCsv(string $project,string $df,string $type,array $payload,array $step):array
    {
        [$dir,$delimiter]=$this->csvDirectory($project);$source=trim((string)($payload['source']??''));if($source===''&&$df!==''){$dc=$this->state->getForProject('dataform.create',$project,$project.'|'.$df);$source=(string)($dc['source']['name']??'');}$file=$dir.'/'.$source.'.csv';
        if($type==='csv.create_table'){$cols=array_values(array_map('strval',(array)$payload['columns']));$this->writeCsv($file,$delimiter,$cols,[]);return ['file'=>$file,'rows'=>0];}
        [$header,$rows]=$this->readCsv($file,$delimiter);if($type==='csv.add_column'){$col=(string)$payload['column'];$header[]=$col;$def=(string)($payload['default']??'');foreach($rows as &$r)$r[]=$def;unset($r);}
        elseif($type==='csv.rename_column'){$i=array_search((string)$payload['from'],$header,true);$header[$i]=(string)$payload['to'];}
        elseif($type==='csv.remove_column'){$i=array_search((string)$payload['column'],$header,true);array_splice($header,$i,1);foreach($rows as &$r)array_splice($r,$i,1);unset($r);}
        elseif($type==='csv.map_values'){$i=array_search((string)$payload['column'],$header,true);$map=(array)$payload['mapping'];foreach($rows as &$r){$old=(string)($r[$i]??'');if(array_key_exists($old,$map))$r[$i]=(string)$map[$old];}unset($r);}else throw new \RuntimeException('CSV-Typ nicht unterstützt: '.$type);$this->writeCsv($file,$delimiter,$header,$rows);return ['file'=>$file,'rows'=>count($rows)];
    }

    /** @return array<string,mixed> */
    private function simulateSql(string $project,array $payload,array $migration):array
    {
        $ds=$this->dataSource($project);$driver=strtolower((string)($ds['profile']['driver']??$ds['driver']??''));if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';if(!in_array($driver,['mysql','pgsql','sqlite','oracle','mssql'],true))throw new \RuntimeException('database.sql ist für die konfigurierte Datenquelle nicht verfügbar: '.$driver);$sql=$this->sqlStatements($payload);if($sql===[])throw new \RuntimeException('database.sql benötigt payload.sql.');$destructive=false;foreach($sql as $q)if(preg_match('/\b(DROP|TRUNCATE|DELETE|ALTER\s+TABLE.+DROP)\b/i',$q))$destructive=true;if($destructive&&empty($migration['destructive']))throw new \RuntimeException('Destruktives SQL muss destructive=true deklarieren.');$pdoDriver=$driver==='oracle'?'oci':($driver==='mssql'?'sqlsrv':$driver);if(!class_exists(\PDO::class)||!in_array($pdoDriver,\PDO::getAvailableDrivers(),true))throw new \RuntimeException('Benötigter PDO-Treiber ist nicht verfügbar: '.$pdoDriver);$warnings=[];if(in_array($driver,['mysql','pgsql','oracle','mssql'],true))$warnings[]='Externe SQL-Migration wird nur ausgeführt, wenn das Recovery-Backup einen physischen Datenbank-Dump enthält.';return ['status'=>'PASS','message'=>'SQL-Migration ist strukturell und laufzeitseitig ausführbar.','details'=>['driver'=>$driver,'statementCount'=>count($sql)],'warnings'=>$warnings];
    }

    /** @return array<string,mixed> */
    private function applySql(string $project,array $payload):array
    {
        $ds=$this->dataSource($project);$driver=strtolower((string)($ds['profile']['driver']??$ds['driver']??''));$c=(array)($ds['connection']??[]);$password='';$ref=trim((string)($c['passwordRef']??''));if($ref!==''){$env=getenv($ref);if($env!==false)$password=(string)$env;}$pdo=$this->pdo($driver,$c,$password);$sql=$this->sqlStatements($payload);$tx=false;try{if(!$pdo->inTransaction())$tx=$pdo->beginTransaction();foreach($sql as $q)$pdo->exec($q);if($tx&&$pdo->inTransaction())$pdo->commit();}catch(\Throwable $e){if($tx&&$pdo->inTransaction())$pdo->rollBack();throw $e;}return ['driver'=>$driver,'statementCount'=>count($sql)];
    }

    private function pdo(string $driver,array $c,string $password):\PDO
    {
        $driver=strtolower(trim($driver));if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';
        $opt=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false];
        if($driver==='sqlite'){$path=$this->absolute((string)($c['path']??''));return new \PDO('sqlite:'.$path,null,null,$opt);}
        if($driver==='mysql'){$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??3306),(string)($c['database']??''),(string)($c['charset']??'utf8mb4'));return new \PDO($dsn,(string)($c['username']??''),$password,$opt);}
        if($driver==='pgsql'){$dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??5432),(string)($c['database']??''));$pdo=new \PDO($dsn,(string)($c['username']??''),$password,$opt);$pdo->exec("SET client_encoding TO 'UTF8'");$schema=trim((string)($c['schema']??'public'))?:'public';if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1)throw new \RuntimeException('Ungültiger PostgreSQL-Schemaname.');$pdo->exec('SET search_path TO "'.$schema.'"');return $pdo;}
        if($driver==='oracle'){$dsn=sprintf('oci:dbname=//%s:%d/%s;charset=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??1521),(string)($c['service']??''),(string)($c['charset']??'AL32UTF8'));return new \PDO($dsn,(string)($c['username']??''),$password,$opt);}
        if($driver==='mssql'){$dsn=sprintf('sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??1433),(string)($c['database']??''),!empty($c['encrypt'])?'true':'false',!empty($c['trustServerCertificate']??false)?'true':'false');return new \PDO($dsn,(string)($c['username']??''),$password,$opt);}
        throw new \RuntimeException('SQL-Treiber nicht unterstützt.');
    }

    /** @return list<string> */
    private function sqlStatements(array $payload):array{$v=$payload['sql']??[];$list=is_string($v)?[$v]:(is_array($v)?$v:[]);$out=[];foreach($list as $q){$q=trim((string)$q);if($q!==''){$out[]=$q;if(count($out)>50)throw new \RuntimeException('Maximal 50 SQL-Anweisungen pro Migrationsschritt.');}}return $out;}

    private function requiresExternalDatabaseRollback(array $steps,string $project):bool{$ds=$this->dataSource($project);$driver=strtolower((string)($ds['profile']['driver']??$ds['driver']??''));if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true))return false;foreach($steps as $s)if(($s['status']??'')!=='SKIP'&&($s['type']??'')==='database.sql')return true;return false;}
    /** @return array<string,mixed> */ private function dataSource(string $project):array{$d=$this->state->getForProject('datasource.configure',$project,$project);if($d===[])throw new \RuntimeException('Datenquellenkonfiguration fehlt für Projekt '.$project.'.');return $d;}
    /** @return array{0:string,1:string} */ private function csvDirectory(string $project):array{$d=$this->dataSource($project);$driver=strtolower((string)($d['profile']['driver']??$d['driver']??''));if($driver!=='csv')throw new \RuntimeException('CSV-Migration verlangt eine CSV-Datenquelle.');$c=(array)($d['connection']??[]);$dir=$this->absolute((string)($c['path']??''));if(!is_dir($dir))throw new \RuntimeException('CSV-Datenbankordner wurde nicht gefunden: '.$dir);return [$dir,(string)($c['delimiter']??'|')?:'|'];}
    private function absolute(string $path):string{$path=trim($path);if(preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path))return $path;return $this->root.'/'.str_replace(['\\','/'],DIRECTORY_SEPARATOR,$path);}

    /** @return array{0:list<string>,1:list<list<string>>} */
    private function readCsv(string $file,string $delimiter):array{$h=fopen($file,'rb');if($h===false)throw new \RuntimeException('CSV kann nicht gelesen werden.');try{$head=fgetcsv($h,0,$delimiter);if(!is_array($head))throw new \RuntimeException('CSV-Header fehlt.');$head=array_map(static fn($v)=>(string)$v,$head);$rows=[];while(($r=fgetcsv($h,0,$delimiter))!==false)$rows[]=array_map(static fn($v)=>(string)$v,$r);return [array_values($head),$rows];}finally{fclose($h);}}
    /** @return list<string> */ private function csvHeader(string $file,string $delimiter):array{return $this->readCsv($file,$delimiter)[0];}
    /** @param list<string> $header @param list<list<string>> $rows */
    private function writeCsv(string $file,string $delimiter,array $header,array $rows):void{$dir=dirname($file);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('CSV-Zielverzeichnis kann nicht erstellt werden.');$tmp=$file.'.tmp.'.bin2hex(random_bytes(4));$h=fopen($tmp,'wb');if($h===false)throw new \RuntimeException('CSV-Temporärdatei kann nicht erstellt werden.');try{fputcsv($h,$header,$delimiter);foreach($rows as $r)fputcsv($h,$r,$delimiter);fflush($h);}finally{fclose($h);}if(!@rename($tmp,$file)){@unlink($tmp);throw new \RuntimeException('CSV-Datei konnte nicht atomar ersetzt werden.');}}

    /** @return array<string,mixed> */ private function fieldState(string $project,string $df):array{return $df===''?[]:$this->state->getForProject('dataform.fields',$project,$project.'|'.$df);}
    private function scopeFor(string $project,string $df,string $assistantId):string{return str_starts_with($assistantId,'dataform.')&&$df!==''?$project.'|'.$df:$project;}
    /** @param array<string,mixed> $state @return array<string,mixed> */ private function replaceFieldName(array $state,string $from,string $to):array{$walk=function($v)use(&$walk,$from,$to){if(is_array($v)){foreach($v as $k=>$x)$v[$k]=$walk($x);return $v;}return is_string($v)&&$v===$from?$to:$v;};return $walk($state);}
    private function dotSet(array &$a,string $path,mixed $value):void{$parts=array_values(array_filter(explode('.',$path),static fn($p)=>$p!==''));if($parts===[])throw new \InvalidArgumentException('Leerer Konfigurationspfad.');$r=&$a;foreach($parts as $i=>$p){if($i===count($parts)-1){$r[$p]=$value;return;}if(!isset($r[$p])||!is_array($r[$p]))$r[$p]=[];$r=&$r[$p];}}
    private function dotUnset(array &$a,string $path):void{$parts=array_values(array_filter(explode('.',$path),static fn($p)=>$p!==''));if($parts===[])return;$r=&$a;foreach($parts as $i=>$p){if(!is_array($r)||!array_key_exists($p,$r))return;if($i===count($parts)-1){unset($r[$p]);return;}$r=&$r[$p];}}

    /** @param array<string,mixed> $migration */ private function applies(array $migration,string $from,string $to):bool{return SemVersion::satisfies($from,(string)($migration['from']??'*'))&&SemVersion::satisfies($to,(string)($migration['to']??'*'));}
    /** @return array<string,mixed> */ private function release(array $m):array{$r=is_array($m['release']??null)?$m['release']:[];return ['version'=>SemVersion::normalize((string)($r['version']??'1.0.0')),'migrations'=>array_values(array_filter((array)($r['migrations']??[]),'is_array'))];}
    /** @return array{0:string,1:?string,2:string}|null */ private function candidateLocator(string $id,string $project,string $source):?array{if($source!==''){try{[$v,$p,$sid]=$this->parseLocator($source);if($sid===$id&&$this->libraryStore->getInScope($id,$v,$p)!==[])return[$v,$p,$source];}catch(\Throwable){}}if($this->libraryStore->getInScope($id,'project',$project)!==[])return['project',$project,'project::'.$project.'::'.$id];if($this->libraryStore->getInScope($id,'system',null)!==[])return['system',null,'system::'.$id];return null;}
    /** @return array{0:string,1:?string,2:string} */ private function parseLocator(string $l):array{$p=explode('::',trim($l));if(($p[0]??'')==='system'&&count($p)===2)return['system',null,$p[1]];if(($p[0]??'')==='project'&&count($p)===3)return['project',$p[1],$p[2]];throw new \InvalidArgumentException('Ungültiger Modullocator: '.$l);}
    private function mapValue(string $value,string $module,array $maps):string{if($value==='')return '';$m=(array)($maps['modules'][$module]??[]);$g=(array)($maps['global']??[]);return (string)($m[$value]??$g[$value]??$value);}
    private function mapRecursive(mixed $v,string $module,array $refs,array $dfs):mixed{if(is_array($v)){foreach($v as $k=>$x)$v[$k]=$this->mapRecursive($x,$module,$refs,$dfs);return $v;}if(!is_string($v))return $v;$mapped=$this->mapValue($v,$module,$dfs);return $this->mapValue($mapped,$module,$refs);}
    /** @return array<string,mixed> */ private function stepRecord(string $module,string $from,string $to,array $m,string $status,string $message,array $details,array $warnings):array{return ['moduleId'=>$module,'fromVersion'=>$from,'toVersion'=>$to,'id'=>(string)$m['id'],'type'=>(string)$m['type'],'phase'=>(string)($m['phase']??'pre'),'order'=>(int)($m['order']??0),'destructive'=>!empty($m['destructive']),'description'=>(string)($m['description']??''),'dataForm'=>(string)($m['dataForm']??''),'payload'=>(array)($m['payload']??[]),'status'=>$status,'message'=>$message,'details'=>$details,'warnings'=>$warnings];}
    /** @return array<string,mixed> */ private function result(array $step,string $message,array $details=[]):array{return ['moduleId'=>$step['moduleId'],'toVersion'=>$step['toVersion'],'id'=>$step['id'],'type'=>$step['type'],'phase'=>$step['phase'],'ok'=>true,'message'=>$message,'details'=>$details,'executedAt'=>gmdate('c')];}
    private function historyPath(string $project):string{return $this->root.'/projects/'.$project.'/config/assistant/module-migrations.json';}
    private function wasApplied(string $project,string $module,string $version,string $id):bool{foreach((array)($this->history($project)['applied']??[]) as $a)if(is_array($a)&&(string)($a['moduleId']??'')===$module&&(string)($a['version']??'')===$version&&(string)($a['migrationId']??'')===$id)return true;return false;}
    private function recordApplied(string $project,string $module,string $version,string $id,array $step,array $result):void{$h=$this->history($project);$h['schema']='easyit.assistant.module-migrations.v1';$h['projectId']=$project;$h['updatedAt']=gmdate('c');$h['applied'][]=['moduleId'=>$module,'version'=>$version,'migrationId'=>$id,'type'=>$step['type'],'phase'=>$step['phase'],'destructive'=>!empty($step['destructive']),'appliedAt'=>gmdate('c'),'result'=>$result];$path=$this->historyPath($project);$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Migrationsregister kann nicht angelegt werden.');$j=json_encode($h,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false||@file_put_contents($path,$j."\n",LOCK_EX)===false)throw new \RuntimeException('Migrationsregister kann nicht geschrieben werden.');}
}
