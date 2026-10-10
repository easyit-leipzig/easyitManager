<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once dirname(__DIR__, 2) . '/system/app/project_store.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user, 'projects.update');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
enterprise_require_project_scope($user,$id,true);
$error = '';
$project = null;
$storeExists = false;
$driver = '';
$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');

try {
    if ($id < 1) throw new RuntimeException('Ungültige Projekt-ID.');
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $st = $pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $project = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($project)) throw new RuntimeException('Projekt wurde nicht gefunden.');

    $driver = enterprise_project_store_driver($env, $project);
    $database = trim((string)($project['database_name'] ?? ''));
    if ($database === '') throw new RuntimeException('Der Projektdatenspeichername fehlt.');
    $storeExists = enterprise_project_store_exists_for_project($env, $project);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));
        if ($storeExists) {
            throw new RuntimeException('Der Projektdatenspeicher ist bereits vorhanden. Ein Neuaufbau ist nur zulässig, wenn der physische Speicher fehlt.');
        }

        $creationAttempted = false;
        try {
            $creationAttempted = true;
            $projectPdo = enterprise_project_store_create_for_project($env, $project);
            $schemaResults = enterprise_project_store_install_schema($projectPdo, dirname(__DIR__, 2) . '/installer/schema/project');
            if ($driver === 'csv') {
                $sourceId = enterprise_project_store_ensure_default_csv_source_for_project($projectPdo, $env, $project);
                $schemaResults[] = 'DEFAULT CSV DATA SOURCE #' . $sourceId;
            } elseif ($driver === 'sqlite') {
                $sourceId = enterprise_project_store_ensure_default_sqlite_source_for_project($projectPdo, $env, $project);
                $schemaResults[] = 'DEFAULT SQLITE DATA SOURCE #' . $sourceId;
            } elseif ($driver === 'pgsql') {
                $sourceId = enterprise_project_store_ensure_default_pgsql_source($projectPdo, $env, $database);
                $schemaResults[] = 'DEFAULT POSTGRESQL DATA SOURCE #' . $sourceId;
            } elseif ($driver === 'oracle') {
                $sourceId = enterprise_project_store_ensure_default_oracle_source($projectPdo, $env, $database);
                $schemaResults[] = 'DEFAULT ORACLE DATA SOURCE #' . $sourceId;
            } elseif ($driver === 'mssql') {
                $sourceId = enterprise_project_store_ensure_default_mssql_source($projectPdo, $env, $database);
                $schemaResults[] = 'DEFAULT MSSQL DATA SOURCE #' . $sourceId;
            }

            if (!enterprise_project_store_exists_for_project($env, $project)) {
                throw new RuntimeException('Der Projektdatenspeicher konnte nach dem Neuaufbau nicht verifiziert werden.');
            }

            enterprise_audit($pdo, (int)$user['id'], 'project.store.rebuild', 'project', (string)$id, [
                'database_driver' => $driver,
                'database_name' => $database,
                'schema' => $driver === 'pgsql' ? enterprise_project_store_pgsql_schema($env) : null,
                'migrations' => $schemaResults,
            ]);
            enterprise_event_dispatch('project.store.rebuilt', [
                'project_id' => $id,
                'database_driver' => $driver,
                'database_name' => $database,
            ], ['user_id' => (int)$user['id']]);

            $_SESSION['project_flash'] = [
                'type' => 'success',
                'message' => 'Der fehlende Projektdatenspeicher für „' . (string)$project['name'] . '“ wurde neu aufgebaut und verifiziert.',
            ];
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            if ($creationAttempted) {
                try { enterprise_project_store_delete_for_project($env, $project); } catch (Throwable) {}
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../dashboard.php'],
    ['label' => 'Projekte', 'href' => 'index.php'],
    ['label' => 'Projektspeicher neu aufbauen', 'href' => ''],
]);
?>
<section class="hero">
    <span class="badge">Projekt-Speicher</span>
    <h1>Projektspeicher neu aufbauen</h1>
    <p>Diese Funktion ist für den Fall vorgesehen, dass die physische Projektdatenbank bzw. das Projektschema gelöscht wurde, die Projektregistrierung im Administrationsspeicher aber erhalten ist.</p>
</section>
<?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (is_array($project)): ?>
<section class="card">
    <h2><?= e((string)$project['name']) ?></h2>
    <dl class="status-list">
        <div><dt>Treiber</dt><dd><?= e(strtoupper($driver)) ?></dd></div>
        <div><dt>Datenbank / Speicher</dt><dd><code><?= e((string)$project['database_name']) ?></code></dd></div>
        <?php if ($driver === 'pgsql'): ?><div><dt>PostgreSQL-Schema</dt><dd><code><?= e(enterprise_project_store_pgsql_schema($env)) ?></code></dd></div><?php endif; ?>
        <div><dt>Physischer Speicher</dt><dd><?= $storeExists ? 'vorhanden' : 'fehlt' ?></dd></div>
    </dl>

    <?php if ($storeExists): ?>
        <div class="notice">Der Projektdatenspeicher ist vorhanden. Es wird nichts verändert.</div>
        <div class="button-row"><a class="button secondary" href="index.php">Zur Projektliste</a></div>
    <?php else: ?>
        <div class="notice"><strong>Sicherer Neuaufbau:</strong> Es wird ausschließlich ein fehlender Speicher neu erzeugt und das Projektschema installiert. Ein vorhandener Speicher wird niemals überschrieben.</div>
        <form method="post" class="form-grid single-column">
            <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="button-row">
                <button class="button" <?= easyit_button_attributes('neu', 'project_storage') ?> type="submit">Projektspeicher neu aufbauen</button>
                <a class="button secondary" href="index.php">Abbrechen</a>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Projektspeicher neu aufbauen',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projektspeicher neu aufbauen',
        'location' => 'Enterprise → Projekte → Projektspeicher neu aufbauen',
        'short' => 'Erzeugt einen fehlenden physischen Projektspeicher erneut, ohne die Projektregistrierung zu löschen.',
        'goal' => 'Nach einer absichtlich gelöschten Projekt-DB bzw. einem gelöschten Schema einen sauberen Neuaufbau durchführen.',
        'tips' => ['Vorhandene Speicher werden nicht überschrieben.', 'Bei PostgreSQL erfolgt der Neuaufbau über die Maintenance-Datenbank; Datenbank und Schema werden anschließend verifiziert.'],
    ],
]);
