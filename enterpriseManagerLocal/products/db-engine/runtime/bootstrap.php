<?php
declare(strict_types=1);
const EASYIT_DB_ENGINE_ROOT=__DIR__;
foreach(['EnterpriseCsvPdo.php','EnterpriseSqlitePdo.php','EnterprisePgsqlPdo.php','EnterpriseOraclePdo.php','EnterpriseMssqlAdapter.php','EnterpriseMssqlPdo.php','LicenseGate.php','DbEngine.php'] as $f)require_once __DIR__.'/lib/'.$f;
function easyit_db_engine_config():array{$f=__DIR__.'/config.php';if(!is_file($f))throw new RuntimeException('DB_ENGINE_NOT_CONFIGURED');$c=require $f;if(!is_array($c))throw new RuntimeException('DB_ENGINE_CONFIG_INVALID');return $c;}
function easyit_db_engine_pdo(?string $expectedProjectId=null):PDO{static $pdo=null;if($pdo instanceof PDO)return $pdo;$c=easyit_db_engine_config();if($expectedProjectId!==null&&$expectedProjectId!==''&&(string)($c['project_id']??'')!==$expectedProjectId)throw new RuntimeException('DB_ENGINE_PROJECT_MISMATCH');EasyItDbEngineLicenseGate::assert(__DIR__,$c);return $pdo=EasyItDbEngine::connect($c);}
