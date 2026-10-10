<?php
declare(strict_types=1);namespace DataForm5\Modules\SDK\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Modules\SDK\ModuleQualityValidator;
final class ModuleValidateCommand extends AbstractCommand{
 public function __construct(private readonly ModuleQualityValidator $v){}public function name():string{return 'module:validate';}public function description():string{return 'SDK Quality Gate für ein Modul.';}public function arguments():array{return ['module'=>'Modul-Slug'];}public function options():array{return ['json'=>['description'=>'JSON','default'=>false,'requires_value'=>false]];}
 public function execute(Input $i,Output $o):int{$r=$this->v->validate((string)($this->argument($i,'module')??''));if((bool)$this->option($i,'json'))$o->line(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));else{foreach($r['errors'] as $x)$o->error($x);foreach($r['warnings'] as $x)$o->warning($x);if($r['valid'])$o->success('Modulvalidierung bestanden ('.$r['score'].'%).');}return $r['valid']?0:1;}
}
