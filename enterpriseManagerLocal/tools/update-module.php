<?php
declare(strict_types=1);
require __DIR__.'/../DataForm5-Core/bootstrap/autoload.php';
use DataForm5\Core\Filesystem\Filesystem; use DataForm5\Modules\SDK\ModuleValidator; use DataForm5\Modules\Packages\{ModuleArchiveExtractor,ModulePackageInstaller,ModulePackageRegistry,ModuleInstallHistory};
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$archive=$argv[1]??''; if($archive===''){fwrite(STDERR,"Verwendung: php tools/update-module.php <modul.zip>\n");exit(2);} $base=dirname(__DIR__); $fs=new Filesystem();
try{$registry=new ModulePackageRegistry($base.'/modules/.installed.json',$fs);$installer=new ModulePackageInstaller($base.'/modules',$base.'/DataForm5-Core/storage/framework/module-packages',$fs,new ModuleValidator(),$registry,new ModuleArchiveExtractor($fs),new ModuleInstallHistory($base.'/modules/.history.json',$fs));$r=$installer->updateArchive($archive);echo "MODULE_UPDATED: ".($r['module']['name']??'')." ".($r['module']['version']??'')."\n";}catch(Throwable $e){fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);}
