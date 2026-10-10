<?php
declare(strict_types=1);
namespace DataForm5\Modules\Packages;
use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Modules\Core\ModuleManifest;
use DataForm5\Modules\SDK\ModuleValidator;
use RuntimeException;
use DataForm5\Modules\Versioning\VersionConstraint;
use DataForm5\Modules\Versioning\ModuleCompatibility;
use DataForm5\Modules\Migrations\ModuleMigrationManager;
use DataForm5\Modules\Lifecycle\ModuleLifecycleManager;
final class ModulePackageInstaller
{
    public function __construct(private readonly string $modulePath,private readonly string $tempPath,private readonly Filesystem $fs,private readonly ModuleValidator $validator,private readonly ModulePackageRegistry $registry,private readonly ModuleArchiveExtractor $extractor,private readonly ?ModuleInstallHistory $history=null,private readonly ?ModuleCompatibility $compatibility=null,private readonly ?ModuleMigrationManager $migrations=null,private readonly ?ModuleLifecycleManager $lifecycle=null) {}
    /** @return array<string,mixed> */
    public function installArchive(string $archive): array { return $this->withArchive($archive,fn(string $dir):array=>$this->installDirectory($dir)); }
    /** @return array<string,mixed> */
    public function updateArchive(string $archive): array { return $this->withArchive($archive,fn(string $dir):array=>$this->updateDirectory($dir)); }
    /** @return array<string,mixed> */
    public function installDirectory(string $source): array
    {
        [$manifest,$check]=$this->validated($source); if($this->registry->get($manifest->name)!==null || is_dir($this->target($manifest->name))) throw new RuntimeException("Modul '{$manifest->name}' ist bereits installiert.");
        $this->assertCompatibility($manifest); $target=$this->target($manifest->name); $this->fs->copy($source,$target);
        try {
            $this->lifecycle?->run($manifest->name,$target,'install',['version'=>$manifest->version]);
            $this->lifecycle?->run($manifest->name,$target,'beforeMigration',['operation'=>'install','version'=>$manifest->version]);
            $migrationResult=$this->migrations?->migrate($manifest->name,$target);
            $this->lifecycle?->run($manifest->name,$target,'afterMigration',['operation'=>'install','version'=>$manifest->version,'applied'=>$migrationResult['applied']??[]]);
            $this->lifecycle?->run($manifest->name,$target,'postInstall',['version'=>$manifest->version]);
        } catch(\Throwable $e) { if(is_dir($target)) $this->fs->delete($target); throw $e; }
        $record=['name'=>$manifest->name,'version'=>$manifest->version,'path'=>$target,'installed_at'=>date(DATE_ATOM),'source'=>'module-package']; $this->registry->put($manifest->name,$record); $this->history?->add('install',$manifest->name,'success',['version'=>$manifest->version,'migrations'=>$migrationResult['applied']??[]]); return ['action'=>'installed','module'=>$record,'validation'=>$check,'migrations'=>$migrationResult??null];
    }
    /** @return array<string,mixed> */
    public function updateDirectory(string $source): array
    {
        [$manifest,$check]=$this->validated($source); $old=$this->registry->get($manifest->name); if($old===null) throw new RuntimeException("Modul '{$manifest->name}' ist nicht als Paket installiert.");
        $from=(string)($old['version']??'0.0.0'); if(!version_compare($manifest->version,$from,'>')) throw new RuntimeException('Neue Modulversion muss größer als die installierte Version sein.');
        $this->assertCompatibility($manifest); $target=$this->target($manifest->name); $backup=$target.'.backup.'.bin2hex(random_bytes(4));
        if(is_dir($target)) $this->fs->move($target,$backup);
        try { $this->fs->copy($source,$target); $this->lifecycle?->run($manifest->name,$target,'update',['from'=>$from,'to'=>$manifest->version]); $this->lifecycle?->run($manifest->name,$target,'beforeMigration',['operation'=>'update','from'=>$from,'to'=>$manifest->version]); $migrationResult=$this->migrations?->migrate($manifest->name,$target); $this->lifecycle?->run($manifest->name,$target,'afterMigration',['operation'=>'update','from'=>$from,'to'=>$manifest->version,'applied'=>$migrationResult['applied']??[]]); $this->lifecycle?->run($manifest->name,$target,'postUpdate',['from'=>$from,'to'=>$manifest->version]); if(is_dir($backup)) $this->fs->delete($backup); }
        catch(\Throwable $e){ if(is_dir($target)) $this->fs->delete($target); if(is_dir($backup)) $this->fs->move($backup,$target); throw $e; }
        $record=$old; $record['version']=$manifest->version; $record['updated_at']=date(DATE_ATOM); $record['path']=$target; $this->registry->put($manifest->name,$record); $this->history?->add('update',$manifest->name,'success',['from'=>$from,'to'=>$manifest->version,'migrations'=>$migrationResult['applied']??[]]); return ['action'=>'updated','from'=>$from,'module'=>$record,'validation'=>$check,'migrations'=>$migrationResult??null];
    }
    public function uninstall(string $name): void
    {
        $old=$this->registry->get($name); if($old===null) throw new RuntimeException("Modul '{$name}' ist nicht als Paket installiert.");
        foreach($this->registry->all() as $other=>$record){ if($other===$name) continue; $manifestFile=(string)($record['path']??'').'/module.json'; if(!is_file($manifestFile)) continue; $m=ModuleManifest::fromFile($manifestFile); if(array_key_exists($name,$m->dependencies)) throw new RuntimeException("Modul '{$name}' wird noch von '{$other}' benötigt."); }
        $target=$this->target($name); $this->lifecycle?->run($name,$target,'uninstall',['version'=>(string)($old['version']??'')]); if(is_dir($target)) $this->fs->delete($target); $this->registry->remove($name); $this->history?->add('remove',$name,'success');
    }
    /** @return array<string,array<string,mixed>> */ public function installed(): array { return $this->registry->all(); }
    public function setEnabled(string $name, bool $enabled): void
    {
        $old=$this->registry->get($name); if($old===null) throw new RuntimeException("Modul '{$name}' ist nicht als Paket installiert.");
        $manifestFile=$this->target($name).'/module.json'; if(!is_file($manifestFile)) throw new RuntimeException('Modulmanifest fehlt.');
        $data=json_decode($this->fs->read($manifestFile),true); if(!is_array($data)) throw new RuntimeException('Modulmanifest ist ungültig.');
        $this->lifecycle?->run($name,$this->target($name),$enabled?'enable':'disable',['version'=>(string)($old['version']??'')]);
        $data['enabled']=$enabled;
        $this->fs->write($manifestFile,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
        $old['enabled']=$enabled; $old['status_changed_at']=date(DATE_ATOM); $this->registry->put($name,$old); $this->history?->add($enabled?'enable':'disable',$name,'success');
    }
    /** @return array{0:ModuleManifest,1:array{valid:bool,errors:list<string>,warnings:list<string>}} */
    private function validated(string $source): array { $check=$this->validator->validateDirectory($source); if(!$check['valid']) throw new RuntimeException('Modulvalidierung fehlgeschlagen: '.implode(' | ',$check['errors'])); return [ModuleManifest::fromFile(rtrim($source,'/\\').'/module.json'),$check]; }
    private function assertCompatibility(ModuleManifest $m): void { $installed=$this->registry->all(); if($this->compatibility!==null){$result=$this->compatibility->check($m,$installed); if(!$result['compatible']) throw new RuntimeException('Modul ist nicht kompatibel: '.implode(' | ',$result['problems'])); return;} foreach($m->dependencyConstraints as $name=>$constraint){ if(!isset($installed[$name])) throw new RuntimeException("Modulabhängigkeit fehlt: {$name}"); $version=(string)($installed[$name]['version']??'0.0.0'); if(!VersionConstraint::matches($version,$constraint)) throw new RuntimeException("Modulabhängigkeit {$name} erfüllt {$constraint} nicht (vorhanden {$version})."); } }
    private function target(string $name): string { return rtrim($this->modulePath,'/\\').'/'.$name; }
    /** @template T @param callable(string):T $callback @return T */
    private function withArchive(string $archive,callable $callback): mixed { $work=rtrim($this->tempPath,'/\\').'/module-'.bin2hex(random_bytes(8)); $this->fs->ensureDirectory($work); try { $root=$this->extractor->extract($archive,$work); return $callback($root); } finally { if(is_dir($work)) $this->fs->delete($work); } }
}
