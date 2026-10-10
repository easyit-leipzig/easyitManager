<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.view');
$error = '';
$projects = [];
$projectProducts = [];
$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
$flash = '';
$flashType = 'success';
$flashBackupToken = '';
$flashBackupFilename = '';
$flashBackupSha256 = '';
$flashBackupExpiresAt = 0;

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $projectProducts = enterprise_available_project_products($pdo,$user);
    $projects = $pdo->query('SELECT * FROM projects ORDER BY name')->fetchAll();
    $projects = enterprise_filter_projects_for_scope($user,$projects);
    foreach ($projects as &$projectRow) {
        try {
            $projectDriver = enterprise_project_store_driver($env, $projectRow);
            $projectRow['_store_driver'] = $projectDriver;
            $projectRow['_store_exists'] = enterprise_project_store_exists_for_project($env, $projectRow);
        } catch (Throwable $storeError) {
            $projectRow['_store_driver'] = enterprise_project_store_driver($env, $projectRow);
            $projectRow['_store_exists'] = false;
            $projectRow['_store_error'] = $storeError->getMessage();
        }
    }
    unset($projectRow);

    if (isset($_SESSION['project_flash']) && is_array($_SESSION['project_flash'])) {
        $flash = trim((string)($_SESSION['project_flash']['message'] ?? ''));
        $flashType = (string)($_SESSION['project_flash']['type'] ?? 'success');
        $flashBackupToken = trim((string)($_SESSION['project_flash']['backup_token'] ?? ''));
        $flashBackupFilename = trim((string)($_SESSION['project_flash']['backup_filename'] ?? ''));
        $flashBackupSha256 = trim((string)($_SESSION['project_flash']['backup_sha256'] ?? ''));
        $flashBackupExpiresAt = (int)($_SESSION['project_flash']['backup_expires_at'] ?? 0);
        unset($_SESSION['project_flash']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../dashboard.php'],
    ['label' => 'Projekte', 'href' => ''],
]);
?>
<section class="hero">
    <span class="badge"><?= enterprise_is_superadmin($user) ? 'Superadmin · Projektverwaltung' : 'Projektverwaltung' ?></span>
    <h1>Projekte</h1>
    <p>Alle easyIT-Projekte werden zentral verwaltet. <?= enterprise_is_superadmin($user) ? 'Sie sind als Superadministrator angemeldet und verfügen hier über die vollständige Projekt-CRUD-Verwaltung.' : 'Öffnen, bearbeiten und entfernen Sie Projekte entsprechend Ihrer Berechtigungen.' ?></p>
    <p class="project-build-note"><strong>Tabellen und Formulare:</strong> Bei DataForm-Projekten können Sie diese direkt über die sichtbaren Schnellzugriffe in der Projektzeile anlegen oder verwalten.</p>
    <div class="actions">
        <?php if(enterprise_can($user,'projects.create') && $projectProducts!==[]): ?><a class="button" <?= easyit_button_attributes('neu','project') ?> data-button-fixed="1" data-crud="create" href="create.php">Neues Projekt anlegen</a>
        <a class="button secondary" <?= easyit_button_attributes('projekt_registrieren') ?> data-button-fixed="1" data-crud="create" href="register.php">Vorhandenes Projekt registrieren</a><?php endif; ?>
        <?php if(enterprise_can($user,'projects.restore')): ?><a class="button secondary" <?= easyit_button_attributes('restore') ?> data-button-fixed="1" data-crud="create" href="restore.php">Projektsicherung wiederherstellen</a><?php endif; ?>
    </div>
</section>

<?php if ($flash !== ''): ?>
    <div class="notice <?= $flashType === 'error' ? 'error' : 'success' ?>" role="status">
        <div><?= e($flash) ?></div>
        <?php if ($flashBackupToken !== ''): ?>
            <div class="button-row" style="margin-top:.75rem">
                <a class="button secondary" data-crud="read" href="backup-download.php?token=<?= e($flashBackupToken) ?>">Projektsicherung herunterladen</a>
            </div>
            <small>
                Datei: <code><?= e($flashBackupFilename) ?></code>
                <?php if ($flashBackupSha256 !== ''): ?> · SHA-256: <code><?= e($flashBackupSha256) ?></code><?php endif; ?>
                <?php if ($flashBackupExpiresAt > 0): ?> · Downloadlink gültig bis <?= e(date('d.m.Y H:i', $flashBackupExpiresAt)) ?><?php endif; ?>
            </small>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="notice error" role="alert">
        <strong>Projektregister nicht verfügbar.</strong><br>
        <?= e($error) ?>
        <div class="actions" style="margin-top:1rem">
            <a class="button" <?= easyit_button_attributes('einstellungen','setup') ?> href="../../setup.php">Setup durchführen</a>
            <a class="button secondary" <?= easyit_button_attributes('datenbank_assistent') ?> href="../../installer/database.php">Datenbank-Assistent öffnen</a>
            <a class="button secondary" <?= easyit_button_attributes('restore') ?> href="../../recovery.php">Recovery / Reset</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!$error && !$projects): ?>
    <div class="empty-state">
        <h2>Noch keine Projekte</h2>
        <?php if(enterprise_can($user,'projects.create') && $projectProducts!==[]): ?><a class="button" <?= easyit_button_attributes('neu','project') ?> data-button-fixed="1" data-crud="create" href="create.php">Erstes Projekt vollständig anlegen</a>
        <a class="button secondary" <?= easyit_button_attributes('projekt_registrieren') ?> data-button-fixed="1" data-crud="create" href="register.php">Vorhandenes Projekt registrieren</a><?php endif; ?>
        <?php if(enterprise_can($user,'projects.restore')): ?><a class="button secondary" <?= easyit_button_attributes('restore') ?> data-button-fixed="1" data-crud="create" href="restore.php">Projektsicherung wiederherstellen</a><?php endif; ?>
    </div>
<?php elseif (!$error): ?>
    <div class="table-wrap">
        <table class="project-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Produkt</th>
                <th>Datenspeicher</th>
                <th>Status</th>
                <th>Geändert</th>
                <th>Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td>
                        <strong><?= e((string)$p['name']) ?></strong>
                        <?php if ((string)$p['product_type'] === 'dataform' && enterprise_customer_has_module($user,'dataform') && (!array_key_exists('_store_exists',$p) || $p['_store_exists'])): ?>
                            <nav class="project-build-links" aria-label="Tabellen und Formulare für <?= e((string)$p['name']) ?>">
                                <a href="../../products/dataform/runtime.php?project=<?= (int)$p['id'] ?>&amp;section=tables&amp;table_source=system#new-table">Tabelle anlegen</a>
                                <a href="../../products/dataform/runtime.php?project=<?= (int)$p['id'] ?>&amp;section=dataforms#new-dataform">Formular anlegen</a>
                            </nav>
                        <?php endif; ?>
                        <?php if ((string)$p['product_type'] === 'calendar' && enterprise_customer_has_module($user,'calendar')): ?>
                            <nav class="project-build-links" aria-label="Calendar für <?= e((string)$p['name']) ?>"><a href="../../products/calendar/admin.php?project=<?= (int)$p['id'] ?>">Daten bearbeiten</a><a href="../../products/calendar/setup.php?project=<?= (int)$p['id'] ?>">Calendar konfigurieren</a><a href="../products/load.php?project_id=<?= (int)$p['id'] ?>&amp;product=calendar">easyITCalendar öffnen</a></nav>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string)$p['product_type']) ?></td>
                    <td>
                        <strong><?= e(strtoupper((string)($p['_store_driver'] ?? $p['database_driver'] ?? 'mysql'))) ?></strong><br>
                        <code><?= e((string)$p['database_name']) ?></code>
                        <?php if (($p['_store_driver'] ?? '') === 'pgsql'): ?><br><small>Schema: <code><?= e(enterprise_project_store_pgsql_schema($env)) ?></code></small><?php endif; ?>
                        <?php if (array_key_exists('_store_exists',$p) && !$p['_store_exists']): ?><br><span class="status-pill">Speicher fehlt</span><?php endif; ?>
                    </td>
                    <td><?= e((string)$p['status']) ?></td>
                    <td><?= e((string)$p['updated_at']) ?></td>
                    <td>
                        <div class="project-crud-actions" aria-label="Aktionen für <?= e((string)$p['name']) ?>">
                            <a class="button" <?= easyit_button_attributes('anzeigen','project') ?> data-button-fixed="1" data-crud="read" href="view.php?id=<?= (int)$p['id'] ?>">Öffnen</a>
                            <?php if(enterprise_can($user,'projects.update') && enterprise_project_scope_manage($user,(int)$p['id'])): ?><a class="button" <?= easyit_button_attributes('bearbeiten','project') ?> data-button-fixed="1" data-crud="edit" href="edit.php?id=<?= (int)$p['id'] ?>">Bearbeiten</a><?php endif; ?>
                            <?php if(enterprise_customer_has_module($user,'export') && enterprise_license_capability($user,'export.execute') && enterprise_can($user,'projects.backup') && enterprise_project_scope_manage($user,(int)$p['id'])): ?><form method="post" action="export.php" class="inline-form" style="display:inline" onsubmit="return confirm('Schlanke HTML5-DataForm-Anwendung für dieses Projekt erzeugen?');">
                                <input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button class="button" <?= easyit_button_attributes('exportieren','project_package') ?> data-button-fixed="1" type="submit">HTML5-App exportieren</button>
                            </form><?php endif; ?>
                            <?php if (array_key_exists('_store_exists',$p) && !$p['_store_exists'] && enterprise_can($user,'projects.update') && enterprise_project_scope_manage($user,(int)$p['id'])): ?>
                                <a class="button" <?= easyit_button_attributes('neu','project_storage') ?> data-button-fixed="1" href="rebuild.php?id=<?= (int)$p['id'] ?>">Speicher neu aufbauen</a>
                            <?php endif; ?>
                            <?php if(enterprise_can($user,'projects.delete') && enterprise_project_scope_manage($user,(int)$p['id'])): ?><a class="button" <?= easyit_button_attributes('loeschen','project') ?> data-button-fixed="1" data-crud="delete" href="delete.php?id=<?= (int)$p['id'] ?>">Projekt löschen</a><?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Projekte',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projektverwaltung',
        'location' => 'Enterprise → Projekte',
        'short' => 'Hier verwalten Sie alle registrierten easyIT-Projekte mit vollständigem CRUD.',
        'goal' => 'Projekt anlegen, öffnen, bearbeiten, als lauffähiges HTML5-App exportieren oder aus der Enterprise-Verwaltung entfernen.',
        'next' => 'Bei einem DataForm-Projekt direkt „Tabelle anlegen“ oder „Formular anlegen“ wählen; alternativ das Projekt öffnen.',
        'tips' => [
            'Beim Löschen können Sie vorab eine vollständige Projektsicherung als Download erzeugen und entscheiden anschließend ausdrücklich, ob die Projektdatenbank erhalten bleibt oder zusätzlich endgültig gelöscht wird.',
            'Eine heruntergeladene Projektsicherung kann über „Projektsicherung wiederherstellen“ inklusive Datenbank und Projektregistrierung zurückgespielt werden.',
            'Über den Export-Button wird eine eigenständige HTML5-DataForm-Anwendung erzeugt: je DataForm eine CRUD-Seite, dazu nur Runtime, DB-Anbindung, notwendige Assets, Projektmedien und SQL-Snapshot.',
            'Name, technischer Slug, Status und Beschreibung können über „Bearbeiten“ geändert werden.',
        ],
    ],
]);
