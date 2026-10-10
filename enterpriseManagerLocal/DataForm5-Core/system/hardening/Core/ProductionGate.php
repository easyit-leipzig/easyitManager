<?php
declare(strict_types=1);
namespace DataForm5\Hardening\Core;
use DataForm5\Core\Config;
use DataForm5\Core\Support\Path;
use DataForm5\Hardening\Contracts\ProductionGateInterface;
use DataForm5\Hardening\Exceptions\ProductionGateException;
final class ProductionGate implements ProductionGateInterface
{
 public function __construct(private readonly Config $config,private readonly Path $paths){}
 public function inspect():array
 {
  $checks=[];$env=(string)$this->config->get('app.environment','development');$debug=(bool)$this->config->get('app.debug',false);$url=(string)$this->config->get('app.url','');
  $checks[]=$this->check('environment.production',$env==='production','APP_ENV muss production sein.',['actual'=>$env]);
  $checks[]=$this->check('debug.disabled',$debug===false,'APP_DEBUG muss deaktiviert sein.');
  $checks[]=$this->check('https.enabled',str_starts_with(strtolower($url),'https://'),'APP_URL muss HTTPS verwenden.',['actual'=>$url]);
  $checks[]=$this->check('display_errors.disabled',ini_get('display_errors')==='0'||ini_get('display_errors')==='','display_errors muss deaktiviert sein.',[],false);
  $secret=(string)$this->config->get('secrets.active_key_value',$this->config->get('secrets.key',''));
  $unsafe=$secret===''||str_contains(strtolower($secret),'development')||str_contains(strtolower($secret),'change-me');
  $checks[]=$this->check('secrets.production_key',!$unsafe,'Der Entwicklungs-/Platzhalterschlüssel muss ersetzt werden.');
  foreach(['storage','storage/logs','storage/framework'] as $dir){$path=$this->paths->base($dir);$checks[]=$this->check('writable.'.str_replace('/','.',$dir),is_dir($path)&&is_writable($path),$dir.' muss vorhanden und beschreibbar sein.');}
  $failed=array_values(array_filter($checks,fn(array $c)=>$c['required']&&!$c['passed']));$warnings=array_values(array_filter($checks,fn(array $c)=>!$c['required']&&!$c['passed']));
  return ['ready'=>$failed===[],'environment'=>$env,'checked_at'=>gmdate(DATE_ATOM),'checks'=>$checks,'failed'=>$failed,'warnings'=>$warnings];
 }
 public function assertReady():void{$report=$this->inspect();if(!$report['ready'])throw new ProductionGateException($report);}
 private function check(string $name,bool $passed,string $message,array $meta=[],bool $required=true):array{return ['name'=>$name,'passed'=>$passed,'required'=>$required,'message'=>$message,'meta'=>$meta];}
}
