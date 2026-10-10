<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.view');
$id = (int)($_GET['id'] ?? 0);
enterprise_require_project_scope($user,$id,false);
$error = '';
$project = null;
$flash = '';
$flashType = 'success';
$storeExists = null;
$storeDriver = '';
$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Projekt nicht gefunden.');
    }
    $storeDriver = enterprise_project_store_driver($env, $project);
    $storeExists = enterprise_project_store_exists_for_project($env, $project);
    $_SESSION['active_project_id'] = $id;
    if (isset($_SESSION['project_flash']) && is_array($_SESSION['project_flash'])) {
        $flash = trim((string)($_SESSION['project_flash']['message'] ?? ''));
        $flashType = (string)($_SESSION['project_flash']['type'] ?? 'success');
        unset($_SESSION['project_flash']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$productUrl = '';
if ($project) {
    $productUrl = '../products/load.php?project_id=' . (int)$project['id'] . '&product=' . rawurlencode((string)$project['product_type']);
}

ob_start();
?>
<?php render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Projekte','href'=>'index.php'],['label'=>(string)($project['name'] ?? 'Projekt'),'href'=>'']]); ?>
<?php if ($flash !== ''): ?>
    <div class="notice <?= $flashType === 'error' ? 'error' : 'success' ?>" role="status"><?= e($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
<?php else: ?>
    <section class="hero">
        <span class="badge"><?= e($project['product_type']) ?></span>
        <h1><?= e($project['name']) ?></h1>
        <p>Projekt-Dashboard und sicherer Übergang in die Produktoberfläche.</p>
        <div class="actions">
            <?php if ($productUrl !== ''): ?>
                <a class="button" href="<?= e($productUrl) ?>">Produktmodul öffnen</a>
            <?php else: ?>
                <span class="button secondary" aria-disabled="true">Produkt noch nicht verfügbar</span>
            <?php endif; ?>
            <?php if(enterprise_customer_has_module($user,'export') && enterprise_license_capability($user,'export.execute') && enterprise_can($user,'projects.backup') && enterprise_project_scope_manage($user,$id)): ?><form method="post" action="export.php" class="inline-form" style="display:inline"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$project['id'] ?>"><button class="button" <?= easyit_button_attributes('exportieren','project_package') ?> data-button-fixed="1" type="submit">HTML5-App exportieren</button></form><?php endif; ?>
            <?php if(enterprise_can($user,'projects.update') && enterprise_project_scope_manage($user,$id)): ?><a class="button" <?= easyit_button_attributes('bearbeiten','project') ?> data-crud="edit" href="edit.php?id=<?= (int)$project['id'] ?>">Bearbeiten</a><?php endif; ?>
            <?php if ($storeExists === false && enterprise_can($user,'projects.update') && enterprise_project_scope_manage($user,$id)): ?><a class="button" <?= easyit_button_attributes('neu','project_storage') ?> href="rebuild.php?id=<?= (int)$project['id'] ?>">Speicher neu aufbauen</a><?php endif; ?>
            <?php if(enterprise_can($user,'projects.delete') && enterprise_project_scope_manage($user,$id)): ?><a class="button" <?= easyit_button_attributes('loeschen','project') ?> data-crud="delete" href="delete.php?id=<?= (int)$project['id'] ?>">Projekt löschen</a><?php endif; ?>
            <a class="button secondary" href="index.php">Zur Projektliste</a>
        </div>
    </section>

    <div class="metric-grid">
        <div class="metric"><strong><?= e($project['status']) ?></strong><span>Status</span></div>
        <div class="metric"><strong><?= e($project['product_type']) ?></strong><span>Produkt</span></div>
        <div class="metric"><strong><?= e($project['database_driver']) ?></strong><span>Treiber</span></div>
    </div>

    <?php if ((string)$project['product_type'] === 'calendar' && $storeExists !== false && enterprise_customer_has_module($user,'calendar')): ?>
    <section class="card project-build-card"><h2>easyITCalendar einrichten</h2><p>Konfigurieren Sie Datenquelle, Datenpflege, Zuordnungen, Kalenderparameter und Auslieferung.</p><div class="actions"><a class="button" href="../../products/calendar/setup.php?project=<?= (int)$project['id'] ?>">Calendar Setup &amp; Datenadministration</a><a class="button secondary" href="../products/load.php?project_id=<?= (int)$project['id'] ?>&amp;product=calendar">Calendar öffnen</a></div></section>
    <?php endif; ?>

    <?php if ((string)$project['product_type'] === 'dataform' && $storeExists !== false && enterprise_customer_has_module($user,'dataform')): ?>
    <section class="card project-build-card">
        <h2>Tabellen und Formulare</h2>
        <p>Hier beginnen Sie den fachlichen Aufbau des Projekts. Der empfohlene Weg ist: zuerst eine Tabelle anlegen und daraus anschließend ein Formular erzeugen.</p>
        <div class="project-builder-grid">
            <a class="project-builder-tile" href="../../products/dataform/runtime.php?project=<?= (int)$project['id'] ?>&amp;section=tables&amp;table_source=system#new-table">
                <span class="project-builder-step">1</span>
                <strong>Tabelle anlegen</strong>
                <span>Tabellenname und Spalten definieren oder vorhandene Tabellen verwalten.</span>
            </a>
            <a class="project-builder-tile" href="../../products/dataform/runtime.php?project=<?= (int)$project['id'] ?>&amp;section=dataforms#new-dataform">
                <span class="project-builder-step">2</span>
                <strong>Formular anlegen</strong>
                <span>Ein Formular (DataForm) neu anlegen oder aus einer vorhandenen Projekttabelle erzeugen.</span>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Projektverbindung</h2>
        <dl class="status-list">
            <div><dt>Datenbank</dt><dd><code><?= e($project['database_name']) ?></code></dd></div>
            <?php if ($storeDriver === 'pgsql'): ?><div><dt>PostgreSQL-Schema</dt><dd><code><?= e(enterprise_project_store_pgsql_schema($env)) ?></code></dd></div><?php endif; ?>
            <div><dt>Physischer Speicher</dt><dd><?= $storeExists === false ? 'fehlt' : 'vorhanden' ?></dd></div>
            <div><dt>Interner Projektname</dt><dd><code><?= e($project['slug']) ?></code></dd></div>
            <div><dt>Storage-Key</dt><dd><code><?= e((string)($project['storage_key'] ?? '')) ?></code></dd></div>
            <div><dt>Projekt-Storage</dt><dd><code><?= e(str_replace('\\','/', enterprise_project_storage_root($project))) ?></code></dd></div>
            <div><dt>Erstellt</dt><dd><?= e($project['created_at']) ?></dd></div>
        </dl>
    </section>
<?php endif; ?>
<?php
$content = ob_get_clean();

render_page([
    'title' => $project['name'] ?? 'Projekt',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projekt-Dashboard',
        'location' => 'Enterprise → Projekte → Projekt',
        'short' => 'Zentrale Übersicht des ausgewählten Projekts.',
        'goal' => 'Das zugeordnete Produkt öffnen oder Projektinformationen prüfen.',
        'next' => 'Wählen Sie „Tabelle anlegen“ oder „Formular anlegen“ im Bereich „Tabellen und Formulare“.',
        'tips' => [
            'Der Core wird niemals direkt als Benutzeroberfläche geöffnet.',
            'Zur Projektwahl gelangen Sie über „Zur Projektliste“.',
        ],
    ],
]);
