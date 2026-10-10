<?php
declare(strict_types=1);
require __DIR__.'/../system/app/bootstrap.php';
use DataForm5\Modules\Migrations\{ModuleMigrationRepository,ModuleMigrationManager};
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$name=trim((string)($argv[1]??'')); if($name===''){fwrite(STDERR,"Verwendung: php tools/module-migration-rollback.php <modulname>\n");exit(2);}
$base=dirname(__DIR__);$path=$base.'/modules/'.$name;
try{$pdo=enterprise_pdo();enterprise_upgrade($pdo);$manager=new ModuleMigrationManager($pdo,new ModuleMigrationRepository($pdo));$r=$manager->rollbackLastBatch($name,$path);echo 'MODULE_MIGRATION_ROLLBACK: '.$name.' '.count($r)." Migration(en)\n";}catch(Throwable $e){fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);}
