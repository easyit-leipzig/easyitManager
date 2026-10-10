<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require dirname(__DIR__, 2) . '/system/app/project_backup.php';
require_once dirname(__DIR__, 2) . '/system/app/project_store.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.delete');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
enterprise_require_project_scope($user,$id,true);
$error = '';
$project = null;
$deleteDatabaseRequested = (string)($_POST['delete_database'] ?? '') === '1';
$backupRequested = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['create_backup'] ?? '') === '1'
    : true;
$backupInfo = null;

function enterprise_project_delete_valid_db_name(string $name): bool
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/', $name) === 1;
}

function enterprise_project_delete_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function enterprise_project_delete_server(array $env): PDO
{
    foreach (['PROJECT_DB_HOST', 'PROJECT_DB_PORT', 'PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') {
            throw new RuntimeException("Projekt-DB-Konfiguration {$key} fehlt. Die Projektdatenspeicher wurde nicht gelöscht.");
        }
    }

    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function enterprise_project_delete_database_exists(PDO $server, string $database): bool
{
    $stmt = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->execute([$database]);
    return $stmt->fetchColumn() !== false;
}

function enterprise_project_delete_assert_database_safe(array $env, array $project, PDO $adminPdo): void
{
    $database = trim((string)($project['database_name'] ?? ''));
    if (!enterprise_project_delete_valid_db_name($database)) {
        throw new RuntimeException('Der hinterlegte Projektdatenspeichername ist ungültig. Die Datenbank wurde nicht gelöscht.');
    }

    $driver=enterprise_project_store_driver($env,$project);
    if (!in_array($driver,['mysql','csv','sqlite','pgsql'],true)) {
        throw new RuntimeException('Die automatische Löschung ist für diesen Projekttreiber noch nicht freigegeben: '.$driver);
    }

    $protected = $driver==='mysql' ? ['mysql', 'information_schema', 'performance_schema', 'sys'] : ($driver==='pgsql' ? ['postgres','template0','template1'] : []);
    $adminDatabase = trim((string)($env['ADMIN_DB_DATABASE'] ?? ''));
    if ($adminDatabase !== '') {
        $protected[] = strtolower($adminDatabase);
    }

    if (in_array(strtolower($database), array_unique($protected), true)) {
        throw new RuntimeException('Die Datenbank `' . $database . '` ist eine geschützte System-/Enterprise-Datenbank und darf nicht gelöscht werden.');
    }

    $shared = $adminPdo->prepare('SELECT id, name FROM projects WHERE database_name = ? AND database_driver = ? AND id <> ? LIMIT 1');
    $shared->execute([$database, $driver, (int)$project['id']]);
    if ($other = $shared->fetch()) {
        throw new RuntimeException(
            'Die Datenbank `' . $database . '` wird auch vom Projekt „' . (string)$other['name'] . '“ verwendet und darf deshalb nicht gelöscht werden.'
        );
    }
}

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);

    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Projekt nicht gefunden.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));
        $confirmName = trim((string)($_POST['confirm_name'] ?? ''));
        if (!hash_equals((string)$project['name'], $confirmName)) {
            throw new RuntimeException('Zur Bestätigung muss der Projektname exakt eingegeben werden.');
        }

        $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
        $databaseName = (string)$project['database_name'];
        $databaseDeleted = false;
        $databaseAlreadyMissing = false;
        $projectSnapshotUpdatedAt = (string)($project['updated_at'] ?? '');
        $projectSnapshotName = (string)($project['name'] ?? '');
        $projectSnapshotDatabase = (string)($project['database_name'] ?? '');

        if ($backupRequested) {
            @set_time_limit(0);
            $backupInfo = enterprise_project_backup_create($env, $project, (int)$user['id']);
            enterprise_audit($pdo, (int)$user['id'], 'project.backup', 'project', (string)$id, [
                'name' => (string)$project['name'],
                'database_name' => $databaseName,
                'backup_filename' => (string)$backupInfo['filename'],
                'backup_sha256' => (string)$backupInfo['sha256'],
                'backup_size' => (int)$backupInfo['size'],
                'backup_expires_at' => (int)$backupInfo['expires_at'],
                'backup_database_stats' => $backupInfo['stats'],
            ]);
            enterprise_event_dispatch('project.backup.created', [
                'project_id' => $id,
                'name' => (string)$project['name'],
                'database_name' => $databaseName,
                'backup_filename' => (string)$backupInfo['filename'],
                'backup_sha256' => (string)$backupInfo['sha256'],
            ], ['user_id' => (int)$user['id']]);
        }

        $context = [
            'name' => (string)$project['name'],
            'slug' => (string)$project['slug'],
            'product_type' => (string)$project['product_type'],
            'database_name' => $databaseName,
            'database_delete_requested' => $deleteDatabaseRequested,
            'database_deleted' => false,
            'database_already_missing' => false,
            'backup_requested' => $backupRequested,
            'backup_created' => is_array($backupInfo),
            'backup_filename' => is_array($backupInfo) ? (string)$backupInfo['filename'] : null,
            'backup_sha256' => is_array($backupInfo) ? (string)$backupInfo['sha256'] : null,
        ];

        $pdo->beginTransaction();
        try {
            // Projektzeile während des destruktiven Vorgangs sperren. Dadurch kann die
            // Datenbankbindung nicht parallel geändert oder entfernt werden.
            $lock = $pdo->prepare('SELECT * FROM projects WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $lockedProject = $lock->fetch();
            if (!$lockedProject) {
                throw new RuntimeException('Projekt wurde während des Löschvorgangs entfernt.');
            }
            $project = $lockedProject;
            $databaseName = (string)$project['database_name'];
            $context['database_name'] = $databaseName;

            if ($backupRequested && (
                (string)($project['name'] ?? '') !== $projectSnapshotName
                || (string)($project['database_name'] ?? '') !== $projectSnapshotDatabase
                || (string)($project['updated_at'] ?? '') !== $projectSnapshotUpdatedAt
            )) {
                throw new RuntimeException('Das Projekt wurde während der Sicherung geändert. Die Sicherungsdatei bleibt als Download verfügbar, das Projekt wurde jedoch nicht gelöscht. Bitte öffnen Sie den Löschdialog erneut.');
            }

            if ($deleteDatabaseRequested) {
                enterprise_project_delete_assert_database_safe($env, $project, $pdo);
                $projectDriver=enterprise_project_store_driver($env,$project);
                if (enterprise_project_store_exists_for_project($env,$project)) {
                    try {
                        enterprise_project_store_delete_for_project($env,$project);
                    } catch (Throwable $dropError) {
                        throw new RuntimeException(
                            'Der Projektdatenspeicher `' . $databaseName . '` konnte nicht gelöscht werden. Die Projektregistrierung bleibt erhalten. Ursache: ' . $dropError->getMessage(),
                            0,
                            $dropError
                        );
                    }
                    if (enterprise_project_store_exists_for_project($env,$project)) {
                        throw new RuntimeException('Der Projektdatenspeicher `' . $databaseName . '` existiert nach dem Löschvorgang weiterhin.');
                    }
                    $databaseDeleted = true;
                } else {
                    $databaseAlreadyMissing = true;
                }

                $context['database_deleted'] = $databaseDeleted;
                $context['database_already_missing'] = $databaseAlreadyMissing;
            }

            $delete = $pdo->prepare('DELETE FROM projects WHERE id = ?');
            $delete->execute([$id]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Projekt konnte nicht aus der Enterprise-Verwaltung entfernt werden.');
            }

            enterprise_audit($pdo, (int)$user['id'], 'project.delete', 'project', (string)$id, $context);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ((int)($_SESSION['active_project_id'] ?? 0) === $id) {
            unset($_SESSION['active_project_id']);
        }

        enterprise_event_dispatch('project.deleted', [
            'project_id' => $id,
            'name' => (string)$project['name'],
            'database_name' => $databaseName,
            'database_delete_requested' => $deleteDatabaseRequested,
            'database_deleted' => $databaseDeleted,
            'database_already_missing' => $databaseAlreadyMissing,
            'backup_requested' => $backupRequested,
            'backup_created' => is_array($backupInfo),
            'backup_filename' => is_array($backupInfo) ? (string)$backupInfo['filename'] : null,
            'backup_sha256' => is_array($backupInfo) ? (string)$backupInfo['sha256'] : null,
        ], ['user_id' => (int)$user['id']]);

        if ($deleteDatabaseRequested && $databaseDeleted) {
            $message = 'Projekt „' . (string)$project['name'] . '“ und die Projektdatenspeicher `' . $databaseName . '` wurden endgültig gelöscht.';
        } elseif ($deleteDatabaseRequested && $databaseAlreadyMissing) {
            $message = 'Projekt „' . (string)$project['name'] . '“ wurde entfernt. Die gewählte Projektdatenspeicher `' . $databaseName . '` war bereits nicht mehr vorhanden.';
        } else {
            $message = 'Projekt „' . (string)$project['name'] . '“ wurde aus dem Enterprise Manager entfernt. Die Datenbank `' . $databaseName . '` wurde nicht gelöscht und bleibt erhalten.';
        }

        $_SESSION['project_flash'] = [
            'type' => 'success',
            'message' => $message,
            'backup_token' => is_array($backupInfo) ? (string)$backupInfo['token'] : '',
            'backup_filename' => is_array($backupInfo) ? (string)$backupInfo['filename'] : '',
            'backup_sha256' => is_array($backupInfo) ? (string)$backupInfo['sha256'] : '',
            'backup_expires_at' => is_array($backupInfo) ? (int)$backupInfo['expires_at'] : 0,
        ];
        header('Location: index.php');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../dashboard.php'],
    ['label' => 'Projekte', 'href' => 'index.php'],
    ['label' => 'Projekt löschen', 'href' => ''],
]);
?>
<section class="hero">
    <span class="badge">DELETE · HF72</span>
    <h1>Projekt löschen</h1>
    <p>Sie entscheiden ausdrücklich, ob vor dem Löschen eine vollständige Projektsicherung als Download bereitgestellt und ob zusätzlich die physischen Projektdatenspeicher gelöscht wird.</p>
</section>

<?php if ($error !== ''): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if (is_array($backupInfo)): ?>
    <div class="notice success" role="status">
        <strong>Projektsicherung wurde erstellt.</strong>
        <a class="button secondary" data-crud="read" href="backup-download.php?token=<?= e((string)$backupInfo['token']) ?>">Sicherung herunterladen</a>
        <small>Datei: <code><?= e((string)$backupInfo['filename']) ?></code> · SHA-256: <code><?= e((string)$backupInfo['sha256']) ?></code></small>
    </div>
<?php endif; ?>

<?php if ($project): ?>
<section class="card">
    <h2><?= e((string)$project['name']) ?></h2>
    <dl class="status-list">
        <div><dt>Projekt-ID</dt><dd><?= (int)$project['id'] ?></dd></div>
        <div><dt>Slug</dt><dd><code><?= e((string)$project['slug']) ?></code></dd></div>
        <div><dt>Projektdatenspeicher</dt><dd><code><?= e((string)$project['database_name']) ?></code></dd></div>
    </dl>

    <div class="notice">
        <strong>Sicheres Standardverhalten:</strong> Eine Projektsicherung ist vorausgewählt. Die Datenbanklöschung bleibt deaktiviert; ohne Auswahl gilt: Die Datenbank bleibt erhalten. Wird die Sicherung angefordert und kann das Archiv nicht vollständig erzeugt werden, wird das Projekt nicht gelöscht.
    </div>

    <form method="post" class="form-grid single-column" id="project-delete-form">
        <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <label class="project-delete-backup-option">
            <span>
                <input type="checkbox" name="create_backup" id="create_backup" value="1" <?= $backupRequested ? 'checked' : '' ?>>
                <strong>Projekt vor dem Löschen als Download sichern</strong>
            </span>
            <small>Erzeugt ein ZIP mit <code>project.json</code>, vollständigem Projektdatenspeicher (MySQL-SQL, CSV-Dateien oder SQLite-Datenbankdatei) sowie Prüfsumme. Der Downloadlink bleibt 24 Stunden verfügbar. Schlägt die Sicherung fehl, wird bei aktivierter Option nichts gelöscht.</small>
        </label>

        <div class="notice" id="project-backup-info" <?= $backupRequested ? '' : 'hidden' ?>>
            <strong>Sicherung vor Löschung:</strong> Das Archiv wird zuerst vollständig erzeugt und geprüft. Erst danach beginnt der eigentliche Löschvorgang.
        </div>

        <label>
            Zur Bestätigung den Projektnamen exakt eingeben
            <input name="confirm_name" required autocomplete="off" placeholder="<?= e((string)$project['name']) ?>" value="<?= e((string)($_POST['confirm_name'] ?? '')) ?>">
        </label>

        <label class="project-delete-db-option">
            <span>
                <input type="checkbox" name="delete_database" id="delete_database" value="1" <?= $deleteDatabaseRequested ? 'checked' : '' ?>>
                <strong>Projektdatenspeicher ebenfalls endgültig löschen</strong>
            </span>
            <small>Standardmäßig deaktiviert. Ohne Haken bleibt <code><?= e((string)$project['database_name']) ?></code> vollständig bestehen.</small>
        </label>

        <div class="notice error" id="database-delete-warning" <?= $deleteDatabaseRequested ? '' : 'hidden' ?>>
            <strong>ACHTUNG – endgültige Datenlöschung:</strong>
            Die Datenbank <code><?= e((string)$project['database_name']) ?></code> einschließlich aller Tabellen, DataForms und Datensätze wird unwiderruflich gelöscht. Diese Aktion kann nicht rückgängig gemacht werden.
        </div>

        <div class="button-row">
            <button class="button" data-crud="delete" type="submit">Projekt löschen</button>
            <a class="button secondary" href="index.php">Abbrechen</a>
        </div>
    </form>
</section>

<script>
(() => {
    const databaseCheckbox = document.getElementById('delete_database');
    const databaseWarning = document.getElementById('database-delete-warning');
    const backupCheckbox = document.getElementById('create_backup');
    const backupInfo = document.getElementById('project-backup-info');

    const syncDatabase = () => {
        if (databaseCheckbox && databaseWarning) databaseWarning.hidden = !databaseCheckbox.checked;
    };
    const syncBackup = () => {
        if (backupCheckbox && backupInfo) backupInfo.hidden = !backupCheckbox.checked;
    };
    databaseCheckbox?.addEventListener('change', syncDatabase);
    backupCheckbox?.addEventListener('change', syncBackup);
    syncDatabase();
    syncBackup();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Projekt löschen',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projekt löschen',
        'location' => 'Enterprise → Projekte → Löschen',
        'short' => 'Sichert ein Projekt optional als Download und entfernt es wahlweise mit oder ohne physischen Projektdatenspeicher.',
        'goal' => 'Vor der Löschung optional eine vollständige Projektsicherung erzeugen und die Datenbank nur nach ausdrücklicher Auswahl endgültig entfernen.',
        'tips' => [
            'Die Projektsicherung ist standardmäßig aktiviert und wird vor jeder destruktiven Aktion vollständig erzeugt.',
            'Ohne Datenbank-Haken bleibt die Projektdatenspeicher erhalten.',
            'Die Datenbanklöschung ist bewusst standardmäßig deaktiviert.',
            'System-/Enterprise-Datenbanken sowie gemeinsam verwendete Datenbanken sind vor DROP DATABASE geschützt.',
            'Scheitert DROP DATABASE, bleibt die Projektregistrierung erhalten.',
            'Der Projektname muss als Löschbestätigung exakt eingegeben werden.',
        ],
    ],
]);
