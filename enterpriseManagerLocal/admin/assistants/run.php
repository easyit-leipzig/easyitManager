<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$assistantId = isset($_REQUEST['assistant']) ? (string) $_REQUEST['assistant'] : 'core.status';
$stepId = isset($_REQUEST['step']) && $_REQUEST['step'] !== '' ? (string) $_REQUEST['step'] : null;
$result = $assistantManager->run($assistantId, $context, $stepId);
$assistant = $assistantManager->getRegistry()->has($assistantId) ? $assistantManager->getRegistry()->get($assistantId) : null;

if ($assistantId !== 'workflow.standard'
    && (string) $context->input('workflow', '') === 'standard'
    && $context->getProjectId()
    && $result->getCurrentStepId() !== '') {
    $workflowStateStore = new \EasyIT\Assistant\State\AssistantStateStore('easyit_assistant', $root);
    $workflowPersistence = new \EasyIT\Assistant\Workflow\WorkflowPersistenceService($workflowStateStore, $root);
    $workflowPersistence->recordAssistantStep(
        (string) $context->getProjectId(),
        (string) ($context->getDataFormId() ?? ''),
        $assistantId,
        (string) $result->getCurrentStepId()
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $result->isOk() && (string) ($_POST['_assistant_action'] ?? '') === 'continue') {
    $next = $result->getData()['nextStep'] ?? null;
    if (is_string($next) && $next !== '') {
        $query = ['assistant' => $assistantId, 'step' => $next];
        if ($context->getProjectId()) {
            $query['project_id'] = $context->getProjectId();
        }
        if ($context->getDataFormId()) {
            $query['dataform_id'] = $context->getDataFormId();
        }
        if ($context->getRecordId()) {
            $query['record_id'] = $context->getRecordId();
        }
        if ((string) $context->input('surface', '') !== '') {
            $query['surface'] = (string) $context->input('surface');
        }
        if ((string) $context->input('workflow', '') !== '') {
            $query['workflow'] = (string) $context->input('workflow');
        }
        header('Location: run.php?' . http_build_query($query));
        exit;
    }
}

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function checked(mixed $value): string { return !empty($value) ? ' checked' : ''; }

$currentStep = null;
foreach ($result->getSteps() as $candidate) {
    if ($candidate->getId() === $result->getCurrentStepId()) {
        $currentStep = $candidate;
        break;
    }
}
$data = $result->getData();
$formValues = is_array($data['formValues'] ?? null) ? $data['formValues'] : [];
$fields = $currentStep && is_array($currentStep->getPayload()['fields'] ?? null) ? $currentStep->getPayload()['fields'] : [];
$hasFileField = false;
foreach ($fields as $candidateField) {
    if (($candidateField['type'] ?? '') === 'file') { $hasFileField = true; break; }
}
$projectQuery = $context->getProjectId() ? '&project_id=' . rawurlencode($context->getProjectId()) : '';
$dataFormQuery = $context->getDataFormId() ? '&dataform_id=' . rawurlencode($context->getDataFormId()) : '';
$recordQuery = $context->getRecordId() ? '&record_id=' . rawurlencode($context->getRecordId()) : '';
$surfaceQuery = (string) $context->input('surface', '') !== '' ? '&surface=' . rawurlencode((string) $context->input('surface')) : '';
$workflowQuery = (string) $context->input('workflow', '') !== '' ? '&workflow=' . rawurlencode((string) $context->input('workflow')) : '';
$contextQuery = $projectQuery . $dataFormQuery . $recordQuery . $surfaceQuery . $workflowQuery;
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>easyIT Enterprise – <?= e($assistant?->getTitle() ?? 'Assistent') ?></title>
    <link rel="stylesheet" href="../../assets/css/assistant.css">
</head>
<body>
<main class="eit-assistant-page" data-assistant-id="<?= e($assistantId) ?>">
    <?php
    $integrationRegistry31 = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
    $pageNavigation31 = new \EasyIT\Assistant\Integration\AssistantPageNavigationRenderer(
        $integrationRegistry31->getCatalog(),
        new \EasyIT\Assistant\Integration\AssistantUiActionRegistry()
    );
    echo $pageNavigation31->render($context, $assistantId, true, true);
    ?>
    <header class="eit-assistant-header">
        <h1><?= e($assistant?->getTitle() ?? $assistantId) ?></h1>
        <?php if ($assistant): ?><p><?= e($assistant->getDescription()) ?></p><?php endif; ?>
    </header>

    <ol class="eit-assistant-steps">
        <?php foreach ($result->getSteps() as $step): ?>
            <li data-state="<?= e($step->getState()) ?>">
                <strong><?= e($step->getTitle()) ?></strong>
                <span><?= e($step->getDescription()) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if (!$result->isOk()): ?>
        <section class="eit-assistant-message" role="alert">
            <h2>Eingabe prüfen</h2>
            <?php foreach ($result->getErrors() as $error): ?><p><?= e((string) $error) ?></p><?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($result->getWarnings() !== []): ?>
        <section class="eit-assistant-message" aria-label="Hinweise">
            <h2>Hinweise</h2>
            <?php foreach ($result->getWarnings() as $warning): ?><p><?= e((string) $warning) ?></p><?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'datasource.configure' && !empty($data['connectionTest']['testedAt'])): ?>
        <section class="eit-assistant-message" aria-label="Verbindungstest">
            <h2>Verbindungstest</h2>
            <p><strong><?= !empty($data['connectionTest']['ok']) ? 'Erfolgreich' : 'Fehlgeschlagen' ?>:</strong> <?= e((string) ($data['connectionTest']['message'] ?? '')) ?></p>
            <?php if (!empty($data['connectionTest']['details'])): ?><pre><?= e((string) json_encode($data['connectionTest']['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (isset($data['discoveredSources']) && is_array($data['discoveredSources'])): ?><p>Erkannte Quellen: <?= count($data['discoveredSources']) ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'workflow.standard' && !empty($data['workflow'])): ?>
        <?php $workflow = (array) $data['workflow']; $wfContextQuery = (!empty($workflow['projectId']) ? '&project_id=' . rawurlencode((string) $workflow['projectId']) : '') . (!empty($workflow['dataFormId']) ? '&dataform_id=' . rawurlencode((string) $workflow['dataFormId']) : ''); ?>
        <section class="eit-assistant-work eit-workflow-dashboard" aria-labelledby="workflow-dashboard-title">
            <h2 id="workflow-dashboard-title">Workflow-Fortschritt</h2>
            <p><strong><?= (int) ($workflow['completeCount'] ?? 0) ?> von <?= (int) ($workflow['totalCount'] ?? 0) ?> Schritten abgeschlossen</strong> – <?= (int) ($workflow['percent'] ?? 0) ?> %</p>
            <progress value="<?= (int) ($workflow['percent'] ?? 0) ?>" max="100"><?= (int) ($workflow['percent'] ?? 0) ?> %</progress>
            <dl class="eit-workflow-context">
                <div><dt>Projekt</dt><dd><?= e((string) (($workflow['projectId'] ?? '') !== '' ? $workflow['projectId'] : '–')) ?></dd></div>
                <div><dt>DataForm</dt><dd><?= e((string) (($workflow['dataFormId'] ?? '') !== '' ? $workflow['dataFormId'] : '–')) ?></dd></div>
            </dl>
            <?php $persistence = (array) ($workflow['persistence'] ?? []); ?>
            <?php if (!empty($persistence['enabled'])): ?>
                <aside class="eit-assistant-message eit-workflow-persistence" aria-label="Persistenter Workflow-Zustand">
                    <p><strong>Projektpersistenz aktiv.</strong> Der Assistenten- und Workflow-Zustand wird zusätzlich projektbezogen gespeichert und kann in einer neuen Sitzung wieder aufgenommen werden.</p>
                    <?php if (!empty($persistence['storage'])): ?><p>Speicherort: <code><?= e((string) $persistence['storage']) ?></code></p><?php endif; ?>
                </aside>
            <?php endif; ?>
            <form method="post" action="run.php?assistant=workflow.standard<?= e($wfContextQuery) ?>" class="eit-workflow-context-form">
                <label>Vorhandenes Projekt verwenden <input type="text" name="workflow_project_id" value="<?= e((string) ($workflow['projectId'] ?? '')) ?>"></label>
                <label>Vorhandenes DataForm verwenden <input type="text" name="workflow_dataform_id" value="<?= e((string) ($workflow['dataFormId'] ?? '')) ?>"></label>
                <button type="submit" name="workflow_action" value="update_context">Kontext übernehmen</button>
            </form>
            <div class="eit-workflow-list">
                <?php foreach (($workflow['steps'] ?? []) as $workflowStep): ?>
                    <?php $ws = (array) $workflowStep; $status = (string) ($ws['status'] ?? 'pending'); ?>
                    <article class="eit-workflow-step" data-status="<?= e($status) ?>">
                        <h3><?= e((string) ($ws['title'] ?? $ws['id'] ?? 'Schritt')) ?> <span class="eit-workflow-status"><?= e(strtoupper($status)) ?></span></h3>
                        <p><?= e((string) ($ws['message'] ?? '')) ?></p>
                        <?php if (!empty($ws['details'])): ?><details><summary>Details</summary><pre><?= e((string) json_encode($ws['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                        <?php if (!empty($ws['available']) && $status !== 'complete'): ?><p><a class="eit-assistant-link" href="<?= e((string) ($ws['url'] ?? '#')) ?>">Diesen Schritt bearbeiten</a></p><?php endif; ?>
                        <?php if (($ws['id'] ?? '') === 'relations'): ?>
                            <form method="post" action="run.php?assistant=workflow.standard<?= e($wfContextQuery) ?>">
                                <?php if (empty($workflow['optional']['relationsSkipped'])): ?><button type="submit" name="workflow_action" value="skip_relations">Keine Beziehungen erforderlich</button><?php else: ?><button type="submit" name="workflow_action" value="use_relations">Beziehungen doch konfigurieren</button><?php endif; ?>
                            </form>
                        <?php elseif (($ws['id'] ?? '') === 'events'): ?>
                            <form method="post" action="run.php?assistant=workflow.standard<?= e($wfContextQuery) ?>">
                                <?php if (empty($workflow['optional']['eventsSkipped'])): ?><button type="submit" name="workflow_action" value="skip_events">Keine Events erforderlich</button><?php else: ?><button type="submit" name="workflow_action" value="use_events">Events doch konfigurieren</button><?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($workflow['allComplete'])): ?>
                <p><strong>Workflow abgeschlossen.</strong> Alle Pflichtschritte und die Gesamtdiagnose sind erfolgreich abgeschlossen.</p>
            <?php else: ?>
                <?php foreach (($workflow['steps'] ?? []) as $workflowStep): $ws = (array) $workflowStep; if (($ws['id'] ?? '') === ($workflow['nextStep'] ?? null)): ?>
                    <?php if (!empty($ws['available'])): ?><p><a class="eit-assistant-link" href="<?= e((string) ($ws['url'] ?? '#')) ?>">Workflow an der nächsten offenen Stelle fortsetzen</a></p><?php endif; ?>
                <?php break; endif; endforeach; ?>
            <?php endif; ?>
            <p><a href="export.php?assistant=workflow.standard<?= e($wfContextQuery) ?>">Workflow-Status als JSON herunterladen</a></p>
            <p><a href="run.php?assistant=workflow.standard&amp;reset=1<?= e($wfContextQuery) ?>">Workflow-Fortschritt zurücksetzen</a></p>
        </section>
    <?php endif; ?>

    <?php if ($currentStep && $assistantId !== 'workflow.standard'): ?>
        <section class="eit-assistant-work">
            <h2><?= e($currentStep->getTitle()) ?></h2>

            <?php if ($fields !== []): ?>
                <form method="post"<?= $hasFileField ? ' enctype="multipart/form-data"' : '' ?> action="run.php?assistant=<?= rawurlencode($assistantId) ?>&amp;step=<?= rawurlencode($currentStep->getId()) ?><?= e($contextQuery) ?>">
                    <input type="hidden" name="assistant" value="<?= e($assistantId) ?>">
                    <input type="hidden" name="step" value="<?= e($currentStep->getId()) ?>">
                    <?php if ($context->getProjectId()): ?><input type="hidden" name="project_id" value="<?= e($context->getProjectId()) ?>"><?php endif; ?>
                    <?php if ($context->getDataFormId()): ?><input type="hidden" name="dataform_id" value="<?= e($context->getDataFormId()) ?>"><?php endif; ?>

                    <div class="eit-assistant-form-grid">
                    <?php foreach ($fields as $field):
                        $name = (string) ($field['name'] ?? '');
                        $type = (string) ($field['type'] ?? 'text');
                        $label = (string) ($field['label'] ?? $name);
                        $value = $formValues[$name] ?? ($field['default'] ?? '');
                        $id = 'assistant-field-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
                    ?>
                        <div class="eit-assistant-field<?= $type === 'checkbox' ? ' eit-assistant-field-checkbox' : '' ?>">
                            <?php if ($type === 'checkbox'): ?>
                                <label for="<?= e($id) ?>"><input id="<?= e($id) ?>" type="checkbox" name="<?= e($name) ?>" value="1"<?= checked($value) ?>> <?= e($label) ?></label>
                            <?php else: ?>
                                <label for="<?= e($id) ?>"><?= e($label) ?></label>
                                <?php if ($type === 'select'): ?>
                                    <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= !empty($field['required']) ? ' required' : '' ?>>
                                    <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                        <option value="<?= e((string) $optionValue) ?>"<?= (string) $value === (string) $optionValue ? ' selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                                    <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= (int) ($field['rows'] ?? 6) ?>" placeholder="<?= e((string) ($field['placeholder'] ?? '')) ?>"><?= e((string) $value) ?></textarea>
                                <?php elseif ($type === 'file'): ?>
                                    <input id="<?= e($id) ?>" type="file" name="<?= e($name) ?>"<?= !empty($field['accept']) ? ' accept="' . e((string) $field['accept']) . '"' : '' ?><?= !empty($field['required']) ? ' required' : '' ?>>
                                <?php else: ?>
                                    <input id="<?= e($id) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>" value="<?= e((string) $value) ?>"<?= isset($field['min']) ? ' min="' . (int) $field['min'] . '"' : '' ?><?= isset($field['max']) ? ' max="' . (int) $field['max'] . '"' : '' ?><?= !empty($field['required']) ? ' required' : '' ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <div class="eit-assistant-actions">
                        <button type="submit" name="_assistant_action" value="save">Eingaben prüfen</button>
                        <?php if (!empty($data['nextStep'])): ?><button type="submit" name="_assistant_action" value="continue">Speichern und weiter</button><?php endif; ?>
                    </div>
                </form>
            <?php else: ?>



    <?php if ($assistantId === 'dataform.module-library'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-library-title">
            <h2 id="module-library-title">DataForm-Modulbibliothek</h2>
            <?php if (!empty($data['moduleLibrary'])): ?><details open><summary>Sichtbare Module (<?= count((array)$data['moduleLibrary']) ?>)</summary><pre><?= e((string)json_encode($data['moduleLibrary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?><p>Noch keine sichtbaren Module.</p><?php endif; ?>
            <?php if (!empty($data['selectedModule'])): ?><details><summary>Ausgewähltes Modul</summary><pre><?= e((string)json_encode($data['selectedModule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleHistory'])): ?><details open><summary>Versionsverlauf</summary><pre><?= e((string)json_encode($data['moduleHistory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleInstallPreview'])): ?><h3>Installationsvorschau: <?= e((string)($data['moduleInstallPreview']['verdict'] ?? '')) ?></h3><pre><?= e((string)json_encode($data['moduleInstallPreview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['moduleInstallResult'])): ?><h3>Installation: <?= !empty($data['moduleInstallResult']['applied']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['moduleInstallResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['moduleLibraryResult'])): ?><p><strong>PASS:</strong> Bibliotheksvorgang wurde ausgeführt.</p><details open><summary>Ergebnis</summary><pre><?= e((string)json_encode($data['moduleLibraryResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleLibraryExportUrl'])): ?><p><a href="<?= e((string)$data['moduleLibraryExportUrl']) ?>">Modulpaket herunterladen</a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.module-migration-history'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-migration-history-title">
            <h2 id="module-migration-history-title">Migrationshistorie, Schema-Diff und Rollback</h2>
            <?php if (isset($data['migrationRuns'])): ?><details open><summary>Migrationsläufe (<?= count((array)$data['migrationRuns']) ?>)</summary><pre><?= e((string)json_encode($data['migrationRuns'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['migrationRun'])): $mr=(array)$data['migrationRun']; ?><h3>Lauf <?= e((string)($mr['runId'] ?? '')) ?> – <?= e((string)($mr['status'] ?? '')) ?></h3><details open><summary>Vollständiger Lauf</summary><pre><?= e((string)json_encode($mr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['schemaDiff'])): $sd=(array)$data['schemaDiff']; ?><h3>Schema-Diff: <?= (int)($sd['count'] ?? 0) ?> Änderungen</h3><details open><summary>Vorher / Nachher</summary><pre><?= e((string)json_encode($sd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['failureDiagnosis'])): ?><details><summary>Fehlerdiagnose</summary><pre><?= e((string)json_encode($data['failureDiagnosis'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['rollbackResult'])): ?><h3>Rollback: <?= !empty($data['rollbackResult']['ok']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['rollbackResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['rollbackDownloadUrl'])): ?><p><a href="<?= e((string)$data['rollbackDownloadUrl']) ?>">Recovery-Checkpoint herunterladen</a><?php if (!empty($data['rollbackShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['rollbackShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p><?php endif; ?>
            <?php if ($context->getProjectId()): ?><p><a href="export.php?assistant=dataform.module-migration-history&amp;project_id=<?= rawurlencode((string)$context->getProjectId()) ?>">Migrationshistorie als JSON</a><?php if (!empty($data['migrationRun']['runId'])): ?> · <a href="export.php?assistant=dataform.module-migration-history&amp;project_id=<?= rawurlencode((string)$context->getProjectId()) ?>&amp;run_id=<?= rawurlencode((string)$data['migrationRun']['runId']) ?>">Diesen Lauf als JSON</a><?php endif; ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.diagnostics' && !empty($data['diagnosticReport'])): ?>
                    <p>Diagnose abgeschlossen. Der detaillierte PASS/FAIL/WARN/SKIP-Bericht steht unten.</p>
                <?php elseif ($assistantId === 'project.recovery' && !empty($data['recoveryResult'])): ?>
                    <p>Backup/Restore abgeschlossen. Ergebnis und Recovery-Prüfung stehen unten.</p>
                    <p><a href="export.php?assistant=project.recovery<?= e($contextQuery) ?>"><?= e((string) ($data['exportLabel'] ?? 'Recovery-Bericht als JSON herunterladen')) ?></a></p>
                <?php elseif ($assistantId === 'dataform.template-library'): ?>
                    <p>Bibliotheksvorgang abgeschlossen. Bibliothek, Ergebnis, Versionsverlauf oder Export stehen unten.</p>
                <?php elseif ($assistantId === 'dataform.transport'): ?>
                    <p>DataForm-Transport abgeschlossen bzw. geprüft. Paket-, Vorschau- oder Importergebnis steht unten.</p>
                <?php elseif ($assistantId === 'dataform.module-transport'): ?>
                    <p>DataForm-Modultransport abgeschlossen bzw. geprüft. Graph, Paket-, Vorschau- oder Importergebnis steht unten.</p>
                <?php elseif ($assistantId === 'dataform.module-dependencies'): ?>
                    <p>Modulabhängigkeiten, Installationsplan oder Installationsregister stehen unten.</p>
                <?php elseif ($assistantId === 'dataform.module-updates'): ?>
                    <p>Update-Suche, Upgrade-Plan, Auswirkungen und Rollback-Checkpoint stehen unten.</p>
                <?php elseif ($assistantId === 'dataform.module-migrations'): ?>
                    <p>Migrations-Dry-Run, Reihenfolge, Recovery-Checkpoint und Ausführungsergebnis stehen unten.</p>
                <?php elseif ($assistantId === 'dataform.module-migration-history'): ?>
                    <p>Migrationsläufe, Schema-Diffs, Fehlerdiagnose und Rollback-Ergebnis stehen unten.</p>
                <?php elseif (($data['configurationReady'] ?? false) === true): ?>
                    <p>Die Konfiguration ist vollständig und validiert.</p>
                    <p><a href="export.php?assistant=<?= rawurlencode($assistantId) ?><?= e($contextQuery) ?>"><?= e((string) ($data['exportLabel'] ?? 'JSON-Konfiguration herunterladen')) ?></a></p>
                <?php elseif (in_array($assistantId, ['project.create', 'project.recovery', 'datasource.configure', 'dataform.create', 'dataform.templates', 'dataform.template-library', 'dataform.transport', 'dataform.module-transport', 'dataform.module-library', 'dataform.module-dependencies', 'dataform.module-updates','dataform.module-migrations', 'dataform.module-migration-history', 'dataform.module-release-gate', 'dataform.release-catalog', 'dataform.trust-policy', 'dataform.trust-recovery', 'dataform.trust-federation', 'dataform.trust-failover', 'dataform.fields', 'dataform.relations', 'dataform.events', 'dataform.actions', 'dataform.diagnostics'], true)): ?>
                    <p>Der Entwurf ist noch nicht vollständig. Öffne einen fehlerhaften Schritt und korrigiere die Angaben.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.release-catalog'): ?>
        <section class="eit-assistant-result" aria-labelledby="release-catalog-title">
            <h2 id="release-catalog-title">Release-Katalog und Vertrauenskette</h2>
            <?php if (!empty($data['trustStatus'])): ?><details open><summary>Ed25519-Trust-Store</summary><pre><?= e((string)json_encode($data['trustStatus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (isset($data['releaseCatalog'])): ?><details open><summary>Signierte Release-Katalogeinträge (<?= count((array)$data['releaseCatalog']) ?>)</summary><pre><?= e((string)json_encode($data['releaseCatalog'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['catalogResult'])): ?><details open><summary>Katalogisierung / Signatur</summary><pre><?= e((string)json_encode($data['catalogResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['verificationResult'])): ?><h3>Verifikation: <?= !empty($data['verificationResult']['ok']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['verificationResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['rotationResult'])): ?><details open><summary>Schlüsselrotation</summary><pre><?= e((string)json_encode($data['rotationResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <p><?php if (!empty($data['catalogExportUrl'])): ?><a href="<?= e((string)$data['catalogExportUrl']) ?>">Öffentlichen Release-Katalog exportieren</a><?php endif; ?><?php if (!empty($data['trustExportUrl'])): ?> · <a href="<?= e((string)$data['trustExportUrl']) ?>">Öffentlichen Trust-Store exportieren</a><?php endif; ?></p>
        </section>
    <?php endif; ?>


    <?php if ($assistantId === 'dataform.trust-policy'): ?>
        <section class="eit-assistant-result" aria-labelledby="trust-policy-title">
            <h2 id="trust-policy-title">Trust-Policy und Schlüsselverwaltung</h2>
            <?php if (!empty($data['trustStatus'])): ?><details open><summary>Aktueller Trust-Status</summary><pre><?= e((string)json_encode($data['trustStatus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['operationResult'])): ?><details open><summary>Schlüsseloperation / Policy-Prüfung</summary><pre><?= e((string)json_encode($data['operationResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['rotationResult'])): ?><details open><summary>Schlüsselrotation</summary><pre><?= e((string)json_encode($data['rotationResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['auditVerification'])): ?><h3>Audit-Kette: <?= !empty($data['auditVerification']['ok']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['auditVerification'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['trustAudit'])): ?><details><summary>Trust-Audit (<?= count((array)$data['trustAudit']) ?> Ereignisse)</summary><pre><?= e((string)json_encode($data['trustAudit'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <p><?php if (!empty($data['catalogExportUrl'])): ?><a href="<?= e((string)$data['catalogExportUrl']) ?>">Öffentlichen Release-Katalog exportieren</a><?php endif; ?><?php if (!empty($data['trustExportUrl'])): ?> · <a href="<?= e((string)$data['trustExportUrl']) ?>">Öffentlichen Trust-Store exportieren</a><?php endif; ?></p>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.trust-recovery'): ?>
        <section class="eit-assistant-result" aria-labelledby="trust-recovery-title">
            <h2 id="trust-recovery-title">Trust-Backup, Offline-Verifikation und Recovery</h2>
            <?php if (!empty($data['recoveryStatus'])): ?><details open><summary>Recovery-/Trust-Status</summary><pre><?= e((string)json_encode($data['recoveryStatus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['publicResult'])): ?><details open><summary>Public-Trust-Bundle</summary><pre><?= e((string)json_encode($data['publicResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['privateResult'])): ?><details open><summary>Private-Key-Backup / Restore</summary><pre><?= e((string)json_encode($data['privateResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['offlineResult'])): ?><h3>Offline-Verifikation: <?= !empty($data['offlineResult']['ok']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['offlineResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['recoveryResult'])): ?><details open><summary>Lost-Key-Recovery</summary><pre><?= e((string)json_encode($data['recoveryResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <p><?php if (!empty($data['publicDownloadUrl'])): ?><a href="<?= e((string)$data['publicDownloadUrl']) ?>">Public-Trust-Bundle herunterladen</a><?php endif; ?><?php if (!empty($data['publicShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['publicShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p>
            <p><?php if (!empty($data['privateDownloadUrl'])): ?><a href="<?= e((string)$data['privateDownloadUrl']) ?>">Verschlüsseltes Private-Key-Backup herunterladen</a><?php endif; ?><?php if (!empty($data['privateShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['privateShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p>
            <p><?php if (!empty($data['offlineDownloadUrl'])): ?><a href="<?= e((string)$data['offlineDownloadUrl']) ?>">Offline-Prüfpaket herunterladen</a><?php endif; ?><?php if (!empty($data['offlineShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['offlineShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.trust-federation'): ?>
        <section class="eit-assistant-result" aria-labelledby="trust-federation-title">
            <h2 id="trust-federation-title">Trust-Disaster-Recovery und Mehrinstanz-Vertrauen</h2>
            <?php if (!empty($data['federationStatus'])): ?><details open><summary>Instanz-/Federation-Status</summary><pre><?= e((string)json_encode($data['federationStatus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['setupResult'])): ?><details open><summary>Instanzinitialisierung</summary><pre><?= e((string)json_encode($data['setupResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['syncExportResult'])): ?><details open><summary>Sync-Export</summary><pre><?= e((string)json_encode($data['syncExportResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['syncImportResult'])): ?><details open><summary>Sync-Vergleich / Anwendung</summary><pre><?= e((string)json_encode($data['syncImportResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['disasterExportResult'])): ?><details open><summary>Disaster-Recovery-Export</summary><pre><?= e((string)json_encode($data['disasterExportResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['disasterRestoreResult'])): ?><details open><summary>Disaster-Recovery-Restore</summary><pre><?= e((string)json_encode($data['disasterRestoreResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['roleResult'])): ?><details open><summary>Rollenwechsel</summary><pre><?= e((string)json_encode($data['roleResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <p><?php if (!empty($data['syncDownloadUrl'])): ?><a href="<?= e((string)$data['syncDownloadUrl']) ?>">Trust-Sync-Paket herunterladen</a><?php endif; ?><?php if (!empty($data['syncShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['syncShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p>
            <p><?php if (!empty($data['disasterDownloadUrl'])): ?><a href="<?= e((string)$data['disasterDownloadUrl']) ?>">Trust-Disaster-Paket herunterladen</a><?php endif; ?><?php if (!empty($data['disasterShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['disasterShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p>
        </section>
    <?php if ($assistantId === 'dataform.trust-failover'): ?>
        <section class="eit-assistant-result" aria-labelledby="trust-failover-title">
            <h2 id="trust-failover-title">PRIMARY-Failover und Quorum-Schutz</h2>
            <?php if (!empty($data['failoverStatus'])): ?><details open><summary>Failover-/Lease-Status</summary><pre><?= e((string)json_encode($data['failoverStatus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php foreach (['identityResult'=>'Instanz-Identity','clusterResult'=>'Cluster-Mitgliedschaft','leaseProposalResult'=>'Lease-Proposal','leaseAckResult'=>'Lease-ACK','leaseActivationResult'=>'Leadership-Lease','heartbeatResult'=>'Heartbeat','electionResult'=>'Election','promotionResult'=>'Quorum-Promotion'] as $key=>$label): ?>
                <?php if (!empty($data[$key])): ?><details open><summary><?= e($label) ?></summary><pre><?= e((string)json_encode($data[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php endforeach; ?>
            <p>
                <?php foreach (['identityDownloadUrl'=>'Identity','clusterDownloadUrl'=>'Cluster','leaseProposalDownloadUrl'=>'Lease-Proposal','leaseAckDownloadUrl'=>'Lease-ACK','leadershipLeaseDownloadUrl'=>'Leadership-Lease','heartbeatDownloadUrl'=>'Heartbeat','electionRequestDownloadUrl'=>'Election-Request','electionVoteDownloadUrl'=>'Election-Vote'] as $key=>$label): ?>
                    <?php if (!empty($data[$key])): ?><a href="<?= e((string)$data[$key]) ?>"><?= e($label) ?> herunterladen</a> · <?php endif; ?>
                <?php endforeach; ?>
            </p>
        </section>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ($assistantId === 'dataform.templates'): ?>
        <section class="eit-assistant-result" aria-labelledby="template-status-title">
            <h2 id="template-status-title">DataForm-Vorlagen</h2>
            <?php if (!empty($data['templates'])): ?>
                <details><summary>Gespeicherte Vorlagen (<?= count($data['templates']) ?>)</summary>
                    <pre><?= e((string) json_encode($data['templates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            <?php else: ?><p>Noch keine DataForm-Vorlagen gespeichert.</p><?php endif; ?>
            <?php if (!empty($data['createdTemplate'])): ?>
                <p><strong>Vorlage gespeichert:</strong> <?= e((string) ($data['createdTemplate']['name'] ?? $data['createdTemplate']['id'] ?? '')) ?></p>
                <details><summary>Enthaltene Konfigurationen</summary><pre><?= e((string) json_encode(array_keys((array)($data['createdTemplate']['bundle'] ?? [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
            <?php endif; ?>
            <?php if (!empty($data['templatePreview'])): $tp = (array)$data['templatePreview']; ?>
                <h3>Diagnose vor Übernahme: <?= e((string)($tp['verdict'] ?? '')) ?></h3>
                <p>Fehler: <?= count((array)($tp['errors'] ?? [])) ?> · Hinweise: <?= count((array)($tp['warnings'] ?? [])) ?></p>
                <?php if (!empty($tp['checks'])): ?><details open><summary>Prüfungen</summary><pre><?= e((string) json_encode($tp['checks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                <details><summary>Gemappte Zielkonfiguration</summary><pre><?= e((string) json_encode($tp['mappedBundle'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
            <?php endif; ?>
            <?php if (!empty($data['templateApplyResult']['applied'])): ?>
                <p><strong>PASS:</strong> Vorlage wurde übernommen und die Zielzustände wurden in der Assistenten-Historie versioniert.</p>
                <?php if (!empty($data['handoffUrl'])): ?><p><a href="<?= e((string)$data['handoffUrl']) ?>"><?= e((string)($data['handoffLabel'] ?? 'Diagnose öffnen')) ?></a></p><?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.template-library'): ?>
        <section class="eit-assistant-result" aria-labelledby="template-library-title">
            <h2 id="template-library-title">Vorlagenbibliothek</h2>
            <?php if (!empty($data['templateLibrary'])): ?>
                <details open><summary>Sichtbare Vorlagen (<?= count((array)$data['templateLibrary']) ?>)</summary><pre><?= e((string)json_encode($data['templateLibrary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
            <?php else: ?><p>Noch keine sichtbaren Vorlagen.</p><?php endif; ?>
            <?php if (!empty($data['selectedTemplate'])): ?><details><summary>Ausgewählte Vorlage</summary><pre><?= e((string)json_encode($data['selectedTemplate'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['templateHistory'])): ?><details open><summary>Versionsverlauf</summary><pre><?= e((string)json_encode($data['templateHistory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['templateLibraryResult'])): ?><p><strong>PASS:</strong> Bibliotheksvorgang wurde ausgeführt.</p><details open><summary>Ergebnis</summary><pre><?= e((string)json_encode($data['templateLibraryResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['templateExportUrl'])): ?><p><a href="<?= e((string)$data['templateExportUrl']) ?>">Vorlage als JSON-Datei exportieren</a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.transport'): ?>
        <?php if (!empty($data['packageInspection'])): ?>
            <section class="eit-assistant-result"><h2>Paketprüfung</h2><pre><?= e((string)json_encode($data['packageInspection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
        <?php endif; ?>
        <?php if (!empty($data['packagePreview'])): ?>
            <section class="eit-assistant-result eit-diagnostic-report"><h2>Import-Vorschau: <?= e((string)($data['packagePreview']['verdict'] ?? '')) ?></h2><pre><?= e((string)json_encode($data['packagePreview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
        <?php endif; ?>
        <?php if (!empty($data['packageExportResult'])): ?>
            <section class="eit-assistant-result"><h2>DataForm-Paket exportiert</h2><p><strong>SHA-256:</strong> <code><?= e((string)($data['packageExportResult']['sha256'] ?? '')) ?></code></p><?php if (!empty($data['downloadUrl'])): ?><p><a href="<?= e((string)$data['downloadUrl']) ?>">DataForm-Paket herunterladen</a></p><?php endif; ?><?php if (!empty($data['shaDownloadUrl'])): ?><p><a href="<?= e((string)$data['shaDownloadUrl']) ?>">SHA-256-Datei herunterladen</a></p><?php endif; ?><details><summary>Manifest</summary><pre><?= e((string)json_encode($data['packageExportResult']['manifest'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details></section>
        <?php endif; ?>
        <?php if (!empty($data['packageApplyResult'])): ?>
            <section class="eit-assistant-result eit-diagnostic-report"><h2>Import-Ergebnis</h2><p><strong><?= !empty($data['packageApplyResult']['applied']) ? 'PASS' : 'FAIL' ?></strong></p><pre><?= e((string)json_encode($data['packageApplyResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php if (!empty($data['handoffUrl'])): ?><p><a href="<?= e((string)$data['handoffUrl']) ?>"><?= e((string)($data['handoffLabel'] ?? 'Diagnose öffnen')) ?></a></p><?php endif; ?></section>
        <?php endif; ?>
    <?php endif; ?>


    <?php if ($assistantId === 'dataform.module-transport'): ?>
        <?php if (!empty($data['moduleInspection'])): ?>
            <section class="eit-assistant-result"><h2>Modulpaket-Prüfung</h2><pre><?= e((string)json_encode($data['moduleInspection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
        <?php endif; ?>
        <?php if (!empty($data['modulePreview'])): ?>
            <section class="eit-assistant-result eit-diagnostic-report"><h2>Modul-Import-Vorschau: <?= e((string)($data['modulePreview']['verdict'] ?? '')) ?></h2><pre><?= e((string)json_encode($data['modulePreview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
        <?php endif; ?>
        <?php if (!empty($data['moduleExportResult'])): ?>
            <section class="eit-assistant-result"><h2>DataForm-Modul exportiert</h2><p><strong>DataForms:</strong> <?= (int)($data['moduleExportResult']['moduleCount'] ?? 0) ?></p><p><strong>SHA-256:</strong> <code><?= e((string)($data['moduleExportResult']['sha256'] ?? '')) ?></code></p><?php if (!empty($data['downloadUrl'])): ?><p><a href="<?= e((string)$data['downloadUrl']) ?>">DataForm-Modulpaket herunterladen</a></p><?php endif; ?><?php if (!empty($data['shaDownloadUrl'])): ?><p><a href="<?= e((string)$data['shaDownloadUrl']) ?>">SHA-256-Datei herunterladen</a></p><?php endif; ?><details open><summary>Beziehungsgraph</summary><pre><?= e((string)json_encode($data['moduleExportResult']['graph'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><details><summary>Manifest</summary><pre><?= e((string)json_encode($data['moduleExportResult']['manifest'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details></section>
        <?php endif; ?>
        <?php if (!empty($data['moduleApplyResult'])): ?>
            <section class="eit-assistant-result eit-diagnostic-report"><h2>Modul-Import-Ergebnis</h2><p><strong><?= !empty($data['moduleApplyResult']['applied']) ? 'PASS' : 'FAIL' ?></strong></p><pre><?= e((string)json_encode($data['moduleApplyResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
        <?php endif; ?>
    <?php endif; ?>


    <?php if ($assistantId === 'dataform.module-library'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-library-title">
            <h2 id="module-library-title">DataForm-Modulbibliothek</h2>
            <?php if (!empty($data['moduleLibrary'])): ?><details open><summary>Sichtbare Module (<?= count((array)$data['moduleLibrary']) ?>)</summary><pre><?= e((string)json_encode($data['moduleLibrary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?><p>Noch keine sichtbaren Module.</p><?php endif; ?>
            <?php if (!empty($data['selectedModule'])): ?><details><summary>Ausgewähltes Modul</summary><pre><?= e((string)json_encode($data['selectedModule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleHistory'])): ?><details open><summary>Versionsverlauf</summary><pre><?= e((string)json_encode($data['moduleHistory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleInstallPreview'])): ?><h3>Installationsvorschau: <?= e((string)($data['moduleInstallPreview']['verdict'] ?? '')) ?></h3><pre><?= e((string)json_encode($data['moduleInstallPreview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['moduleInstallResult'])): ?><h3>Installation: <?= !empty($data['moduleInstallResult']['applied']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['moduleInstallResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['moduleLibraryResult'])): ?><p><strong>PASS:</strong> Bibliotheksvorgang wurde ausgeführt.</p><details open><summary>Ergebnis</summary><pre><?= e((string)json_encode($data['moduleLibraryResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleLibraryExportUrl'])): ?><p><a href="<?= e((string)$data['moduleLibraryExportUrl']) ?>">Modulpaket herunterladen</a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.module-dependencies'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-dependency-title">
            <h2 id="module-dependency-title">Modulabhängigkeiten und Installation</h2>
            <?php if (!empty($data['moduleReleaseResult'])): ?><h3>Release gespeichert</h3><pre><?= e((string)json_encode($data['moduleReleaseResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['moduleDependencyPlan'])): $mdp=(array)$data['moduleDependencyPlan']; ?><h3>Installationsplan: <?= e((string)($mdp['verdict'] ?? '')) ?></h3><p><strong>Reihenfolge:</strong> <?= e(implode(' → ', array_map('strval',(array)($mdp['installModuleIds'] ?? [])))) ?></p><details open><summary>Prüfungen und Plan</summary><pre><?= e((string)json_encode($mdp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleInstallResult'])): ?><h3>Installation: <?= !empty($data['moduleInstallResult']['applied']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['moduleInstallResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (isset($data['installedModules'])): ?><details open><summary>Installierte Module (<?= count((array)$data['installedModules']) ?>)</summary><pre><?= e((string)json_encode($data['installedModules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.module-updates'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-update-title">
            <h2 id="module-update-title">Modul-Updates und Upgrades</h2>
            <?php if (!empty($data['moduleUpdateScan'])): ?><details open><summary>Update-Suche</summary><pre><?= e((string)json_encode($data['moduleUpdateScan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleUpdatePlan'])): ?><h3>Upgrade-Plan: <?= e((string)($data['moduleUpdatePlan']['verdict'] ?? '')) ?></h3><details open><summary>Plan und Kompatibilitätsprüfung</summary><pre><?= e((string)json_encode($data['moduleUpdatePlan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleUpdateResult'])): ?><h3>Upgrade: <?= !empty($data['moduleUpdateResult']['applied']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['moduleUpdateResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['rollbackDownloadUrl'])): ?><p><a href="<?= e((string)$data['rollbackDownloadUrl']) ?>">Rollback-Projektbackup herunterladen</a><?php if (!empty($data['rollbackShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['rollbackShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p><?php endif; ?>
            <?php if (!empty($data['moduleUpdateCheckpoints'])): ?><details><summary>Rollback-Checkpoints (<?= count((array)$data['moduleUpdateCheckpoints']) ?>)</summary><pre><?= e((string)json_encode($data['moduleUpdateCheckpoints'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['handoffUrl'])): ?><p><a href="<?= e((string)$data['handoffUrl']) ?>"><?= e((string)($data['handoffLabel'] ?? 'Migrationsassistent öffnen')) ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.module-migrations'): ?>
        <section class="eit-assistant-result" aria-labelledby="module-migration-title">
            <h2 id="module-migration-title">Modul-Updates und Migrationen</h2>
            <?php if (!empty($data['moduleMigrationPlan'])): $mmp=(array)$data['moduleMigrationPlan']; ?><h3>Dry-Run: <?= e((string)($mmp['verdict'] ?? '')) ?></h3><p><strong>Migrationen:</strong> <?= (int)($mmp['migrationCount'] ?? 0) ?> · <strong>destruktiv:</strong> <?= (int)($mmp['destructiveCount'] ?? 0) ?></p><details open><summary>Migrations- und Upgrade-Plan</summary><pre><?= e((string)json_encode($mmp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['moduleMigrationResult'])): ?><h3>Ausführung: <?= !empty($data['moduleMigrationResult']['applied']) ? 'PASS' : 'FAIL' ?></h3><pre><?= e((string)json_encode($data['moduleMigrationResult'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            <?php if (!empty($data['migrationHistory'])): ?><details><summary>Migrationsregister</summary><pre><?= e((string)json_encode($data['migrationHistory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
            <?php if (!empty($data['rollbackDownloadUrl'])): ?><p><a href="<?= e((string)$data['rollbackDownloadUrl']) ?>">Rollback-Projektbackup herunterladen</a><?php if (!empty($data['rollbackShaDownloadUrl'])): ?> · <a href="<?= e((string)$data['rollbackShaDownloadUrl']) ?>">SHA-256</a><?php endif; ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'dataform.diagnostics' && !empty($data['diagnosticReport'])): ?>
        <?php $diag = $data['diagnosticReport'] instanceof JsonSerializable ? $data['diagnosticReport']->jsonSerialize() : (array) $data['diagnosticReport']; ?>
        <section class="eit-assistant-result eit-diagnostic-report" aria-labelledby="diagnostic-report-title">
            <h2 id="diagnostic-report-title">Diagnosebericht: <?= e((string) ($diag['verdict'] ?? '')) ?></h2>
            <p>
                PASS: <?= (int) ($diag['counts']['pass'] ?? 0) ?> ·
                FAIL: <?= (int) ($diag['counts']['fail'] ?? 0) ?> ·
                WARN: <?= (int) ($diag['counts']['warn'] ?? 0) ?> ·
                SKIP: <?= (int) ($diag['counts']['skip'] ?? 0) ?>
            </p>
            <div class="eit-diagnostic-list">
                <?php foreach (($diag['checks'] ?? []) as $check): ?>
                    <?php $status = strtoupper((string) ($check['status'] ?? '')); ?>
                    <article class="eit-diagnostic-check" data-status="<?= e(strtolower($status)) ?>">
                        <h3><?= e($status) ?> – <?= e((string) ($check['title'] ?? 'Prüfung')) ?></h3>
                        <p><strong><?= e((string) ($check['group'] ?? '')) ?> / <?= e((string) ($check['id'] ?? '')) ?></strong></p>
                        <p><?= e((string) ($check['message'] ?? '')) ?></p>
                        <?php if (!empty($check['recommendation'])): ?><p><strong>Empfehlung:</strong> <?= e((string) $check['recommendation']) ?></p><?php endif; ?>
                        <?php if (!empty($check['details'])): ?><details><summary>Details</summary><pre><?= e((string) json_encode($check['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <p><a href="export.php?assistant=dataform.diagnostics<?= e($contextQuery) ?>">Diagnosebericht als JSON herunterladen</a></p>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'project.create' && !empty($data['provisioningPlan'])): ?>
        <section class="eit-assistant-result" aria-labelledby="project-plan-title">
            <h2 id="project-plan-title">Projektstruktur</h2>
            <p><strong>Ziel:</strong> <?= e((string) ($data['provisioningPlan']['projectAbsolutePath'] ?? '')) ?></p>
            <?php if (!empty($data['provisioningPlan']['directories'])): ?>
                <details><summary>Verzeichnisse</summary><pre><?= e((string) implode("\n", $data['provisioningPlan']['directories'])) ?></pre></details>
            <?php endif; ?>
            <?php if (!empty($data['provisioningResult']['message'])): ?><p><strong>Status:</strong> <?= e((string) $data['provisioningResult']['message']) ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'project.recovery' && !empty($data['recoveryResult'])): ?>
        <section class="eit-assistant-result" aria-labelledby="recovery-result-title">
            <h2 id="recovery-result-title">Backup / Restore Ergebnis</h2>
            <p><strong><?= !empty($data['recoveryResult']['ok']) ? 'PASS' : 'FAIL' ?>:</strong> <?= e((string) ($data['recoveryResult']['message'] ?? '')) ?></p>
            <?php if (!empty($data['recoveryResult']['sha256'])): ?><p><strong>SHA-256:</strong> <code><?= e((string) $data['recoveryResult']['sha256']) ?></code></p><?php endif; ?>
            <?php if (!empty($data['downloadUrl'])): ?><p><a href="<?= e((string) $data['downloadUrl']) ?>">Projekt-Backup ZIP herunterladen</a></p><?php endif; ?>
            <?php if (!empty($data['shaDownloadUrl'])): ?><p><a href="<?= e((string) $data['shaDownloadUrl']) ?>">SHA-256-Datei herunterladen</a></p><?php endif; ?>
            <?php if (!empty($data['recoveryResult']['details'])): ?><details><summary>Details</summary><pre><?= e((string) json_encode($data['recoveryResult']['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($assistantId === 'project.recovery' && !empty($data['recoveryReport'])): ?>
        <?php $recoveryReport = (array) $data['recoveryReport']; ?>
        <section class="eit-assistant-result eit-diagnostic-report" aria-labelledby="recovery-report-title">
            <h2 id="recovery-report-title">Recovery-Diagnose: <?= e((string) ($recoveryReport['verdict'] ?? '')) ?></h2>
            <p>PASS: <?= (int) ($recoveryReport['counts']['pass'] ?? 0) ?> · FAIL: <?= (int) ($recoveryReport['counts']['fail'] ?? 0) ?> · WARN: <?= (int) ($recoveryReport['counts']['warn'] ?? 0) ?> · SKIP: <?= (int) ($recoveryReport['counts']['skip'] ?? 0) ?></p>
            <div class="eit-diagnostic-list">
                <?php foreach (($recoveryReport['checks'] ?? []) as $check): ?>
                    <?php $status = strtoupper((string) ($check['status'] ?? '')); ?>
                    <article class="eit-diagnostic-check" data-status="<?= e(strtolower($status)) ?>">
                        <h3><?= e($status) ?> – <?= e((string) ($check['title'] ?? 'Prüfung')) ?></h3>
                        <p><?= e((string) ($check['message'] ?? '')) ?></p>
                        <?php if (!empty($check['recommendation'])): ?><p><strong>Empfehlung:</strong> <?= e((string) $check['recommendation']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($data['compiledConfig'])): ?>
        <section class="eit-assistant-result">
            <h2><?= e((string) ($data['configurationTitle'] ?? 'DataForm-Konfiguration')) ?></h2>
            <pre><?= e((string) json_encode($data['compiledConfig'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php if (!empty($data['handoffUrl'])): ?><p><a href="<?= e((string) $data['handoffUrl']) ?>"><?= e((string) ($data['handoffLabel'] ?? 'Weiterverwenden')) ?></a></p><?php endif; ?>
        </section>
    <?php elseif ($assistantId === 'core.status'): ?>
        <section class="eit-assistant-result"><h2>Ergebnis</h2><pre><?= e((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></section>
    <?php endif; ?>

    <?php if ($assistantId !== 'workflow.standard' && (string) $context->input('workflow', '') === 'standard'): ?>
        <nav class="eit-assistant-bottom-nav" aria-label="Workflow-Navigation">
            <a href="run.php?assistant=workflow.standard<?= e($projectQuery . $dataFormQuery) ?>">Workflow-Fortschritt prüfen und fortsetzen</a>
        </nav>
    <?php endif; ?>

    <?php if (in_array($assistantId, ['project.create', 'project.recovery', 'datasource.configure', 'dataform.create', 'dataform.templates', 'dataform.template-library', 'dataform.transport', 'dataform.module-transport', 'dataform.module-library', 'dataform.module-dependencies', 'dataform.module-updates','dataform.module-migrations', 'dataform.module-migration-history', 'dataform.module-release-gate', 'dataform.release-catalog', 'dataform.trust-policy', 'dataform.trust-recovery', 'dataform.trust-federation', 'dataform.trust-failover', 'dataform.fields', 'dataform.relations', 'dataform.events', 'dataform.actions', 'dataform.diagnostics'], true)): ?>
        <nav class="eit-assistant-bottom-nav" aria-label="Assistentennavigation">
            <?php if (!empty($data['previousStep'])): ?><a href="run.php?assistant=<?= rawurlencode($assistantId) ?>&amp;step=<?= rawurlencode((string) $data['previousStep']) ?><?= e($contextQuery) ?>">Vorheriger Schritt</a><?php endif; ?>
            <?php
            if ($assistantId === 'project.create') $resetStep = 'identity';
            elseif ($assistantId === 'project.recovery') $resetStep = 'operation';
            elseif ($assistantId === 'datasource.configure') $resetStep = 'profile';
            elseif ($assistantId === 'dataform.module-release-gate') $resetStep = 'select';
            elseif (in_array($assistantId, ['dataform.release-catalog','dataform.trust-policy','dataform.trust-recovery','dataform.trust-federation','dataform.trust-failover'], true)) $resetStep = 'overview';
            elseif (in_array($assistantId, ['dataform.module-updates','dataform.module-migrations','dataform.module-migration-history'], true)) $resetStep = 'project';
            elseif (in_array($assistantId, ['dataform.templates','dataform.template-library','dataform.transport','dataform.module-transport','dataform.module-library','dataform.module-dependencies'], true)) $resetStep = 'operation';
            elseif ($assistantId === 'dataform.relations') $resetStep = 'relation';
            elseif (in_array($assistantId, ['dataform.fields','dataform.events','dataform.actions','dataform.diagnostics'], true)) $resetStep = 'context';
            else $resetStep = 'source';
            ?>
            <a href="run.php?assistant=<?= rawurlencode($assistantId) ?>&amp;step=<?= rawurlencode($resetStep) ?>&amp;reset=1<?= e($contextQuery) ?>">Entwurf zurücksetzen</a>
        </nav>
    <?php endif; ?>
</main>
<script src="../../assets/js/assistant.js" defer></script>
<script src="../../assets/js/dataform-events.js" defer></script>
<script src="../../assets/js/dataform-actions.js" defer></script>
<script src="../../assets/js/dataform-fields.js" defer></script>
<script src="../../assets/js/assistant-launcher.js" defer></script>
</body>
</html>
