<?php
declare(strict_types=1);
require __DIR__.'/../DataForm5-Core/bootstrap/autoload.php';
use DataForm5\Core\Filesystem\Filesystem; use DataForm5\Modules\SDK\ModuleValidator; use DataForm5\Modules\Packages\{ModuleArchiveExtractor,ModulePackageInstaller,ModulePackageRegistry,ModuleInstallHistory};
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$name=$argv[1]??''; if($name===''){fwrite(STDERR,"Verwendung: php tools/remove-module.php <modulname>\n");exit(2);} $base=dirname(__DIR__);$fs=new Filesystem();
try{$registry=new ModulePackageRegistry($base.'/modules/.installed.json',$fs);$installer=new ModulePackageInstaller($base.'/modules',$base.'/DataForm5-Core/storage/framework/module-packages',$fs,new ModuleValidator(),$registry,new ModuleArchiveExtractor($fs),new ModuleInstallHistory($base.'/modules/.history.json',$fs));$installer->uninstall($name);echo "MODULE_REMOVED: {$name}\n";}catch(Throwable $e){fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);}
