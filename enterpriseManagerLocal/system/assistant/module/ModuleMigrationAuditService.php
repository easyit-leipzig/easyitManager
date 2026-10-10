<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

use EasyIT\Assistant\Recovery\ProjectArchiveService;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\State\AssistantStateSanitizer;

final class ModuleMigrationAuditService
{
    private string $root;
    private AssistantStateSanitizer $sanitizer;

    public function __construct(
        private AssistantStateStore $state,
        private ModuleInstallationRegistry $installations,
        private ProjectArchiveService $archives,
        string $root
    ) {
        $this->root = rtrim($root, '/\\');
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $checkpoint */
    public function beginRun(string $projectId, array $plan, array $checkpoint): string
    {
        $this->assertProject($projectId);
        $runId = 'mig-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $before = $this->captureSnapshot($projectId);
        $run = [
            'schema' => 'easyit.assistant.module-migration-run.v1',
            'runId' => $runId,
            'projectId' => $projectId,
            'status' => 'RUNNING',
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'plan' => $this->compactPlan($plan),
            'steps' => array_values((array)($plan['steps'] ?? [])),
            'checkpoint' => $this->compactCheckpoint($checkpoint),
            'beforeSnapshot' => $before,
            'afterSnapshot' => null,
            'schemaDiff' => ['count' => 0, 'changes' => [], 'summary' => []],
            'migrationResults' => [],
            'failedStep' => null,
            'error' => null,
            'rollback' => null,
        ];
        $this->writeRun($projectId, $runId, $run);
        $this->updateIndex($projectId, $run);
        return $runId;
    }

    /** @param list<array<string,mixed>> $results @param array<string,mixed>|null $failedStep */
    public function finishRun(string $projectId, string $runId, string $status, array $results = [], ?array $failedStep = null, ?string $error = null): array
    {
        $run = $this->getRun($projectId, $runId);
        if ($run === []) {
            throw new \RuntimeException('Migrationslauf wurde nicht gefunden: ' . $runId);
        }
        $after = $this->captureSnapshot($projectId);
        $run['status'] = $status;
        $run['finishedAt'] = gmdate('c');
        $run['afterSnapshot'] = $after;
        $run['schemaDiff'] = $this->diffSnapshots((array)$run['beforeSnapshot'], $after);
        $run['migrationResults'] = $results;
        $run['failedStep'] = $failedStep;
        $run['error'] = $error;
        $this->writeRun($projectId, $runId, $run);
        $this->updateIndex($projectId, $run);
        return $run;
    }

    /** @return list<array<string,mixed>> */
    public function listRuns(string $projectId): array
    {
        $this->assertProject($projectId);
        $path = $this->indexPath($projectId);
        if (!is_file($path)) return [];
        $doc = $this->readJson($path);
        $runs = array_values(array_filter((array)($doc['runs'] ?? []), 'is_array'));
        usort($runs, static fn(array $a, array $b): int => strcmp((string)($b['startedAt'] ?? ''), (string)($a['startedAt'] ?? '')));
        return $runs;
    }

    /** @return array<string,mixed> */
    public function getRun(string $projectId, string $runId): array
    {
        $this->assertProject($projectId);
        $this->assertRunId($runId);
        $path = $this->runPath($projectId, $runId);
        return is_file($path) ? $this->readJson($path) : [];
    }

    /** @return array<string,mixed> */
    public function captureSnapshot(string $projectId): array
    {
        $this->assertProject($projectId);
        $project = $this->root . '/projects/' . $projectId;
        $assistantStates = [];
        $stateDir = $project . '/config/assistant/state';
        foreach (glob($stateDir . '/*.json') ?: [] as $file) {
            $doc = $this->readJson($file);
            if (($doc['schema'] ?? '') !== 'easyit.assistant.state.v1') continue;
            $assistantId = (string)($doc['assistantId'] ?? '');
            if (!in_array($assistantId, ['dataform.create','dataform.fields','dataform.relations','dataform.events','dataform.actions','datasource.configure'], true)) continue;
            $scope = (string)($doc['scope'] ?? 'default');
            $assistantStates[$assistantId . '|' . $scope] = (array)($doc['state'] ?? []);
        }
        ksort($assistantStates);

        $csvSchemas = [];
        $dataDir = $project . '/data';
        if (is_dir($dataDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dataDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if (!$fi instanceof \SplFileInfo || !$fi->isFile() || strtolower($fi->getExtension()) !== 'csv') continue;
                $relative = str_replace('\\', '/', substr($fi->getPathname(), strlen($project) + 1));
                $header = $this->csvHeader($fi->getPathname());
                $csvSchemas[$relative] = ['columns' => $header, 'columnCount' => count($header)];
            }
            ksort($csvSchemas);
        }

        $sqliteSchemas = [];
        if (extension_loaded('pdo_sqlite') && is_dir($dataDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dataDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) {
                if (!$fi instanceof \SplFileInfo || !$fi->isFile() || !in_array(strtolower($fi->getExtension()), ['sqlite','db'], true)) continue;
                try {
                    $pdo = new \PDO('sqlite:' . $fi->getPathname(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                    $tables = [];
                    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") ?: [] as $row) {
                        $name = (string)$row['name']; $cols = [];
                        foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($name) . ')') ?: [] as $c) $cols[] = ['name'=>(string)$c['name'],'type'=>(string)$c['type'],'notnull'=>(int)$c['notnull'],'pk'=>(int)$c['pk']];
                        $tables[$name] = $cols;
                    }
                    $relative = str_replace('\\', '/', substr($fi->getPathname(), strlen($project) + 1));
                    $sqliteSchemas[$relative] = $tables;
                } catch (\Throwable) {}
            }
            ksort($sqliteSchemas);
        }

        return [
            'schema' => 'easyit.assistant.module-migration-snapshot.v1',
            'projectId' => $projectId,
            'capturedAt' => gmdate('c'),
            'assistantStates' => $this->sanitizer->sanitize($assistantStates),
            'csvSchemas' => $csvSchemas,
            'sqliteSchemas' => $sqliteSchemas,
            'externalDatabaseSchema' => $this->captureExternalDatabaseSchema($projectId),
            'moduleInstallations' => $this->sanitizer->sanitize($this->installations->document($projectId)),
            'projectConfig' => $this->sanitizer->sanitize($this->readJson($project . '/config/project.json')),
            'datasourceConfig' => $this->sanitizer->sanitize($this->readJson($project . '/config/datasource.json')),
        ];
    }

    /** @return array{count:int,changes:list<array<string,mixed>>,summary:array<string,int>} */
    public function diffSnapshots(array $before, array $after): array
    {
        $a = $this->flatten($this->withoutVolatile($before));
        $b = $this->flatten($this->withoutVolatile($after));
        $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b))));
        sort($keys, SORT_STRING);
        $changes = []; $summary = ['added'=>0,'removed'=>0,'changed'=>0,'dataform'=>0,'csv'=>0,'sqlite'=>0,'database'=>0,'modules'=>0,'config'=>0];
        foreach ($keys as $key) {
            $hasA = array_key_exists($key, $a); $hasB = array_key_exists($key, $b);
            if ($hasA && $hasB && $a[$key] === $b[$key]) continue;
            $type = !$hasA ? 'added' : (!$hasB ? 'removed' : 'changed');
            $category = str_starts_with($key, '$.assistantStates') ? 'dataform' : (str_starts_with($key, '$.csvSchemas') ? 'csv' : (str_starts_with($key, '$.sqliteSchemas') ? 'sqlite' : (str_starts_with($key, '$.externalDatabaseSchema') ? 'database' : (str_starts_with($key, '$.moduleInstallations') ? 'modules' : 'config'))));
            $summary[$type]++; $summary[$category]++;
            $changes[] = ['path'=>$key,'type'=>$type,'category'=>$category,'before'=>$hasA?$a[$key]:null,'after'=>$hasB?$b[$key]:null];
        }
        return ['count'=>count($changes),'changes'=>$changes,'summary'=>$summary];
    }

    /** @return array<string,mixed> */
    public function rollback(string $projectId, string $runId, string $confirmation): array
    {
        $this->assertProject($projectId);
        if (!hash_equals($projectId, trim($confirmation))) {
            return ['ok'=>false,'message'=>'Rollback nicht ausgeführt: Zur Bestätigung muss die Projekt-ID exakt eingegeben werden.'];
        }
        $run = $this->getRun($projectId, $runId);
        if ($run === []) return ['ok'=>false,'message'=>'Migrationslauf wurde nicht gefunden.'];
        $checkpoint = (array)($run['checkpoint'] ?? []);
        if(array_key_exists('rollbackAvailable',$checkpoint) && empty($checkpoint['rollbackAvailable'])) return ['ok'=>false,'message'=>(string)($checkpoint['rollbackNote']??'Rollback-Checkpoint ist für dieses Projekt nicht automatisch verwendbar.')];
        $archive = (string)($checkpoint['path'] ?? '');
        if ($archive === '' || !is_file($archive)) return ['ok'=>false,'message'=>'Recovery-Checkpoint ist nicht mehr verfügbar.'];
        foreach ((array)($run['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') === 'database.sql') {
                $inspection = $this->archives->inspect($archive);
                $mode = (string)($inspection['manifest']['database']['mode'] ?? 'unknown');
                if ($mode === 'external') return ['ok'=>false,'message'=>'Automatischer Rollback ist für externe SQL-Migrationen blockiert. Projektdateien können aus dem Checkpoint wiederhergestellt werden, der externe DB-Dump muss jedoch kontrolliert separat zurückgespielt werden.'];
            }
        }
        $safety = $this->archives->backup($projectId, true, false);
        if (!$safety->isOk()) return ['ok'=>false,'message'=>'Rollback wurde abgebrochen, weil das Sicherheitsbackup des aktuellen Zustands nicht erzeugt werden konnte: '.$safety->getMessage()];
        $restore = $this->archives->restoreInPlace($archive, $projectId);
        $result = $restore->jsonSerialize();
        if (!$restore->isOk()) return ['ok'=>false,'message'=>$restore->getMessage(),'safetyBackup'=>$safety->jsonSerialize(),'restore'=>$result];

        $afterRollback = $this->captureSnapshot($projectId);
        $verification = $this->diffSnapshots((array)($run['beforeSnapshot'] ?? []), $afterRollback);
        $run['rollback'] = [
            'status' => $verification['count'] === 0 ? 'PASS' : 'PASS_WITH_DIFF',
            'rolledBackAt' => gmdate('c'),
            'safetyBackup' => $safety->jsonSerialize(),
            'restore' => $result,
            'verificationDiff' => $verification,
        ];
        $run['status'] = 'ROLLED_BACK';
        $this->writeRun($projectId, $runId, $run);
        $this->updateIndex($projectId, $run);
        return ['ok'=>true,'message'=>'Projekt wurde auf den Recovery-Checkpoint des Migrationslaufs zurückgesetzt.','run'=>$run,'safetyBackup'=>$safety->jsonSerialize(),'restore'=>$result,'verificationDiff'=>$verification];
    }

    /** @return array<string,mixed> */
    private function compactPlan(array $plan): array
    {
        return ['verdict'=>$plan['verdict']??null,'migrationCount'=>$plan['migrationCount']??0,'destructiveCount'=>$plan['destructiveCount']??0,'selected'=>$plan['updatePlan']['selected']??[],'targetVersions'=>$plan['updatePlan']['compatibility']['versions']??[],'preparedAt'=>$plan['preparedAt']??null];
    }
    /** @return array<string,mixed> */
    private function compactCheckpoint(array $checkpoint): array
    {
        return ['ok'=>$checkpoint['ok']??false,'path'=>$checkpoint['path']??null,'fileName'=>$checkpoint['fileName']??null,'sha256'=>$checkpoint['sha256']??null,'rollbackAvailable'=>true,'details'=>$this->sanitizer->sanitize((array)($checkpoint['details']??[]))];
    }
    /** @return array<string,mixed> */
    private function withoutVolatile(array $snapshot): array { unset($snapshot['capturedAt']); if(isset($snapshot['moduleInstallations']['updatedAt'])) unset($snapshot['moduleInstallations']['updatedAt']); return $snapshot; }
    /** @return array<string,mixed> */
    private function flatten(mixed $value, string $path = '$'): array
    {
        if (!is_array($value)) return [$path=>$value];
        if ($value === []) return [$path=>[]];
        $out=[];
        foreach ($value as $k=>$v) { foreach ($this->flatten($v, $path.'.'.str_replace(['.','[',']'],['_','_','_'],(string)$k)) as $p=>$x) $out[$p]=$x; }
        return $out;
    }
    /** @return array<string,mixed> */
    private function captureExternalDatabaseSchema(string $projectId): array
    {
        $ds=$this->state->getForProject('datasource.configure',$projectId,$projectId);
        $driver=strtolower((string)($ds['profile']['driver']??$ds['driver']??''));
        if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';
        if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true))return ['status'=>'NOT_EXTERNAL','driver'=>$driver,'tables'=>[]];
        $pdoDriver=$driver==='oracle'?'oci':($driver==='mssql'?'sqlsrv':$driver);
        if(!class_exists(\PDO::class)||!in_array($pdoDriver,\PDO::getAvailableDrivers(),true))return ['status'=>'SKIP','driver'=>$driver,'message'=>'PDO-Treiber ist für externen Schema-Snapshot nicht verfügbar.','tables'=>[]];
        $c=(array)($ds['connection']??[]);$password='';$ref=trim((string)($c['passwordRef']??''));if($ref!==''){$v=getenv($ref);if($v!==false)$password=(string)$v;}
        try{
            $opt=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false];
            if($driver==='mysql'){
                $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??3306),(string)($c['database']??''),(string)($c['charset']??'utf8mb4'));
                $pdo=new \PDO($dsn,(string)($c['username']??''),$password,$opt);
                $rows=$pdo->query("SELECT table_name,column_name,column_type,is_nullable,column_key,ordinal_position FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position")->fetchAll();
            }elseif($driver==='pgsql'){
                $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??5432),(string)($c['database']??''));
                $pdo=new \PDO($dsn,(string)($c['username']??''),$password,$opt);$pdo->exec("SET client_encoding TO 'UTF8'");$schema=trim((string)($c['schema']??'public'))?:'public';if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1)throw new \RuntimeException('Ungültiger PostgreSQL-Schemaname.');$pdo->exec('SET search_path TO "'.$schema.'"');
                $rows=$pdo->query("SELECT c.table_name,c.column_name,CASE WHEN c.data_type='character varying' THEN 'varchar('||c.character_maximum_length||')' WHEN c.data_type='numeric' THEN 'numeric('||c.numeric_precision||','||c.numeric_scale||')' ELSE c.data_type END AS column_type,c.is_nullable,CASE WHEN EXISTS (SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema WHERE tc.table_schema=c.table_schema AND tc.table_name=c.table_name AND tc.constraint_type='PRIMARY KEY' AND kcu.column_name=c.column_name) THEN 'PRI' ELSE '' END AS column_key,c.ordinal_position FROM information_schema.columns c WHERE c.table_schema=current_schema() ORDER BY c.table_name,c.ordinal_position")->fetchAll();
            }elseif($driver==='oracle'){
                $dsn=sprintf('oci:dbname=//%s:%d/%s;charset=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??1521),(string)($c['service']??''),(string)($c['charset']??'AL32UTF8'));
                $pdo=new \PDO($dsn,(string)($c['username']??''),$password,$opt);
                $rows=$pdo->query("SELECT table_name,column_name,data_type,nullable,column_id FROM user_tab_columns ORDER BY table_name,column_id")->fetchAll();
            }else{
                $dsn=sprintf('sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s',(string)($c['host']??'127.0.0.1'),(int)($c['port']??1433),(string)($c['database']??''),!empty($c['encrypt'])?'true':'false',!empty($c['trustServerCertificate']??false)?'true':'false');
                $pdo=new \PDO($dsn,(string)($c['username']??''),$password,$opt);
                $rows=$pdo->query("SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS column_type,c.IS_NULLABLE AS is_nullable,CASE WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA=kcu.TABLE_SCHEMA WHERE tc.CONSTRAINT_TYPE='PRIMARY KEY' AND tc.TABLE_SCHEMA=c.TABLE_SCHEMA AND tc.TABLE_NAME=c.TABLE_NAME AND kcu.COLUMN_NAME=c.COLUMN_NAME) THEN 'PRI' ELSE '' END AS column_key,c.ORDINAL_POSITION AS ordinal_position FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA='dbo' ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION")->fetchAll();
            }
            $tables=[];foreach($rows as $r){$t=(string)($r['table_name']??$r['TABLE_NAME']??'');$col=(string)($r['column_name']??$r['COLUMN_NAME']??'');if($t===''||$col==='')continue;$tables[$t][]=$this->sanitizer->sanitize($r);}ksort($tables);return ['status'=>'PASS','driver'=>$driver,'tables'=>$tables];
        }catch(\Throwable $e){return ['status'=>'SKIP','driver'=>$driver,'message'=>'Externer Schema-Snapshot konnte nicht gelesen werden: '.$e->getMessage(),'tables'=>[]];}
    }

    /** @return list<string> */
    private function csvHeader(string $file): array
    {
        $line = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? '';
        if ($line === '') return [];
        $delimiter = str_contains($line, '|') ? '|' : (str_contains($line, ';') ? ';' : ',');
        return array_map('strval', str_getcsv($line, $delimiter));
    }
    private function indexPath(string $projectId): string { return $this->root.'/projects/'.$projectId.'/config/assistant/module-migration-runs.json'; }
    private function runPath(string $projectId,string $runId): string { return $this->root.'/projects/'.$projectId.'/config/assistant/module-migration-runs/'.$runId.'.json'; }
    private function assertProject(string $projectId): void { if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$projectId)||!is_dir($this->root.'/projects/'.$projectId)) throw new \InvalidArgumentException('Projekt wurde nicht gefunden: '.$projectId); }
    private function assertRunId(string $runId): void { if(!preg_match('/^mig-[A-Za-z0-9._-]{8,80}$/',$runId)) throw new \InvalidArgumentException('Ungültige Migrations-Run-ID.'); }
    /** @param array<string,mixed> $run */
    private function writeRun(string $projectId,string $runId,array $run): void { $path=$this->runPath($projectId,$runId);$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Migrationslauf-Verzeichnis kann nicht angelegt werden.');$this->writeJson($path,$run); }
    /** @param array<string,mixed> $run */
    private function updateIndex(string $projectId,array $run): void
    {
        $path=$this->indexPath($projectId);$doc=is_file($path)?$this->readJson($path):['schema'=>'easyit.assistant.module-migration-runs.v1','projectId'=>$projectId,'runs'=>[]];$doc['schema']='easyit.assistant.module-migration-runs.v1';$doc['projectId']=$projectId;$doc['updatedAt']=gmdate('c');$summary=['runId'=>$run['runId'],'status'=>$run['status'],'startedAt'=>$run['startedAt'],'finishedAt'=>$run['finishedAt'],'migrationCount'=>$run['plan']['migrationCount']??0,'destructiveCount'=>$run['plan']['destructiveCount']??0,'diffCount'=>$run['schemaDiff']['count']??0,'failedStep'=>$run['failedStep'],'error'=>$run['error'],'rollbackStatus'=>$run['rollback']['status']??null];$runs=(array)($doc['runs']??[]);$found=false;foreach($runs as $i=>$existing){if(is_array($existing)&&($existing['runId']??'')===$run['runId']){$runs[$i]=$summary;$found=true;break;}}if(!$found)$runs[]=$summary;$doc['runs']=array_values($runs);$this->writeJson($path,$doc);
    }
    /** @return array<string,mixed> */ private function readJson(string $path):array{$raw=@file_get_contents($path);$d=is_string($raw)?json_decode($raw,true):null;return is_array($d)?$d:[];}
    /** @param array<string,mixed> $doc */ private function writeJson(string $path,array $doc):void{$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis kann nicht angelegt werden: '.$dir);$json=json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new \RuntimeException('JSON kann nicht serialisiert werden.');$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('JSON kann nicht atomar geschrieben werden: '.$path);}}
}
