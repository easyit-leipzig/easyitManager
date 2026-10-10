<?php
declare(strict_types=1);
namespace DataForm5\Modules\Versioning;
use DataForm5\Modules\Core\ModuleManifest;
final class ModuleCompatibility
{
    public function __construct(private readonly string $coreVersion) {}
    public function coreVersion(): string { return VersionConstraint::normalize($this->coreVersion); }
    /** @param array<string,array<string,mixed>> $installed @return array{compatible:bool,core_compatible:bool,dependency_compatible:bool,problems:list<string>} */
    public function check(ModuleManifest $manifest,array $installed=[]): array
    {
        $problems=[]; $core=VersionConstraint::matches($this->coreVersion(),$manifest->coreVersion);
        if(!$core)$problems[]="Benötigt Core {$manifest->coreVersion}; vorhanden {$this->coreVersion()}.";
        $deps=true;
        foreach($manifest->dependencyConstraints as $name=>$constraint){
            if(!isset($installed[$name])){$deps=false;$problems[]="Abhängigkeit {$name} fehlt.";continue;}
            $v=(string)($installed[$name]['version']??'0.0.0');
            if(!VersionConstraint::matches($v,$constraint)){$deps=false;$problems[]="Abhängigkeit {$name} {$constraint} nicht erfüllt (vorhanden {$v}).";}
        }
        return ['compatible'=>$core&&$deps,'core_compatible'=>$core,'dependency_compatible'=>$deps,'problems'=>$problems];
    }
}
