<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK;
use DataForm5\Modules\Core\ModuleManifest;use DataForm5\Modules\Contracts\ModuleInterface;
final class ModuleQualityValidator{
 public function __construct(private readonly string $root){}
 public function validate(string $module):array{
  $e=[];$w=[];$c=[];$check=function(bool $ok,string $key,string $msg)use(&$e,&$c){$c[$key]=$ok?'pass':'fail';if(!$ok)$e[]=$msg;};
  if(!preg_match('/^[a-z0-9][a-z0-9._-]*$/',$module))return ['module'=>$module,'valid'=>false,'errors'=>['Ungültiger Modulname.'],'warnings'=>[],'checks'=>[],'score'=>0];
  $d=$this->root.'/modules/'.$module;$mf=$d.'/module.json';$check(is_dir($d),'structure.module_dir','Modulverzeichnis fehlt.');$check(is_file($mf),'manifest.exists','module.json fehlt.');
  if($e)return $this->result($module,$e,$w,$c);
  try{$m=ModuleManifest::fromFile($mf);$c['manifest.parse']='pass';}catch(\Throwable $x){$e[]=$x->getMessage();$c['manifest.parse']='fail';return $this->result($module,$e,$w,$c);}
  $check($m->name===$module,'manifest.name','Manifestname stimmt nicht mit Modulverzeichnis überein.');$check((bool)preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/',$m->version),'manifest.version','Version ist nicht SemVer-kompatibel.');
  $boot=$d.'/bootstrap.php';$check(is_file($boot),'bootstrap.exists','bootstrap.php fehlt.');if(is_file($boot)){try{require_once $boot;$c['bootstrap.load']='pass';}catch(\Throwable $x){$c['bootstrap.load']='fail';$e[]='Bootstrap: '.$x->getMessage();}}
  $check(class_exists($m->entry),'entry.exists',"Entry-Klasse '{$m->entry}' fehlt.");if(class_exists($m->entry))$check(is_subclass_of($m->entry,ModuleInterface::class),'entry.interface','Entry-Klasse implementiert ModuleInterface nicht.');
  foreach($m->capabilities as $i=>$cap)$check((bool)preg_match('/^[a-z0-9._-]+\.[a-z0-9._-]+$/',$cap),"capability.$i","Ungültige Capability '$cap'.");
  $names=[];foreach(array_merge($m->routes,$m->apiRoutes) as $i=>$r){$name=is_array($r)?(string)($r['name']??''):'';$check($name!=='',"route.$i.name",'Route ohne Namen.');$check($name===''||!isset($names[$name]),"route.$i.unique","Doppelte Route '$name'.");if($name!=='')$names[$name]=1;$cap=is_array($r)?(string)($r['capability']??''):'';$check($cap===''||in_array($cap,$m->capabilities,true),"route.$i.capability","Route '$name' referenziert unbekannte Capability '$cap'.");}
  foreach($m->lifecycle as $hook=>$class)$check(is_string($class)&&$class!==''&&class_exists($class),"lifecycle.$hook","Lifecycle-Klasse '$class' fehlt.");
  foreach(['README.md','tests/smoke.php','config/schema.php'] as $f)if(!is_file($d.'/'.$f))$w[]="Empfohlene Datei fehlt: $f";
  return $this->result($module,$e,$w,$c);
 }
 private function result(string $m,array $e,array $w,array $c):array{$pass=count(array_filter($c,fn($v)=>$v==='pass'));return ['module'=>$m,'valid'=>$e===[],'errors'=>$e,'warnings'=>$w,'checks'=>$c,'score'=>$c===[]?0:(int)round(100*$pass/count($c))];}
}
