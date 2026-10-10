<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK;
use DataForm5\Modules\Core\ModuleManifest;
use DataForm5\Modules\Versioning\VersionConstraint;
final class ModuleValidator
{
    /** @return array{valid:bool,errors:list<string>,warnings:list<string>} */
    public function validateDirectory(string $directory): array
    {
        $errors=[]; $warnings=[]; $directory=rtrim($directory,'/\\');
        $manifestFile=$directory.'/module.json';
        if(!is_file($manifestFile)) return ['valid'=>false,'errors'=>['module.json fehlt.'],'warnings'=>[]];
        try { $manifest=ModuleManifest::fromFile($manifestFile); }
        catch(\Throwable $e) { return ['valid'=>false,'errors'=>[$e->getMessage()],'warnings'=>[]]; }
        if(!preg_match('/^[a-z0-9][a-z0-9._-]*$/',$manifest->name)) $errors[]='Modulname muss aus Kleinbuchstaben, Zahlen, Punkt, Unterstrich oder Bindestrich bestehen.';
        if(!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$manifest->version)) $warnings[]='Version entspricht nicht dem empfohlenen SemVer-Schema.';
        if($manifest->coreVersion!=='*' && !preg_match('/^(?:[<>=~^]+\s*)?\d+(?:\.\d+){0,2}/',$manifest->coreVersion)) $warnings[]='core_version enthält keine erkennbare Versionsbedingung.';
        if(!is_file($directory.'/bootstrap.php')) $errors[]='bootstrap.php fehlt.';
        if(!is_dir($directory.'/src')) $errors[]='src/ fehlt.';
        if(!is_file($directory.'/README.md')) $warnings[]='README.md fehlt.';
        if(!is_dir($directory.'/tests')) $warnings[]='tests/ fehlt.';
        return ['valid'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings];
    }
}
