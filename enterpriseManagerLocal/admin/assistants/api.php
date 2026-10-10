<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$assistantId = isset($_REQUEST['assistant']) ? (string) $_REQUEST['assistant'] : 'core.status';
$stepId = isset($_REQUEST['step']) ? (string) $_REQUEST['step'] : null;
$result = $assistantManager->run($assistantId, $context, $stepId);

http_response_code($result->isOk() ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
