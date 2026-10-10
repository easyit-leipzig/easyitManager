<?php
declare(strict_types=1);
namespace DataForm5\Deployment\Core;
use DataForm5\Deployment\Contracts\ReleaseManagerInterface;
final class ReleaseManager implements ReleaseManagerInterface
{
    public function __construct(private string $basePath,private string $releasePath,private MaintenanceMode $maintenance){}
    public function createManifest(string $sourcePath,string $version,array $metadata=[]):array{$manifest=ReleaseManifest::create($sourcePath,$version,$metadata);if(!is_dir($this->releasePath))mkdir($this->releasePath,0775,true);$file=$this->releasePath.'/release-'.$version.'.json';file_put_contents($file,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,LOCK_EX);return $manifest;}
    public function verifyManifest(string $sourcePath,array $manifest):bool{return ReleaseManifest::verify($sourcePath,$manifest);}
    public function enterMaintenance(string $message='Wartungsarbeiten'):void{$this->maintenance->enable($message);}
    public function leaveMaintenance():void{$this->maintenance->disable();}
    public function isMaintenance():bool{return $this->maintenance->enabled();}
    public function maintenanceData():?array{return $this->maintenance->data();}
    public function currentVersion():string{$file=$this->basePath.'/VERSION';return is_file($file)?trim((string)file_get_contents($file)):'unknown';}
    public function updateAvailable(string $candidate):bool{return version_compare($candidate,$this->currentVersion(),'>');}
}
