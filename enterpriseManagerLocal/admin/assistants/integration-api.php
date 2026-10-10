<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$surface = \EasyIT\Assistant\Integration\AssistantSurfaceResolver::resolve($context->getRoute(), isset($_REQUEST['surface']) ? (string) $_REQUEST['surface'] : null);
$integration = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
if (!$integration->hasSurface($surface)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannte Oberfläche.', 'surface' => $surface], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
echo json_encode([
    'ok' => true,
    'surface' => $surface,
    'surfaceTitle' => $integration->getSurfaceTitle($surface),
    'context' => [
        'projectId' => $context->getProjectId(),
        'dataFormId' => $context->getDataFormId(),
        'recordId' => $context->getRecordId(),
    ],
    'assistants' => $integration->entries($surface, $context),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
