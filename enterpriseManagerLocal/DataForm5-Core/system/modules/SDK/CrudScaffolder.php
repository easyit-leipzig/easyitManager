<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK;
final class CrudScaffolder
{
 public function __construct(private readonly string $enterpriseRoot){}
 public function create(string $module,string $entity):array
 {
  if(!preg_match('/^[a-z0-9][a-z0-9._-]*$/',$module))throw new \InvalidArgumentException('Ungültiger Modulname.');
  if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$entity))throw new \InvalidArgumentException('Ungültiger Entityname.');
  $dir=$this->enterpriseRoot.'/modules/'.$module;$mf=$dir.'/module.json';
  if(!is_file($mf))throw new \RuntimeException("Modul '{$module}' wurde nicht gefunden.");
  $m=json_decode((string)file_get_contents($mf),true);$entry=(string)($m['entry']??'');$pos=strrpos($entry,'\\');$ns=$pos===false?'':substr($entry,0,$pos);$plural=strtolower($entity).'s';
  $files=[
   "src/Models/{$entity}.php"=>"<?php\ndeclare(strict_types=1);\nnamespace {$ns}\\Models;\nfinal class {$entity}{public function __construct(public readonly array \$attributes=[]){ }public function id():mixed{return \$this->attributes['id']??null;}}\n",
   "src/Http/{$entity}Controller.php"=>"<?php\ndeclare(strict_types=1);\nnamespace {$ns}\\Http;\nuse DataForm5\\Http\\Core\\{Request,Response};\nfinal class {$entity}Controller{public function index(Request \$request):Response{return Response::page('<section><h1>{$entity} Verwaltung</h1></section>','{$entity} Verwaltung');}}\n",
   "resources/views/{$plural}.php"=>"<?php\ndeclare(strict_types=1);\n?><section><h1>{$entity} Verwaltung</h1></section>\n",
   "tests/{$entity}CrudTest.php"=>"<?php\ndeclare(strict_types=1);\necho 'CRUD_TEST_OK'.PHP_EOL;\n"];
  foreach($files as $rel=>$content)if(file_exists($dir.'/'.$rel))throw new \RuntimeException('Zieldatei existiert bereits: '.$dir.'/'.$rel);
  foreach($files as $rel=>$content){$target=$dir.'/'.$rel;if(!is_dir(dirname($target)))mkdir(dirname($target),0775,true);file_put_contents($target,$content);}
  $route=$module.'.'.$plural;$m['routes'][]=['name'=>$route,'methods'=>['GET','POST'],'controller'=>$ns.'\\Http\\'.$entity.'Controller@index','capability'=>$module.'.manage','title'=>$entity.' Verwaltung'];
  $m['ui']['navigation'][]=['label'=>$entity.' Verwaltung','href'=>'app/module.php?route='.rawurlencode($route),'capability'=>$module.'.manage','priority'=>110];
  file_put_contents($mf,json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
  return ['module'=>$module,'entity'=>$entity,'files'=>array_keys($files),'route'=>$route];
 }
}
