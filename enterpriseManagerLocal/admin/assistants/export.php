<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/system/assistant/state/AssistantStateStore.php';
require_once $root . '/system/assistant/dataform/DataFormDraft.php';
require_once $root . '/system/assistant/dataform/DataFormDraftValidator.php';
require_once $root . '/system/assistant/dataform/DataFormConfigCompiler.php';
require_once $root . '/system/assistant/relation/RelationDraft.php';
require_once $root . '/system/assistant/relation/RelationDraftValidator.php';
require_once $root . '/system/assistant/relation/RelationConfigCompiler.php';
require_once $root . '/system/assistant/event/EventDraft.php';
require_once $root . '/system/assistant/event/EventDraftValidator.php';
require_once $root . '/system/assistant/event/EventConfigCompiler.php';
require_once $root . '/system/assistant/action/ActionDefinition.php';
require_once $root . '/system/assistant/action/DataFormActionRegistry.php';
require_once $root . '/system/assistant/action/ActionDraft.php';
require_once $root . '/system/assistant/action/ActionDraftValidator.php';
require_once $root . '/system/assistant/action/ActionConfigCompiler.php';
require_once $root . '/system/assistant/datasource/DataSourceDraft.php';
require_once $root . '/system/assistant/datasource/DataSourceConfigCompiler.php';
require_once $root . '/system/assistant/datasource/DataSourceAdapterInterface.php';
require_once $root . '/system/assistant/datasource/DataFormDatabaseInterface.php';
require_once $root . '/system/assistant/datasource/CoreDatabaseBridge.php';
require_once $root . '/system/assistant/datasource/ConnectionTestResult.php';
require_once $root . '/system/assistant/datasource/DataSourceRegistry.php';
require_once $root . '/system/assistant/datasource/DataSourceDraftValidator.php';
require_once $root . '/system/assistant/datasource/adapters/AbstractPdoAdapter.php';
require_once $root . '/system/assistant/datasource/adapters/MySqlDataSourceAdapter.php';
require_once $root . '/system/assistant/datasource/adapters/SQLiteDataSourceAdapter.php';
require_once $root . '/system/assistant/datasource/adapters/CsvDataSourceAdapter.php';
require_once $root . '/system/assistant/datasource/adapters/OracleDataSourceAdapter.php';
require_once $root . '/system/assistant/field/FieldTypeRegistry.php';
require_once $root . '/system/assistant/field/FieldDraft.php';
require_once $root . '/system/assistant/field/FieldDraftValidator.php';
require_once $root . '/system/assistant/field/FieldConfigCompiler.php';
require_once $root . '/system/assistant/relation/BoundValueResolver.php';
require_once $root . '/system/assistant/event/DataFormActionContextBuilder.php';
require_once $root . '/system/assistant/diagnostic/DiagnosticCheck.php';
require_once $root . '/system/assistant/diagnostic/DiagnosticReport.php';
require_once $root . '/system/assistant/diagnostic/DataFormDiagnosticService.php';
require_once $root . '/system/assistant/project/ProjectDraft.php';
require_once $root . '/system/assistant/project/ProjectDraftValidator.php';
require_once $root . '/system/assistant/project/ProjectConfigCompiler.php';
require_once $root . '/system/assistant/workflow/WorkflowDefinition.php';
require_once $root . '/system/assistant/workflow/WorkflowProgressService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$assistantId = isset($_GET['assistant']) ? (string) $_GET['assistant'] : 'dataform.create';
$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (string) $_GET['project_id'] : 'global';
$dataFormId = isset($_GET['dataform_id']) && $_GET['dataform_id'] !== '' ? (string) $_GET['dataform_id'] : 'relation';
$store = new \EasyIT\Assistant\State\AssistantStateStore();

if ($assistantId === 'workflow.standard') {
    $workflowState = $store->get('workflow.standard', 'active-standard-workflow');
    $dsRegistry = new \EasyIT\Assistant\DataSource\DataSourceRegistry([
        new \EasyIT\Assistant\DataSource\Adapters\MySqlDataSourceAdapter(),
        new \EasyIT\Assistant\DataSource\Adapters\SQLiteDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\CsvDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\OracleDataSourceAdapter(),
    ]);
    $actionRegistry = new \EasyIT\Assistant\Action\DataFormActionRegistry();
    $fieldTypes = new \EasyIT\Assistant\Field\FieldTypeRegistry();
    $diagnostics = new \EasyIT\Assistant\Diagnostic\DataFormDiagnosticService($store, $dsRegistry, $actionRegistry, $fieldTypes);
    $service = new \EasyIT\Assistant\Workflow\WorkflowProgressService($store, $root, $dsRegistry, $actionRegistry, $fieldTypes, $diagnostics);
    $effectiveProject = $projectId !== 'global' ? $projectId : (string) ($workflowState['projectId'] ?? '');
    $effectiveDataForm = $dataFormId !== 'relation' ? $dataFormId : (string) ($workflowState['dataFormId'] ?? '');
    $progress = $service->evaluate($workflowState, $effectiveProject, $effectiveDataForm);
    $validation = ['errors' => [], 'warnings' => []];
    $config = [
        'schema' => 'easyit.assistant.workflow.v1',
        'id' => 'standard',
        'projectId' => $progress['projectId'],
        'dataFormId' => $progress['dataFormId'],
        'completeCount' => $progress['completeCount'],
        'totalCount' => $progress['totalCount'],
        'percent' => $progress['percent'],
        'nextStep' => $progress['nextStep'],
        'allComplete' => $progress['allComplete'],
        'steps' => $progress['steps'],
        'optional' => $workflowState['optional'] ?? [],
        'startedAt' => $workflowState['startedAt'] ?? null,
        'updatedAt' => $workflowState['updatedAt'] ?? null,
    ];
    $baseName = ($progress['projectId'] !== '' ? $progress['projectId'] : 'easyit') . '-workflow';
    $suffix = '.workflow.json';
} elseif ($assistantId === 'project.recovery') {
    $scope = $projectId !== 'global' ? $projectId : 'recovery-global';
    $state = $store->get($assistantId, $scope);
    $validation = ['errors' => [], 'warnings' => []];
    if (is_array($state['diagnosticReport'] ?? null)) {
        $config = $state['diagnosticReport'];
    } elseif (is_array($state['result'] ?? null)) {
        $config = [
            'schema' => 'easyit.project.recovery-operation.v1',
            'mode' => (string) ($state['mode'] ?? ''),
            'result' => $state['result'],
        ];
    } else {
        http_response_code(422);
        exit('Noch kein Backup-/Restore-Ergebnis vorhanden.');
    }
    $baseName = (string) ($state['restoredProjectId'] ?? $state['projectId'] ?? 'project');
    $suffix = '.recovery.json';
} elseif ($assistantId === 'project.create') {
    $draft = new \EasyIT\Assistant\Project\ProjectDraft($store->get($assistantId, 'project-create'));
    $validation = (new \EasyIT\Assistant\Project\ProjectDraftValidator())->validate($draft, 'review');
    $config = (new \EasyIT\Assistant\Project\ProjectConfigCompiler())->compile($draft);
    $baseName = (string) ($config['project']['id'] ?? 'project');
    $suffix = '.project.json';
} elseif ($assistantId === 'datasource.configure') {
    $draft = new \EasyIT\Assistant\DataSource\DataSourceDraft($store->get($assistantId, $projectId));
    $registry = new \EasyIT\Assistant\DataSource\DataSourceRegistry([
        new \EasyIT\Assistant\DataSource\Adapters\MySqlDataSourceAdapter(),
        new \EasyIT\Assistant\DataSource\Adapters\SQLiteDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\CsvDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\OracleDataSourceAdapter(),
    ]);
    $validation = (new \EasyIT\Assistant\DataSource\DataSourceDraftValidator($registry))->validate($draft, null);
    $config = (new \EasyIT\Assistant\DataSource\DataSourceConfigCompiler())->compile($draft);
    $baseName = (string) ($config['profile'] ?? 'datasource');
    $suffix = '.datasource.json';
} elseif ($assistantId === 'dataform.create') {
    $draft = new \EasyIT\Assistant\DataForm\DataFormDraft($store->get($assistantId, $projectId));
    $validation = (new \EasyIT\Assistant\DataForm\DataFormDraftValidator())->validate($draft, null);
    $config = (new \EasyIT\Assistant\DataForm\DataFormConfigCompiler())->compile($draft);
    $baseName = (string) ($config['name'] ?? 'dataform');
    $suffix = '.dataform.json';
} elseif ($assistantId === 'dataform.fields') {
    $scope = $projectId . '|' . $dataFormId;
    $types = new \EasyIT\Assistant\Field\FieldTypeRegistry();
    $draft = new \EasyIT\Assistant\Field\FieldDraft($store->get($assistantId, $scope));
    $validation = (new \EasyIT\Assistant\Field\FieldDraftValidator($types))->validate($draft, null);
    $config = (new \EasyIT\Assistant\Field\FieldConfigCompiler($types))->compile($draft);
    $baseName = (string) ($config['dataForm'] ?? ($dataFormId !== 'relation' ? $dataFormId : 'dataform'));
    $suffix = '.fields.json';
} elseif ($assistantId === 'dataform.relations') {
    $scope = $projectId . '|' . $dataFormId;
    $draft = new \EasyIT\Assistant\Relation\RelationDraft($store->get($assistantId, $scope));
    $validation = (new \EasyIT\Assistant\Relation\RelationDraftValidator())->validate($draft, null);
    $config = (new \EasyIT\Assistant\Relation\RelationConfigCompiler())->compile($draft);
    $baseName = (string) ($config['name'] ?? 'relation');
    $suffix = '.relation.json';
} elseif ($assistantId === 'dataform.events') {
    $scope = $projectId . '|' . $dataFormId;
    $draft = new \EasyIT\Assistant\Event\EventDraft($store->get($assistantId, $scope));
    $validation = (new \EasyIT\Assistant\Event\EventDraftValidator())->validate($draft, null);
    $config = (new \EasyIT\Assistant\Event\EventConfigCompiler())->compile($draft);
    $baseName = $dataFormId !== 'relation' ? $dataFormId : 'dataform';
    $suffix = '.events.json';
} elseif ($assistantId === 'dataform.actions') {
    $scope = $projectId . '|' . $dataFormId;
    $registry = new \EasyIT\Assistant\Action\DataFormActionRegistry();
    $draft = new \EasyIT\Assistant\Action\ActionDraft($store->get($assistantId, $scope));
    $validation = (new \EasyIT\Assistant\Action\ActionDraftValidator($registry))->validate($draft, null);
    $config = (new \EasyIT\Assistant\Action\ActionConfigCompiler($registry))->compile($draft);
    $baseName = (string) ($config['dataForm'] ?? ($dataFormId !== 'relation' ? $dataFormId : 'dataform'));
    $suffix = '.actions.json';
 } elseif ($assistantId === 'dataform.module-migration-history') {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId) || !is_dir($root . '/projects/' . $projectId)) {
        http_response_code(422); exit('Projekt für Migrationshistorie wurde nicht gefunden.');
    }
    $runId = isset($_GET['run_id']) ? trim((string)$_GET['run_id']) : '';
    if ($runId !== '') {
        if (!preg_match('/^mig-[A-Za-z0-9._-]{8,80}$/', $runId)) { http_response_code(422); exit('Ungültige Migrations-Run-ID.'); }
        $file = $root . '/projects/' . $projectId . '/config/assistant/module-migration-runs/' . $runId . '.json';
        $baseName = $projectId . '-' . $runId; $suffix = '.migration-run.json';
    } else {
        $file = $root . '/projects/' . $projectId . '/config/assistant/module-migration-runs.json';
        $baseName = $projectId . '-migration-history'; $suffix = '.migration-history.json';
    }
    if (!is_file($file)) { http_response_code(404); exit('Migrationshistorie wurde nicht gefunden.'); }
    $decoded = json_decode((string)file_get_contents($file), true);
    if (!is_array($decoded)) { http_response_code(500); exit('Migrationshistorie ist ungültig.'); }
    $validation = ['errors' => [], 'warnings' => []]; $config = $decoded;
} elseif ($assistantId === 'dataform.diagnostics') {
    $diagState = $store->get($assistantId, $projectId);
    $effectiveDataForm = (string) ($diagState['dataForm'] ?? ($dataFormId !== 'relation' ? $dataFormId : ''));
    $dsRegistry = new \EasyIT\Assistant\DataSource\DataSourceRegistry([
        new \EasyIT\Assistant\DataSource\Adapters\MySqlDataSourceAdapter(),
        new \EasyIT\Assistant\DataSource\Adapters\SQLiteDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\CsvDataSourceAdapter($root),
        new \EasyIT\Assistant\DataSource\Adapters\OracleDataSourceAdapter(),
    ]);
    $actionRegistry = new \EasyIT\Assistant\Action\DataFormActionRegistry();
    $fieldTypes = new \EasyIT\Assistant\Field\FieldTypeRegistry();
    $service = new \EasyIT\Assistant\Diagnostic\DataFormDiagnosticService($store, $dsRegistry, $actionRegistry, $fieldTypes);
    $report = $service->diagnose($projectId, $effectiveDataForm, $diagState + ['runtimeDataSource' => false, 'sampleParentValue' => 42]);
    $validation = ['errors' => [], 'warnings' => []];
    $config = $report->jsonSerialize();
    $baseName = $effectiveDataForm !== '' ? $effectiveDataForm : 'dataform';
    $suffix = '.diagnostic.json';
} else {
    http_response_code(400);
    exit('Unsupported assistant export.');
}

if ($validation['errors'] !== []) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'errors' => $validation['errors']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $baseName) ?: 'assistant-config';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . $suffix . '"');
header('Cache-Control: no-store');
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
