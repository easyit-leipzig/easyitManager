<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$assistantId = trim((string) ($_REQUEST['assistant'] ?? ''));
$projectId = trim((string) ($context->getProjectId() ?? ''));
$dataFormId = trim((string) ($context->getDataFormId() ?? ''));
$integrationRegistry31 = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
$catalog31 = $integrationRegistry31->getCatalog();

function eh(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (!$catalog31->hasHistory($assistantId) || !$assistantManager->getRegistry()->has($assistantId)) {
    http_response_code(400); die('Ungültiger oder nicht historisierter Assistent.');
}
if ($projectId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId) || !is_dir($root . '/projects/' . $projectId)) {
    http_response_code(400); die('Für die Historie ist ein gültiger Projektkontext erforderlich.');
}
if (\EasyIT\Assistant\State\AssistantScopeResolver::requiresDataForm($assistantId) && $dataFormId === '') {
    http_response_code(400); die('Für diesen Assistenten ist zusätzlich ein DataForm-Kontext erforderlich.');
}

$scope = \EasyIT\Assistant\State\AssistantScopeResolver::resolve($assistantId, $projectId, $dataFormId);
$store = new \EasyIT\Assistant\State\AssistantStateStore('easyit_assistant', $root);
$actions = new \EasyIT\Assistant\State\AssistantHistoryActionRegistry();
$assistant = $assistantManager->getRegistry()->get($assistantId);

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['easyit_assistant_history_csrf'])) {
    $_SESSION['easyit_assistant_history_csrf'] = bin2hex(random_bytes(24));
}
$csrf = session_status() === PHP_SESSION_ACTIVE ? (string) ($_SESSION['easyit_assistant_history_csrf'] ?? '') : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $provided = (string) ($_POST['_csrf'] ?? '');
    if ($csrf === '' || $provided === '' || !hash_equals($csrf, $provided)) {
        http_response_code(403); die('Ungültige Sicherheitsprüfung.');
    }
    $op = (string) ($_POST['history_action'] ?? '');
    $result = match ($op) {
        'undo' => $store->undo($assistantId, $projectId, $scope),
        'redo' => $store->redo($assistantId, $projectId, $scope),
        'restore' => $store->restoreHistoryVersion($assistantId, $projectId, $scope, (string) ($_POST['version_id'] ?? '')),
        default => ['ok' => false, 'message' => 'Unbekannte Historienaktion.'],
    };
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['easyit_assistant_history_flash'] = [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }
    $query = ['assistant' => $assistantId, 'project_id' => $projectId];
    if ($dataFormId !== '') { $query['dataform_id'] = $dataFormId; }
    header('Location: history.php?' . http_build_query($query)); exit;
}

$history = $store->history($assistantId, $projectId, $scope);
$currentVersionId = (string) ($history['currentVersionId'] ?? '');
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$comparison = ($from !== '' && $to !== '') ? $store->compareHistory($assistantId, $projectId, $scope, $from, $to) : [];
$flash = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['easyit_assistant_history_flash'] ?? null) : null;
if (session_status() === PHP_SESSION_ACTIVE) { unset($_SESSION['easyit_assistant_history_flash']); }

if ((string) ($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'schema' => 'easyit.assistant.history-report.v1',
        'assistantId' => $assistantId,
        'projectId' => $projectId,
        'dataFormId' => $dataFormId !== '' ? $dataFormId : null,
        'scope' => $scope,
        'history' => $history,
        'comparison' => $comparison,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$base = ['assistant' => $assistantId, 'project_id' => $projectId];
if ($dataFormId !== '') { $base['dataform_id'] = $dataFormId; }
$runQuery = $base;
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>easyIT Enterprise – Assistenten-Historie</title>
    <link rel="stylesheet" href="../../assets/css/assistant.css">
</head>
<body>
<main class="eit-assistant-page eit-history-page">
    <?php
    $navigation31 = new \EasyIT\Assistant\Integration\AssistantPageNavigationRenderer($catalog31, new \EasyIT\Assistant\Integration\AssistantUiActionRegistry());
    echo $navigation31->render($context, $assistantId, false, false);
    ?>
    <nav class="eit-assistant-nav"><a href="run.php?<?= eh(http_build_query($runQuery)) ?>" data-button-key="back" title="Zur vorherigen Assistentenansicht zurückkehren" aria-label="Zurück zu <?= eh($assistant->getTitle()) ?>">Zurück zu <?= eh($assistant->getTitle()) ?></a></nav>
    <header class="eit-assistant-header">
        <h1>Historie – <?= eh($assistant->getTitle()) ?></h1>
        <p>Projekt: <strong><?= eh($projectId) ?></strong><?php if ($dataFormId !== ''): ?> · DataForm: <strong><?= eh($dataFormId) ?></strong><?php endif; ?></p>
    </header>

    <?php if (is_array($flash)): ?>
        <section class="eit-assistant-message" role="status" data-state="<?= !empty($flash['ok']) ? 'ok' : 'error' ?>"><p><?= eh((string) ($flash['message'] ?? '')) ?></p></section>
    <?php endif; ?>

    <section class="eit-assistant-work" aria-labelledby="history-actions-title">
        <h2 id="history-actions-title">Undo / Redo</h2>
        <form method="post" class="eit-history-actions">
            <input type="hidden" name="_csrf" value="<?= eh($csrf) ?>">
            <?php $undo = $actions->get('undo'); $redo = $actions->get('redo'); ?>
            <button type="submit" name="history_action" value="undo" data-button-key="<?= eh((string) $undo['buttonKey']) ?>" title="<?= eh((string) $undo['title']) ?>" aria-label="<?= eh((string) $undo['ariaLabel']) ?>"<?= empty($history['canUndo']) ? ' disabled' : '' ?>>Rückgängig</button>
            <button type="submit" name="history_action" value="redo" data-button-key="<?= eh((string) $redo['buttonKey']) ?>" title="<?= eh((string) $redo['title']) ?>" aria-label="<?= eh((string) $redo['ariaLabel']) ?>"<?= empty($history['canRedo']) ? ' disabled' : '' ?>>Wiederholen</button>
            <a href="history.php?<?= eh(http_build_query(array_merge($base, ['format' => 'json']))) ?>">Historie als JSON</a>
        </form>
    </section>

    <?php if (!empty($comparison['ok'])): ?>
        <section class="eit-assistant-work eit-history-compare" aria-labelledby="history-compare-title">
            <h2 id="history-compare-title">Vergleich vorher / nachher</h2>
            <p><?= (int) ($comparison['count'] ?? 0) ?> Änderung(en) zwischen <code><?= eh((string) ($comparison['fromVersionId'] ?? '')) ?></code> und <code><?= eh((string) ($comparison['toVersionId'] ?? '')) ?></code>.</p>
            <div class="eit-history-diff-list">
                <?php foreach (($comparison['changes'] ?? []) as $change): $c = (array) $change; ?>
                    <article class="eit-history-diff" data-type="<?= eh((string) ($c['type'] ?? 'changed')) ?>">
                        <h3><?= eh((string) ($c['path'] ?? '$')) ?></h3>
                        <p><strong>Typ:</strong> <?= eh((string) ($c['type'] ?? 'changed')) ?></p>
                        <div class="eit-history-before-after">
                            <div><strong>Vorher</strong><pre><?= eh((string) json_encode($c['before'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
                            <div><strong>Nachher</strong><pre><?= eh((string) json_encode($c['after'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="eit-assistant-work" aria-labelledby="history-list-title">
        <h2 id="history-list-title">Versionen</h2>
        <?php if (($history['entries'] ?? []) === []): ?>
            <p>Noch keine Historienversion vorhanden. Die nächste persistente Änderung legt automatisch die Baseline und den neuen Stand an.</p>
        <?php else: ?>
            <div class="eit-history-list">
            <?php foreach (($history['entries'] ?? []) as $entry): $v = (array) $entry; $id = (string) ($v['id'] ?? ''); ?>
                <article class="eit-history-version" data-current="<?= !empty($v['current']) ? 'true' : 'false' ?>" data-detached="<?= !empty($v['detached']) ? 'true' : 'false' ?>">
                    <h3><?= !empty($v['current']) ? 'AKTUELL – ' : '' ?><?= eh($id) ?></h3>
                    <p><?= eh((string) ($v['createdAt'] ?? '')) ?> · Aktion: <strong><?= eh((string) ($v['action'] ?? '')) ?></strong><?= !empty($v['detached']) ? ' · abgelöster Redo-Zweig' : '' ?></p>
                    <p>SHA-256: <code><?= eh((string) ($v['checksum'] ?? '')) ?></code></p>
                    <div class="eit-history-version-actions">
                        <?php if ($currentVersionId !== '' && $id !== $currentVersionId): ?>
                            <a href="history.php?<?= eh(http_build_query(array_merge($base, ['from' => $id, 'to' => $currentVersionId]))) ?>">Mit aktuellem Stand vergleichen</a>
                        <?php endif; ?>
                        <?php if (empty($v['current'])): $restore = $actions->get('restore'); ?>
                            <form method="post" onsubmit="return confirm('Diesen Zustand als neuen aktuellen Stand wiederherstellen?');">
                                <input type="hidden" name="_csrf" value="<?= eh($csrf) ?>">
                                <input type="hidden" name="version_id" value="<?= eh($id) ?>">
                                <button type="submit" name="history_action" value="restore" data-button-key="<?= eh((string) $restore['buttonKey']) ?>" title="<?= eh((string) $restore['title']) ?>" aria-label="<?= eh((string) $restore['ariaLabel']) ?>">Diesen Stand wiederherstellen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <details><summary>Zustand anzeigen</summary><pre><?= eh((string) json_encode($store->historyVersion($assistantId, $projectId, $scope, $id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
