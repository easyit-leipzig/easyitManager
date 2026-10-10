<?php
declare(strict_types=1);
require __DIR__.'/../DataForm5-Core/bootstrap/autoload.php';
use DataForm5\Modules\SDK\ModuleScaffolder;
use DataForm5\Modules\SDK\ModuleValidator;
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$args=$argv; array_shift($args);
if(count($args)<2){fwrite(STDERR,"Verwendung: php tools/create-module.php <name> <namespace> [klasse] [beschreibung]\n");exit(2);}
[$name,$namespace]=$args; $class=$args[2]??'Module'; $description=$args[3]??'';
try{
 $root=dirname(__DIR__).'/modules';
 $result=(new ModuleScaffolder())->create($root,$name,$namespace,$class,$description);
 $check=(new ModuleValidator())->validateDirectory($result['directory']);
 echo "MODULE_CREATED: {$result['directory']}\n";
 foreach($check['warnings'] as $w) echo "WARNING: {$w}\n";
 if(!$check['valid']){foreach($check['errors'] as $e) fwrite(STDERR,"ERROR: {$e}\n");exit(1);} echo "MODULE_VALID\n";
}catch(Throwable $e){fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);}
