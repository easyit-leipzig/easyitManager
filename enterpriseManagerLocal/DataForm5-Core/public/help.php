<?php
declare(strict_types=1);

use EasyIT\DataForm5\Help\HelpController;
use EasyIT\DataForm5\Help\HelpRegistry;
use EasyIT\DataForm5\Help\HelpService;

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/system/help/HelpRegistry.php';
require_once $projectRoot . '/system/help/HelpService.php';
require_once $projectRoot . '/system/help/HelpController.php';

$helpId = isset($_GET['id']) && is_string($_GET['id'])
    ? trim($_GET['id'])
    : 'dataformContext';

$registry = HelpRegistry::fromProjectRoot($projectRoot);
$service = new HelpService($projectRoot, $registry);
$controller = new HelpController($service);

$controller->document($helpId);
