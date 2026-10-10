<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once __DIR__ . '/system/ModuleRegistry.php';
require_once __DIR__ . '/system/ModulePackageManager.php';

$user = enterprise_require_auth('../../');
enterprise_require_customer_module($user,'dataform');
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0));
enterprise_require_project_scope($user,$projectId,true);
$error = '';
$success = '';
$project = null;
$states = [];
$packagePreview = null;
$packageHistory = [];

try {
    $adminPdo = enterprise_pdo();
    enterprise_upgrade($adminPdo);
    if ($projectId < 1) {
        throw new RuntimeException('Kein Projekt ausgewählt.');
    }
    $stmt = $adminPdo->prepare('SELECT * FROM projects WHERE id=? AND product_type=? LIMIT 1');
    $stmt->execute([$projectId, 'dataform']);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Das DataForm-Projekt wurde nicht gefunden.');
    }
    $_SESSION['active_project_id'] = $projectId;

    $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);

    $modulesPath = __DIR__ . '/modules';
    $discovered = ModuleRegistry::discover($modulesPath);
    $states = ModuleRegistry::states($pdo, $discovered);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'toggle_module') {
            $key = strtolower(trim((string)($_POST['module_key'] ?? '')));
            $enabled = (string)($_POST['enabled'] ?? '0') === '1';
            ModuleRegistry::setEnabled($pdo, $key, $enabled, $states);
            $states = ModuleRegistry::states($pdo, $discovered);
            $success = 'Der Modulstatus wurde gespeichert.';
        } elseif ($action === 'rescan_modules') {
            ModuleRegistry::synchronize($pdo, $discovered);
            $states = ModuleRegistry::states($pdo, $discovered);
            $success = 'Die Modulverzeichnisse wurden erneut eingelesen.';
        } elseif ($action === 'preview_package') {
            $packagePreview = ModulePackageManager::inspectUpload($_FILES['module_package'] ?? [], dirname(__DIR__,2) . '/workspace/module-packages');
            $_SESSION['module_package_preview'] = $packagePreview;
            $success = 'Das Modulpaket wurde geprüft. Kontrollieren Sie die Vorschau und installieren Sie es anschließend.';
        } elseif ($action === 'install_package') {
            $packagePreview = $_SESSION['module_package_preview'] ?? null;
            if (!is_array($packagePreview) || !is_dir((string)($packagePreview['module_dir'] ?? ''))) {
                throw new RuntimeException('Es liegt kein gültig geprüftes Modulpaket zur Installation vor.');
            }
            $done = ModulePackageManager::install($pdo, $packagePreview, $modulesPath, (int)($user['id'] ?? 0));
            unset($_SESSION['module_package_preview']);
            $discovered = ModuleRegistry::discover($modulesPath);
            $states = ModuleRegistry::states($pdo, $discovered);
            $success = $done === 'update' ? 'Das Modul wurde sicher aktualisiert.' : 'Das Modul wurde sicher installiert.';
            $packagePreview = null;
        } elseif ($action === 'cancel_package') {
            $packagePreview = $_SESSION['module_package_preview'] ?? null;
            if (is_array($packagePreview)) ModulePackageManager::cleanup($packagePreview);
            unset($_SESSION['module_package_preview']);
            $packagePreview = null;
            $success = 'Die Paketvorschau wurde verworfen.';
        }
    }
    if ($packagePreview === null && isset($_SESSION['module_package_preview']) && is_array($_SESSION['module_package_preview'])) {
        $packagePreview = $_SESSION['module_package_preview'];
    }
    $packageHistory = ModulePackageManager::history($pdo);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$enabledCount = count(array_filter($states, static fn(array $m): bool => $m['enabled']));
$requiredCount = count(array_filter($states, static fn(array $m): bool => $m['required']));
ob_start();
?>
<div class="workspace-breadcrumbs"><?php render_breadcrumbs([
 ['label'=>'Enterprise','href'=>'../../app/dashboard.php'],
 ['label'=>'Projekte','href'=>'../../app/projects/index.php'],
 ['label'=>(string)($project['name']??'DataForm'),'href'=>$project?'../../app/projects/view.php?id='.(int)$project['id']:''],
 ['label'=>'DataForm Workspace','href'=>'index.php?project='.$projectId],
 ['label'=>'Module','href'=>''],
]); ?></div>
<div class="df-workspace">
<header class="df-workspace-header"><div><span class="badge">DataForm Workspace</span><h1>Modulverwaltung</h1><p>Module erkennen, aktivieren sowie signifikant geprüfte ZIP-Pakete installieren und aktualisieren.</p></div><div class="actions"><a class="button secondary" href="index.php?project=<?= $projectId ?>">Zum Workspace</a></div></header>
<?php if($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
<div class="df-workspace-grid">
<aside class="df-explorer"><div class="df-pane-title">Explorer</div><nav><a href="index.php?project=<?= $projectId ?>">Übersicht</a><a href="index.php?project=<?= $projectId ?>&section=dataforms">DataForms</a><a href="relations.php?project=<?= $projectId ?>">Beziehungen</a><a href="workflow.php?project=<?= $projectId ?>">Workflow</a><a class="active" href="modules.php?project=<?= $projectId ?>">Module</a></nav></aside>
<main class="df-main">
<div class="metric-grid df-metrics"><div class="metric"><strong><?= count($states) ?></strong><span>erkannt</span></div><div class="metric"><strong><?= $enabledCount ?></strong><span>aktiv</span></div><div class="metric"><strong><?= $requiredCount ?></strong><span>Pflichtmodule</span></div></div>
<section class="card"><div class="df-toolbar"><div><h2>Installierte Module</h2><p>Die Manifestdateien werden aus <code>products/dataform/modules/*/module.json</code> gelesen.</p></div><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="action" value="rescan_modules"><button class="button secondary">Neu einlesen</button></form></div>
<?php if(!$states): ?><div class="empty-state">Keine gültigen Module gefunden.</div><?php else: ?><div class="df-form-list"><?php foreach($states as $module): ?><article class="df-form-item"><div><h3><?= e((string)$module['name']) ?> <span class="badge">v<?= e((string)$module['version']) ?></span></h3><p><?= e((string)($module['description'] ?: 'Keine Beschreibung vorhanden.')) ?></p><div class="df-form-meta"><code><?= e((string)$module['key']) ?></code> · <?= e((string)$module['category']) ?> · <?= $module['required']?'Pflichtmodul':'optional' ?><?php if($module['dependencies']): ?> · benötigt: <?= e(implode(', ', $module['dependencies'])) ?><?php endif; ?></div></div><div class="actions"><strong class="<?= $module['enabled']?'ok':'muted' ?>"><?= $module['enabled']?'aktiv':'inaktiv' ?></strong><?php if(!$module['required']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="action" value="toggle_module"><input type="hidden" name="module_key" value="<?= e((string)$module['key']) ?>"><input type="hidden" name="enabled" value="<?= $module['enabled']?'0':'1' ?>"><button class="button <?= $module['enabled']?'secondary':'' ?>"><?= $module['enabled']?'Deaktivieren':'Aktivieren' ?></button></form><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="card"><h2>Modulpaket installieren oder aktualisieren</h2><p>Laden Sie ein ZIP-Paket hoch. Der Assistent prüft Pfade, Dateitypen, Größe, Manifest und semantische Version, bevor Dateien verändert werden.</p>
<?php if(!$packagePreview): ?><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="action" value="preview_package"><label class="full">ZIP-Modulpaket<input type="file" name="module_package" accept=".zip,application/zip" required></label><div class="full actions"><button class="button">Paket prüfen</button></div></form>
<?php else: ?><div class="notice"><strong><?= e((string)$packagePreview['name']) ?></strong> · Version <?= e((string)$packagePreview['version']) ?> · Schlüssel <code><?= e((string)$packagePreview['key']) ?></code><br><?= count($packagePreview['files']) ?> Dateien · SHA-256 <code><?= e(substr((string)$packagePreview['package_hash'],0,20)) ?>…</code><?php if($packagePreview['dependencies']): ?><br>Abhängigkeiten: <?= e(implode(', ',$packagePreview['dependencies'])) ?><?php endif; ?></div><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="action" value="install_package"><button class="button">Geprüftes Paket installieren</button></form><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="action" value="cancel_package"><button class="button secondary">Verwerfen</button></form></div><?php endif; ?></section>
<section class="card"><h2>Installationshistorie</h2><?php if(!$packageHistory): ?><div class="empty-state">Noch keine Modulpakete über den Assistenten installiert.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Modul</th><th>Version</th><th>Aktion</th><th>Status</th></tr></thead><tbody><?php foreach($packageHistory as $entry): ?><tr><td><?= e((string)$entry['installed_at']) ?></td><td><code><?= e((string)$entry['module_key']) ?></code></td><td><?= e((string)$entry['version']) ?></td><td><?= e((string)$entry['action_name']) ?></td><td><?= e((string)$entry['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card"><h2>Laufzeitprinzip</h2><p>Der Modulstatus wird in der vorhandenen Projektdatenbank gespeichert. Ein erneutes Einlesen aktualisiert Name, Version und Manifest-Prüfsumme, ohne die Aktivierung des Projekts zu überschreiben.</p></section>
</main>
<aside class="df-properties"><div class="df-pane-title">Kontexthilfe</div><h3>Ziel</h3><p>DataForm-Funktionen als getrennte, kontrollierbare Module betreiben.</p><h3>Pflichtmodule</h3><p>Designer und Security bilden die technische Grundausstattung und können nicht deaktiviert werden.</p><h3>Abhängigkeiten</h3><p>Ein Modul kann nur aktiviert werden, wenn seine benötigten Module bereits aktiv sind.</p><h3>Nächster Schritt</h3><p>Modulkonfigurationen und kryptografisch signierte Release-Pakete ergänzen.</p></aside>
</div></div>
<?php
$content = ob_get_clean();
render_page([
 'title'=>'DataForm-Module','active'=>'projects','base'=>'../../','content'=>$content,
 'app_nav'=>true,'user'=>$user,'body_class'=>'workspace-page','styles'=>['products/dataform/assets/workspace.css'],
 'help'=>['title'=>'Modulverwaltung','location'=>'DataForm → Module','goal'=>'Module erkennen und projektbezogen verwalten.','next'=>'Modulkonfigurationen und signierte Release-Pakete ergänzen.','duration'=>'ca. 2 Minuten']
]);
