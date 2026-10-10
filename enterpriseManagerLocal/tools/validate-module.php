<?php
declare(strict_types=1);
require __DIR__.'/../DataForm5-Core/bootstrap/autoload.php';
use DataForm5\Modules\SDK\ModuleValidator;
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$dir=$argv[1]??''; if($dir===''){fwrite(STDERR,"Verwendung: php tools/validate-module.php <modulverzeichnis>\n");exit(2);}
$result=(new ModuleValidator())->validateDirectory($dir);
foreach($result['warnings'] as $w) echo "WARNING: {$w}\n";
foreach($result['errors'] as $e) fwrite(STDERR,"ERROR: {$e}\n");
echo $result['valid']?"MODULE_VALID\n":"MODULE_INVALID\n"; exit($result['valid']?0:1);
