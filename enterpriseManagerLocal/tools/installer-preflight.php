<?php
declare(strict_types=1);
$kernel=require dirname(__DIR__).'/DataForm5-Core/bootstrap/app.php';
$installer=$kernel->container()->get(\DataForm5\Installer\Core\EnterpriseInstaller::class);
$status=$installer->status();
echo json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(($status['inspection']['enterprise_ready']??false)?0:1);
