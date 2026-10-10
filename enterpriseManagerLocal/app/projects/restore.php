<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require dirname(__DIR__, 2) . '/system/app/project_restore.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.restore');
$pdo = enterprise_pdo();
enterprise_upgrade($pdo);
$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
$error = '';
$success = '';
$previewToken = trim((string)($_GET['token'] ?? $_POST['restore_token'] ?? ''));
$previewState = null;
$preview = null;
$form = [
    'name' => '',
    'slug' => '',
    'database_name' => '',
    'status' => 'active',
    'database_strategy' => 'auto',
    'replace_confirm' => '',
];
$restoredProjectId = 0;

function enterprise_project_restore_slug_from_name(string $value): string
{
    $value = trim($value);
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 159) : 'restored-project';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'preview') {
            $staged = enterprise_project_restore_stage_upload((array)($_FILES['backup_zip'] ?? []));
            $previewToken = (string)$staged['token'];
            header('Location: restore.php?token=' . rawurlencode($previewToken));
            exit;
        }

        if ($action === 'discard') {
            if ($previewToken !== '' && isset($_SESSION['project_restore_previews'][$previewToken])) {
                $entry = $_SESSION['project_restore_previews'][$previewToken];
                if (is_array($entry) && is_file((string)($entry['path'] ?? ''))) {
                    @unlink((string)$entry['path']);
                }
                unset($_SESSION['project_restore_previews'][$previewToken]);
            }
            header('Location: restore.php');
            exit;
        }

        if ($action === 'restore') {
            $previewState = enterprise_project_restore_resolve_preview($previewToken);
            $preview = $previewState['preview'];
            $projectMeta = is_array($preview['project'] ?? null) ? $preview['project'] : [];
            $manifestProject = is_array($preview['manifest']['project'] ?? null) ? $preview['manifest']['project'] : [];
            $sourceDatabase = trim((string)($manifestProject['database_name'] ?? $projectMeta['database_name'] ?? ''));
            $backupDriver = strtolower(trim((string)($preview['database_driver'] ?? $manifestProject['database_driver'] ?? $projectMeta['database_driver'] ?? 'mysql')));
            if ($backupDriver === 'mariadb') $backupDriver = 'mysql';

            $form['name'] = trim((string)($_POST['name'] ?? ''));
            $form['slug'] = trim((string)($_POST['slug'] ?? ''));
            $form['database_name'] = trim((string)($_POST['database_name'] ?? ''));
            $form['status'] = trim((string)($_POST['status'] ?? 'active'));
            $form['database_strategy'] = trim((string)($_POST['database_strategy'] ?? 'auto'));
            $form['replace_confirm'] = trim((string)($_POST['replace_confirm'] ?? ''));

            if ($form['name'] === '') {
                throw new RuntimeException('Projektname ist erforderlich.');
            }
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,158}$/', $form['slug']) !== 1) {
                throw new RuntimeException('Der technische Slug ist ungültig.');
            }
            if (!enterprise_project_restore_valid_database_name($form['database_name'])) {
                throw new RuntimeException('Der Ziel-Projektspeichername ist ungültig.');
            }
            if (!in_array($form['status'], ['active', 'inactive'], true)) {
                throw new RuntimeException('Ungültiger Projektstatus.');
            }

            $duplicate = $pdo->prepare('SELECT id, name, slug, database_name FROM projects WHERE slug = ? OR database_name = ? LIMIT 1');
            $duplicate->execute([$form['slug'], $form['database_name']]);
            if ($existing = $duplicate->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Slug oder Zieldatenbank wird bereits vom Projekt „' . (string)$existing['name'] . '“ verwendet.');
            }

            if ($backupDriver === 'csv') {
                $dbResult = enterprise_project_restore_csv_store(
                    $env,
                    $sourceDatabase,
                    $form['database_name'],
                    (string)$previewState['path'],
                    $form['database_strategy'],
                    $form['replace_confirm']
                );
            } elseif ($backupDriver === 'sqlite') {
                $dbResult = enterprise_project_restore_sqlite_store(
                    $env,
                    $sourceDatabase,
                    $form['database_name'],
                    (string)$previewState['path'],
                    $form['database_strategy'],
                    $form['replace_confirm']
                );
            } elseif ($backupDriver === 'oracle') {
                $dbResult = enterprise_project_restore_oracle_store($env,$sourceDatabase,$form['database_name'],(string)$preview['database_sql'],$form['database_strategy'],$form['replace_confirm']);
            } elseif ($backupDriver === 'pgsql') {
                $dbResult = enterprise_project_restore_pgsql_store(
                    $env,
                    $sourceDatabase,
                    $form['database_name'],
                    (string)$preview['database_sql'],
                    $form['database_strategy'],
                    $form['replace_confirm']
                );
            } elseif ($backupDriver === 'mssql') {
                $dbResult = enterprise_project_restore_mssql_store(
                    $env,
                    $sourceDatabase,
                    $form['database_name'],
                    (string)$preview['database_sql'],
                    $form['database_strategy'],
                    $form['replace_confirm']
                );
            } else {
                $dbResult = enterprise_project_restore_database(
                    $env,
                    $sourceDatabase,
                    $form['database_name'],
                    (string)$preview['database_sql'],
                    $form['database_strategy'],
                    $form['replace_confirm']
                );
            }

            $description = array_key_exists('description', $projectMeta) ? (string)$projectMeta['description'] : null;
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO projects(name, slug, product_type, database_driver, database_name, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $form['name'],
                    $form['slug'],
                    (string)($projectMeta['product_type'] ?? $manifestProject['product_type'] ?? 'dataform'),
                    $backupDriver,
                    $form['database_name'],
                    $description,
                    $form['status'],
                ]);
                $restoredProjectId = (int)$pdo->lastInsertId();
                $storageKey = enterprise_project_storage_assign($pdo, $restoredProjectId, $form['slug']);
                $restoredProject = enterprise_project_storage_fetch($pdo, $restoredProjectId);
                enterprise_project_storage_provision($restoredProject, $env);
                $restoredDocumentFiles = enterprise_project_restore_documents((string)$previewState['path'], $restoredProject);
            } catch (Throwable $registrationError) {
                if (isset($restoredProject) && is_array($restoredProject)) {
                    try { enterprise_project_storage_remove_tree(enterprise_project_storage_root($restoredProject)); } catch (Throwable) {}
                }
                if (isset($restoredProjectId) && $restoredProjectId > 0) {
                    try { $cleanup=$pdo->prepare('DELETE FROM projects WHERE id=?'); $cleanup->execute([$restoredProjectId]); } catch (Throwable) {}
                }
                if (($dbResult['database_restored'] ?? false) && !($dbResult['database_existed_before'] ?? false)) {
                    try { enterprise_project_store_delete($env, $form['database_name'], $backupDriver); } catch (Throwable) {}
                }
                throw new RuntimeException('Die Datenbank wurde verarbeitet, aber das Projekt bzw. sein Storage konnte nicht registriert werden: ' . $registrationError->getMessage(), 0, $registrationError);
            }

            enterprise_audit($pdo, (int)$user['id'], 'project.restore', 'project', (string)$restoredProjectId, [
                'backup_filename' => (string)$preview['filename'],
                'backup_sha256' => (string)$preview['sha256'],
                'source_project_id' => (int)($projectMeta['id'] ?? $manifestProject['id'] ?? 0),
                'source_name' => (string)($projectMeta['name'] ?? $manifestProject['name'] ?? ''),
                'source_slug' => (string)($projectMeta['slug'] ?? $manifestProject['slug'] ?? ''),
                'source_database' => $sourceDatabase,
                'database_driver' => $backupDriver,
                'restored_name' => $form['name'],
                'restored_slug' => $form['slug'],
                'restored_database' => $form['database_name'],
                'database_strategy' => (string)$dbResult['strategy'],
                'database_restored' => (bool)$dbResult['database_restored'],
                'database_reused' => (bool)$dbResult['database_reused'],
                'sql_statements' => (int)$dbResult['statements'],
                'restored_document_files' => (int)($restoredDocumentFiles ?? 0),
                'storage_key' => (string)($storageKey ?? ''),
            ]);
            enterprise_event_dispatch('project.restored', [
                'project_id' => $restoredProjectId,
                'name' => $form['name'],
                'slug' => $form['slug'],
                'database_name' => $form['database_name'],
                'database_driver' => $backupDriver,
                'database_strategy' => (string)$dbResult['strategy'],
                'backup_sha256' => (string)$preview['sha256'],
            ], ['user_id' => (int)$user['id']]);

            if (isset($_SESSION['project_restore_previews'][$previewToken])) {
                $entry = $_SESSION['project_restore_previews'][$previewToken];
                if (is_array($entry) && is_file((string)($entry['path'] ?? ''))) {
                    @unlink((string)$entry['path']);
                }
                unset($_SESSION['project_restore_previews'][$previewToken]);
            }

            $_SESSION['project_flash'] = [
                'type' => 'success',
                'message' => 'Projekt „' . $form['name'] . '“ wurde aus der Sicherung wiederhergestellt.',
            ];
            header('Location: view.php?id=' . $restoredProjectId);
            exit;
        }
    }

    if ($previewToken !== '') {
        $previewState = enterprise_project_restore_resolve_preview($previewToken);
        $preview = $previewState['preview'];
        $projectMeta = is_array($preview['project'] ?? null) ? $preview['project'] : [];
        $manifestProject = is_array($preview['manifest']['project'] ?? null) ? $preview['manifest']['project'] : [];
        $form['name'] = trim((string)($projectMeta['name'] ?? $manifestProject['name'] ?? ''));
        $form['slug'] = trim((string)($projectMeta['slug'] ?? $manifestProject['slug'] ?? ''));
        if ($form['slug'] === '') {
            $form['slug'] = enterprise_project_restore_slug_from_name($form['name']);
        }
        $form['database_name'] = trim((string)($manifestProject['database_name'] ?? $projectMeta['database_name'] ?? ''));
        $form['status'] = in_array((string)($projectMeta['status'] ?? 'active'), ['active', 'inactive'], true)
            ? (string)$projectMeta['status'] : 'active';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    // If a restore POST failed, keep the submitted values instead of resetting
    // them from the backup metadata below.
    if ($preview === null && $previewToken !== '') {
        try {
            $previewState = enterprise_project_restore_resolve_preview($previewToken);
            $preview = $previewState['preview'];
        } catch (Throwable) {
        }
    }
}

ob_start();
render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../dashboard.php'],
    ['label' => 'Projekte', 'href' => 'index.php'],
    ['label' => 'Projekt wiederherstellen', 'href' => ''],
]);
?>
<section class="hero">
    <span class="badge">RESTORE · HF73</span>
    <h1>Projekt aus Sicherung wiederherstellen</h1>
    <p>Laden Sie eine mit dem Enterprise Manager erzeugte Projekt-ZIP-Sicherung hoch. Das Archiv wird zuerst geprüft; erst danach entscheiden Sie, wie Projektregistrierung und physischer Projektdatenspeicher wiederhergestellt werden.</p>
</section>

<?php if ($error !== ''): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($preview === null): ?>
<section class="card">
    <h2>1. Projektsicherung auswählen</h2>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">
        <input type="hidden" name="action" value="preview">
        <label class="form-span">
            Projekt-ZIP-Sicherung
            <input type="file" name="backup_zip" accept=".zip,application/zip" required>
            <small>Erwartet wird das ZIP aus „Projekt vor dem Löschen als Download sichern“ mit <code>manifest.json</code> und <code>project.json</code>; MySQL-Sicherungen enthalten <code>database.sql</code>, PostgreSQL-Sicherungen <code>postgresql-data/database.sql</code>, CSV-Sicherungen <code>csv-data/</code> und SQLite-Sicherungen <code>sqlite-data/database.sqlite</code>.</small>
        </label>
        <div class="form-span button-row">
            <button class="button" data-crud="read" type="submit">Sicherung prüfen</button>
            <a class="button secondary" href="index.php">Abbrechen</a>
        </div>
    </form>
</section>
<?php else: ?>
<?php
$manifest = is_array($preview['manifest'] ?? null) ? $preview['manifest'] : [];
$manifestProject = is_array($manifest['project'] ?? null) ? $manifest['project'] : [];
$dbStats = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
$sourceDb = (string)($manifestProject['database_name'] ?? '');
?>
<section class="card">
    <h2>1. Sicherung geprüft</h2>
    <dl class="status-list">
        <div><dt>Datei</dt><dd><code><?= e((string)$preview['filename']) ?></code></dd></div>
        <div><dt>SHA-256</dt><dd><code><?= e((string)$preview['sha256']) ?></code></dd></div>
        <div><dt>Projekt aus Sicherung</dt><dd><?= e((string)($manifestProject['name'] ?? $form['name'])) ?></dd></div>
        <div><dt>Treiber</dt><dd><code><?= e(strtoupper((string)($preview['database_driver'] ?? $manifestProject['database_driver'] ?? 'mysql'))) ?></code></dd></div>
        <div><dt>Ursprünglicher Projektspeicher</dt><dd><code><?= e($sourceDb) ?></code></dd></div>
        <div><dt>Tabellen</dt><dd><?= (int)($dbStats['tables'] ?? 0) ?></dd></div>
        <div><dt>Views</dt><dd><?= (int)($dbStats['views'] ?? 0) ?></dd></div>
        <div><dt>Datensätze</dt><dd><?= (int)($dbStats['rows'] ?? 0) ?></dd></div>
        <div><dt>Trigger</dt><dd><?= (int)($dbStats['triggers'] ?? 0) ?></dd></div>
    </dl>
</section>

<section class="card">
    <h2>2. Restore-Ziel festlegen</h2>
    <form method="post" class="form-grid" id="project-restore-form">
        <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="restore_token" value="<?= e($previewToken) ?>">

        <label>
            Projektname
            <input name="name" required value="<?= e($form['name']) ?>">
            <small>Kann beim Restore geändert werden.</small>
        </label>
        <label>
            Technischer Slug
            <input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,158}" value="<?= e($form['slug']) ?>">
            <small>Muss im Enterprise Manager eindeutig sein.</small>
        </label>
        <label>
            Ziel-Projektspeicher
            <input name="database_name" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}" value="<?= e($form['database_name']) ?>">
            <small>Standardmäßig wird der Speichername aus der Sicherung verwendet.</small>
        </label>
        <label>
            Projektstatus
            <select name="status">
                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>aktiv</option>
                <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>inaktiv</option>
            </select>
        </label>

        <label class="form-span">
            Verhalten für den Projektdatenspeicher
            <select name="database_strategy" id="database_strategy">
                <option value="auto" <?= $form['database_strategy'] === 'auto' ? 'selected' : '' ?>>Automatisch – vorhandene Originaldatenbank weiterverwenden, sonst aus ZIP wiederherstellen</option>
                <option value="reuse" <?= $form['database_strategy'] === 'reuse' ? 'selected' : '' ?>>Vorhandenen Projektspeicher verwenden – Backup-Daten nicht einspielen</option>
                <option value="restore" <?= $form['database_strategy'] === 'restore' ? 'selected' : '' ?>>Projektspeicher aus Sicherung wiederherstellen – Ziel darf noch nicht existieren</option>
                <option value="replace" <?= $form['database_strategy'] === 'replace' ? 'selected' : '' ?>>Vorhandenen Projektspeicher endgültig ersetzen</option>
            </select>
            <small><strong>Empfehlung:</strong> „Automatisch“. Wurde beim Löschen nur die Projektregistrierung entfernt, bleibt der vorhandene Projektspeicher unverändert. Wurde er mitgelöscht, wird er aus dem ZIP neu aufgebaut.</small>
        </label>

        <div class="form-span notice error" id="replace-warning" hidden>
            <strong>Achtung – destruktiver Restore:</strong> Die vorhandene Ziel-Projektspeicher wird vor dem Restore vollständig gelöscht. Dieser Schritt kann nicht rückgängig gemacht werden.
            <label style="margin-top:.75rem">Speichername zur Bestätigung
                <input name="replace_confirm" value="<?= e($form['replace_confirm']) ?>" autocomplete="off" placeholder="<?= e($form['database_name']) ?>">
            </label>
        </div>

        <div class="form-span notice">
            <strong>Sicherheitslogik:</strong> System- und Enterprise-Speicher sind als Restore-Ziel gesperrt. Slug und Speichername dürfen keinem anderen registrierten Projekt gehören. Bei einem neuen Ziel wird ein unvollständiger Projektspeicher nach Restore-Fehlern automatisch entfernt.
        </div>

        <div class="form-span button-row">
            <button class="button" data-crud="create" type="submit">Projekt wiederherstellen</button>
            <button class="button secondary" type="submit" name="action" value="discard" formnovalidate>Vorschau verwerfen</button>
            <a class="button secondary" href="index.php">Abbrechen</a>
        </div>
    </form>
</section>
<script>
(() => {
    const form = document.getElementById('project-restore-form');
    if (!form) return;
    const strategy = form.querySelector('#database_strategy');
    const warning = form.querySelector('#replace-warning');
    const confirmInput = warning ? warning.querySelector('input[name="replace_confirm"]') : null;
    const sync = () => {
        const replace = strategy && strategy.value === 'replace';
        if (warning) warning.hidden = !replace;
        if (confirmInput) confirmInput.required = !!replace;
    };
    strategy?.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Projekt wiederherstellen',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projekt-Restore',
        'location' => 'Enterprise → Projekte → Projekt wiederherstellen',
        'short' => 'Stellt eine zuvor heruntergeladene easyIT-Projektsicherung wieder bereit.',
        'goal' => 'Projektregistrierung und – falls erforderlich – den physischen MySQL-, PostgreSQL-, CSV- oder SQLite-Projektdatenspeicher aus dem ZIP wiederherstellen.',
        'steps' => [
            'Projekt-ZIP auswählen und zuerst prüfen.',
            'Projektname, Slug und Ziel-Projektspeicher kontrollieren.',
            'Speicherstrategie festlegen.',
            'Projekt wiederherstellen.',
        ],
        'tips' => [
            '„Automatisch“ verwendet einen noch vorhandenen Originalspeicher weiter und stellt ihn nur dann aus dem Backup wieder her, wenn er fehlt.',
            '„Vorhandenen Projektspeicher ersetzen“ löscht den Zielspeicher unwiderruflich und verlangt deshalb die exakte Bestätigung des Speichernamens.',
            'Die Sicherungsdatei wird ausschließlich in einem geschützten temporären Bereich verarbeitet.',
        ],
    ],
]);
