<?php
declare(strict_types=1);
require __DIR__.'/../DataForm5-Core/bootstrap/autoload.php';
use DataForm5\Modules\SDK\ModuleValidator; use DataForm5\Modules\Packages\ModulePackageBuilder;
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$dir=$argv[1]??'';$zip=$argv[2]??'';if($dir===''||$zip===''){fwrite(STDERR,"Verwendung: php tools/build-module-package.php <modulordner> <ziel.zip>\n");exit(2);}try{$sha=(new ModulePackageBuilder(new ModuleValidator()))->build($dir,$zip);echo "MODULE_PACKAGE_BUILT: {$zip}\nSHA256: {$sha}\n";}catch(Throwable $e){fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);}
