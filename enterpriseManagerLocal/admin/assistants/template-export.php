<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/system/assistant/state/AssistantStateSanitizer.php';
require_once $root . '/system/assistant/template/DataFormTemplateStore.php';
require_once $root . '/system/assistant/template/DataFormTemplateLibraryService.php';

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (string)$_GET['project_id'] : null;
try {
    $store = new \EasyIT\Assistant\Template\DataFormTemplateStore($root);
    $library = new \EasyIT\Assistant\Template\DataFormTemplateLibraryService($store, $root);
    $json = $library->exportJson($id, $projectId);
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $id) ?: 'dataform-template';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.dataform-template.json"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Export nicht möglich: ' . $e->getMessage();
}
