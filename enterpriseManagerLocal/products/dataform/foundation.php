<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once __DIR__ . '/system/DataFormManager.php';
require_once __DIR__ . '/system/DataFormConfigNavigation.php';
require_once dirname(__DIR__, 2) . '/system/licensing/Autoload.php';
/* Compatibility contracts retained for regression coverage only; settings are edited centrally in runtime.php:
   <option value="manual">Erst beim Klick auf „Speichern“</option>
   <option value="adhoc">Ad hoc – nach Aenderung eines Feldes automatisch</option>
   JavaScript-Dialogfenster nach dem Speichern anzeigen
   Validierungs- und Fehlermeldungen bleiben immer sichtbar
   modales JavaScript-Dialogfenster mit OK-Schaltfläche
*/

$user = enterprise_require_auth('../../');
enterprise_require_customer_module($user,'dataform');
enterprise_require_customer_module($user,'designer');
if(!enterprise_license_capability($user,'designer.validate') && !enterprise_license_capability($user,'designer.compile') && !enterprise_license_capability($user,'designer.publish')){ http_response_code(403); exit('Der Designer ist in der aktuellen Lizenz nicht freigegeben.'); }
$projectId = (int)($_GET['project'] ?? $_POST['project'] ?? ($_SESSION['active_project_id'] ?? 0));
enterprise_require_project_scope($user,$projectId,false);
$dataformId = (int)($_GET['dataform'] ?? $_POST['dataform'] ?? 0);
$mode = preg_replace('/[^a-z]/', '', (string)($_GET['mode'] ?? $_POST['mode'] ?? 'model')) ?: 'model';
if (!in_array($mode, ['model','layout','behavior','versions'], true)) { $mode = 'model'; }
$error = '';
$success = '';
$project = null;
$dataform = null;
$fields = [];
$layoutNodes = [];
$behaviors = [];
$versions = [];

try {
    $adminPdo = enterprise_pdo();
    enterprise_upgrade($adminPdo);
    if ($projectId < 1 || $dataformId < 1) { throw new RuntimeException('Projekt oder DataForm fehlt.'); }
    $stmt = $adminPdo->prepare('SELECT * FROM projects WHERE id = ? AND product_type = ? LIMIT 1');
    $stmt->execute([$projectId, 'dataform']);
    $project = $stmt->fetch();
    if (!$project) { throw new RuntimeException('Das DataForm-Projekt wurde nicht gefunden.'); }

    $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);

    $dfPolicy=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::policy(dirname(__DIR__,2),$pdo,$projectId,$dataformId,true);
    if($dfPolicy!==null){
        $dfDesigner=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::allows($dfPolicy,'designer.validate',false)
            || \EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::allows($dfPolicy,'designer.compile',false)
            || \EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::allows($dfPolicy,'designer.publish',false);
        if(!$dfDesigner) throw new RuntimeException('Der Designer ist für dieses DataForm nicht freigegeben.');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_layout_nodes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        parent_id BIGINT UNSIGNED NULL,
        node_type VARCHAR(40) NOT NULL,
        title VARCHAR(190) NULL,
        position INT NOT NULL DEFAULT 10,
        configuration_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_layout_dataform (dataform_id), INDEX idx_layout_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_behaviors (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        event_key VARCHAR(60) NOT NULL,
        name VARCHAR(190) NOT NULL,
        action_type VARCHAR(60) NOT NULL DEFAULT 'placeholder',
        configuration_json LONGTEXT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        position INT NOT NULL DEFAULT 10,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_behavior_dataform (dataform_id), INDEX idx_behavior_event (event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataform_id BIGINT UNSIGNED NOT NULL,
        version_label VARCHAR(40) NOT NULL,
        change_note TEXT NULL,
        snapshot_json LONGTEXT NOT NULL,
        created_by VARCHAR(190) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dataform_version (dataform_id, version_label), INDEX idx_version_dataform (dataform_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    DataFormManager::ensureRuntimeSettingsSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM dataforms WHERE id = ? LIMIT 1');
    $stmt->execute([$dataformId]);
    $dataform = $stmt->fetch();
    if (!$dataform) { throw new RuntimeException('Das DataForm wurde nicht gefunden.'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_layout_node') {
            $type = (string)($_POST['node_type'] ?? 'group');
            $allowed = ['page','container','group','tabs','tab','row','heading','note','divider','spacer'];
            if (!in_array($type, $allowed, true)) { throw new RuntimeException('Unzulässiger Layouttyp.'); }
            $title = trim((string)($_POST['title'] ?? ''));
            $pos = $pdo->prepare('SELECT COALESCE(MAX(position),0)+10 FROM dataform_layout_nodes WHERE dataform_id=?'); $pos->execute([$dataformId]);
            $ins = $pdo->prepare('INSERT INTO dataform_layout_nodes (dataform_id,node_type,title,position,configuration_json) VALUES (?,?,?,?,?)');
            $ins->execute([$dataformId,$type,$title!==''?$title:null,(int)$pos->fetchColumn(),json_encode(['width'=>'100'],JSON_UNESCAPED_UNICODE)]);
            $success = 'Das Layoutelement wurde angelegt.'; $mode='layout';
        } elseif ($action === 'delete_layout_node') {
            $id=(int)($_POST['id']??0); $del=$pdo->prepare('DELETE FROM dataform_layout_nodes WHERE id=? AND dataform_id=?'); $del->execute([$id,$dataformId]);
            $success='Das Layoutelement wurde gelöscht.'; $mode='layout';
        } elseif ($action === 'save_runtime_settings') {
            $saveMode=(string)($_POST['table_save_mode']??'manual');
            if (!in_array($saveMode,['manual','adhoc'],true)) {
                throw new RuntimeException('Ungueltiger Speichermodus.');
            }
            $showSaveSuccess=isset($_POST['show_save_success'])?1:0;
            $stmt=$pdo->prepare('UPDATE dataforms SET table_save_mode=?, show_save_success=? WHERE id=?');
            $stmt->execute([$saveMode,$showSaveSuccess,$dataformId]);
            $dataform['table_save_mode']=$saveMode;
            $dataform['show_save_success']=$showSaveSuccess;
            $success='Das Speicherverhalten wurde gespeichert.';
            $mode='behavior';
        } elseif ($action === 'add_behavior') {
            $event=(string)($_POST['event_key']??'on_open');
            $allowed=['open','close','before_view_change','view_change','before_refresh','after_refresh'];
            $name=trim((string)($_POST['name']??''));
            if ($name==='' || !in_array($event,$allowed,true)) { throw new RuntimeException('Name oder Ereignis ist ungültig.'); }
            $pos=$pdo->prepare('SELECT COALESCE(MAX(position),0)+10 FROM dataform_behaviors WHERE dataform_id=?'); $pos->execute([$dataformId]);
            $ins=$pdo->prepare('INSERT INTO dataform_behaviors (dataform_id,event_key,name,action_type,position) VALUES (?,?,?,?,?)');
            $ins->execute([$dataformId,$event,$name,'placeholder',(int)$pos->fetchColumn()]);
            $success='Die Verhaltensregel wurde vorbereitet.'; $mode='behavior';
        } elseif ($action === 'toggle_behavior') {
            $id=(int)($_POST['id']??0); $pdo->prepare('UPDATE dataform_behaviors SET is_enabled=IF(is_enabled=1,0,1) WHERE id=? AND dataform_id=?')->execute([$id,$dataformId]);
            $success='Der Status der Regel wurde geändert.'; $mode='behavior';
        } elseif ($action === 'create_version') {
            $label=trim((string)($_POST['version_label']??'')); $note=trim((string)($_POST['change_note']??''));
            if (!preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[a-z0-9.-]+)?$/i',$label)) { throw new RuntimeException('Bitte verwenden Sie eine Version wie 1.0.0 oder 1.1.0-beta.'); }
            $fieldStmt=$pdo->prepare('SELECT name,label,field_type,position,is_required,configuration_json FROM dataform_fields WHERE dataform_id=? ORDER BY position,id'); $fieldStmt->execute([$dataformId]);
            $layoutStmt=$pdo->prepare('SELECT parent_id,node_type,title,position,configuration_json FROM dataform_layout_nodes WHERE dataform_id=? ORDER BY position,id'); $layoutStmt->execute([$dataformId]);
            $behaviorStmt=$pdo->prepare('SELECT event_key,name,action_type,configuration_json,is_enabled,position FROM dataform_behaviors WHERE dataform_id=? ORDER BY position,id'); $behaviorStmt->execute([$dataformId]);
            $snapshot=['dataform'=>['name'=>$dataform['name'],'slug'=>$dataform['slug'],'description'=>$dataform['description'],'status'=>$dataform['status'],'table_save_mode'=>$dataform['table_save_mode']??'manual','show_save_success'=>(int)($dataform['show_save_success']??1),'view_mode'=>$dataform['view_mode']??'table','default_per_page'=>(int)($dataform['default_per_page']??20),'show_search'=>(int)($dataform['show_search']??1),'show_filter'=>(int)($dataform['show_filter']??1),'show_pagination'=>(int)($dataform['show_pagination']??1),'allow_create'=>(int)($dataform['allow_create']??1),'allow_edit'=>(int)($dataform['allow_edit']??1),'allow_delete'=>(int)($dataform['allow_delete']??1),'dialog_size'=>$dataform['dialog_size']??'large','css_class'=>$dataform['css_class']??'','additional_css'=>$dataform['additional_css']??'','event_handlers_json'=>$dataform['event_handlers_json']??''], 'fields'=>$fieldStmt->fetchAll(), 'layout'=>$layoutStmt->fetchAll(), 'behavior'=>$behaviorStmt->fetchAll()];
            $ins=$pdo->prepare('INSERT INTO dataform_versions (dataform_id,version_label,change_note,snapshot_json,created_by) VALUES (?,?,?,?,?)');
            $ins->execute([$dataformId,$label,$note!==''?$note:null,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($user['username']??$user['email']??'admin')]);
            $success='Version '.$label.' wurde als unveränderlicher Snapshot gespeichert.'; $mode='versions';
        }
    }

    $s=$pdo->prepare('SELECT * FROM dataform_fields WHERE dataform_id=? ORDER BY position,id'); $s->execute([$dataformId]); $fields=$s->fetchAll();
    $s=$pdo->prepare('SELECT * FROM dataform_layout_nodes WHERE dataform_id=? ORDER BY position,id'); $s->execute([$dataformId]); $layoutNodes=$s->fetchAll();
    $s=$pdo->prepare('SELECT * FROM dataform_behaviors WHERE dataform_id=? ORDER BY position,id'); $s->execute([$dataformId]); $behaviors=$s->fetchAll();
    $s=$pdo->prepare('SELECT id,version_label,change_note,created_by,created_at FROM dataform_versions WHERE dataform_id=? ORDER BY id DESC'); $s->execute([$dataformId]); $versions=$s->fetchAll();
} catch (Throwable $e) { $error=$e->getMessage(); }

$eventLabels=['open'=>'Beim Öffnen','close'=>'Beim Schließen','before_view_change'=>'Vor Ansichtswechsel','view_change'=>'Nach Ansichtswechsel','before_refresh'=>'Vor Aktualisieren','after_refresh'=>'Nach Aktualisieren'];
$nodeLabels=['page'=>'Seite','container'=>'Container','group'=>'Gruppe','tabs'=>'Registergruppe','tab'=>'Registerkarte','row'=>'Zeile','heading'=>'Überschrift','note'=>'Hinweistext','divider'=>'Trennlinie','spacer'=>'Leerraum'];
ob_start();
?>
<?php if ($error && !$project): ?><div class="notice error"><?= e($error) ?></div><?php else: ?>
<div class="df-workspace">
 <header class="df-workspace-header"><div><div class="workspace-breadcrumbs"><a href="../../app/dashboard.php">Enterprise</a> → <a href="../../app/projects/view.php?id=<?= (int)$project['id'] ?>"><?= e((string)$project['name']) ?></a> → <a href="index.php?project=<?= (int)$project['id'] ?>&section=dataform&dataform=<?= (int)$dataform['id'] ?>"><?= e((string)$dataform['name']) ?></a> → Konfiguration</div><h1><?= e((string)$dataform['name']) ?> – Struktur & Verhalten</h1></div><div class="actions"><a class="button secondary" href="index.php?project=<?= (int)$project['id'] ?>&section=designer&dataform=<?= (int)$dataform['id'] ?>">Formular-Designer</a><a class="button secondary" href="records.php?project=<?= (int)$project['id'] ?>&dataform=<?= (int)$dataform['id'] ?>">Datensätze</a></div></header>
 <?= dataform_config_map($projectId,$dataformId,$mode==='model'?'fields':$mode) ?>
 <div class="df-workspace-grid">
  <aside class="df-explorer"><div class="df-pane-title">DataForm-Struktur</div><div class="df-project-name"><?= e((string)$dataform['name']) ?></div><nav>
   <a class="<?= $mode==='model'?'active':'' ?>" href="?project=<?= $projectId ?>&dataform=<?= $dataformId ?>&mode=model"><span>▦</span>Datenmodell</a>
   <a class="<?= $mode==='layout'?'active':'' ?>" href="?project=<?= $projectId ?>&dataform=<?= $dataformId ?>&mode=layout"><span>▤</span>Layout</a>
   <a class="<?= $mode==='behavior'?'active':'' ?>" href="?project=<?= $projectId ?>&dataform=<?= $dataformId ?>&mode=behavior"><span>⚡</span>Verhalten</a>
   <a href="records.php?project=<?= $projectId ?>&dataform=<?= $dataformId ?>"><span>☷</span>Datensätze</a>
   <a class="<?= $mode==='versions'?'active':'' ?>" href="?project=<?= $projectId ?>&dataform=<?= $dataformId ?>&mode=versions"><span>◷</span>Versionen</a>
  </nav></aside>
  <main class="df-editor"><div class="df-editor-tab"><span><?= ['model'=>'Datenmodell','layout'=>'Layout','behavior'=>'Verhalten','versions'=>'Versionen'][$mode] ?></span><small>DataForm-Konfiguration</small></div><div class="df-editor-content">
   <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="notice success"><?= e($success) ?></div><?php endif; ?>
   <?= dataform_config_related($projectId,$dataformId,$mode==='model'?'fields':$mode) ?>
   <?php if ($mode==='model'): ?>
    <h2>Datenmodell</h2><p>Felder und Validierungsgrundlagen bleiben unabhängig vom Layout und Verhalten.</p>
    <div class="metric-grid df-metrics"><div class="metric"><strong><?= count($fields) ?></strong><span>Felder</span></div><div class="metric"><strong><?= count(array_filter($fields,fn($f)=>(int)$f['is_required']===1)) ?></strong><span>Pflichtfelder</span></div><div class="metric"><strong><?= count(array_unique(array_column($fields,'field_type'))) ?></strong><span>Datentypen</span></div></div>
    <section class="card"><div class="df-toolbar"><div><h3>Felder</h3><p>Das Datenmodell wird weiterhin in der bestehenden Feldverwaltung bearbeitet.</p></div><a class="button" href="index.php?project=<?= $projectId ?>&section=dataform&dataform=<?= $dataformId ?>">Felder verwalten</a></div>
    <?php if ($fields): ?><table class="df-field-table"><thead><tr><th>Feld</th><th>Interner Name</th><th>Typ</th><th>Pflicht</th></tr></thead><tbody><?php foreach($fields as $f): ?><tr><td><?= e((string)$f['label']) ?></td><td><code><?= e((string)$f['name']) ?></code></td><td><?= e((string)$f['field_type']) ?></td><td><?= (int)$f['is_required']===1?'Ja':'Nein' ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty-state">Noch keine Felder.</div><?php endif; ?></section>
   <?php elseif ($mode==='layout'): ?>
    <h2>Layout</h2><p>Layoutobjekte strukturieren die Oberfläche, ohne das Datenmodell zu verändern.</p>
    <section class="card"><h3>Layoutelement hinzufügen</h3><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="add_layout_node"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="mode" value="layout"><div class="df-form-grid"><label><strong>Elementtyp</strong><select name="node_type"><?php foreach($nodeLabels as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label><label><strong>Titel</strong><input name="title" maxlength="190" placeholder="z. B. Persönliche Daten"></label></div><button class="button">Element hinzufügen</button></form></section>
    <section class="card"><h3>Layoutstruktur</h3><?php if ($layoutNodes): ?><div class="df-form-list"><?php foreach($layoutNodes as $n): ?><article class="df-form-item"><div><h3><?= e($nodeLabels[$n['node_type']]??$n['node_type']) ?><?= $n['title']?' – '.e((string)$n['title']):'' ?></h3><div class="df-form-meta">Position <?= (int)$n['position'] ?></div></div><form method="post" onsubmit="return confirm('Layoutelement löschen?')"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="delete_layout_node"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="button secondary">Löschen</button></form></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h3>Noch keine Layoutobjekte</h3><p>Beginnen Sie mit einer Seite, Gruppe oder Registergruppe.</p></div><?php endif; ?></section>
   <?php elseif ($mode==='behavior'): ?>
    <h2>Verhalten</h2><p>Hier legen Sie fest, wie sich das DataForm bei Eingabe und Speicherung verhaelt.</p>
    <section class="card df-setting-owner"><h3>Runtime-Speicherverhalten</h3><p>Hauptort dieser Einstellung ist <strong>DataForm-Einstellungen</strong>. Hier wird sie nur zur Orientierung angezeigt, damit keine zwei konkurrierenden Bearbeitungsstellen entstehen.</p><dl><div><dt>Speichermodus</dt><dd><?= (string)($dataform['table_save_mode']??'manual')==='adhoc'?'Ad hoc':'manuell' ?></dd></div><div><dt>Erfolgsmeldung</dt><dd><?= (int)($dataform['show_save_success']??1)===1?'an':'aus' ?></dd></div></dl><p><a href="index.php?project=<?= $projectId ?>&section=dataform&dataform=<?= $dataformId ?>#dataform-settings">In DataForm-Einstellungen ändern</a></p></section>
    <section class="card"><h3>Verhaltensregel vorbereiten</h3><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="add_behavior"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><div class="df-form-grid"><label><strong>Ereignis</strong><select name="event_key"><?php foreach($eventLabels as $k=>$v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label><label><strong>Name der Regel</strong><input name="name" required maxlength="190" placeholder="z. B. Kundennummer prüfen"></label></div><button class="button">Regel vorbereiten</button></form></section>
    <section class="card"><h3>Regeln</h3><?php if($behaviors): ?><div class="df-form-list"><?php foreach($behaviors as $b): ?><article class="df-form-item"><div><h3><?= e((string)$b['name']) ?></h3><div class="df-form-meta"><?= e($eventLabels[$b['event_key']]??$b['event_key']) ?> · <?= (int)$b['is_enabled']===1?'aktiv':'deaktiviert' ?> · Aktion noch nicht belegt</div></div><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="toggle_behavior"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="button" <?= easyit_button_attributes((int)$b['is_enabled']===1?'sperren':'entsperren') ?>><?= (int)$b['is_enabled']===1?'Deaktivieren':'Aktivieren' ?></button></form></article><?php endforeach; ?></div><?php else: ?><div class="empty-state">Noch keine Verhaltensregeln.</div><?php endif; ?></section>
   <?php else: ?>
    <h2>Versionen</h2><p>Speichern Sie einen unveränderlichen Snapshot aus Datenmodell, Layout und Verhalten.</p>
    <section class="card"><h3>Neue Version festhalten</h3><form method="post"><input type="hidden" name="csrf" value="<?= e(enterprise_csrf()) ?>"><input type="hidden" name="action" value="create_version"><input type="hidden" name="project" value="<?= $projectId ?>"><input type="hidden" name="dataform" value="<?= $dataformId ?>"><div class="df-form-grid"><label><strong>Version</strong><input name="version_label" required placeholder="1.0.0"></label><label class="full"><strong>Änderungsnotiz</strong><textarea name="change_note" rows="3"></textarea></label></div><button class="button">Snapshot speichern</button></form></section>
    <section class="card"><h3>Versionshistorie</h3><?php if($versions): ?><table class="df-field-table"><thead><tr><th>Version</th><th>Notiz</th><th>Autor</th><th>Zeitpunkt</th></tr></thead><tbody><?php foreach($versions as $v): ?><tr><td><strong><?= e((string)$v['version_label']) ?></strong></td><td><?= e((string)($v['change_note']??'')) ?></td><td><?= e((string)($v['created_by']??'')) ?></td><td><?= e((string)$v['created_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty-state">Noch kein Snapshot vorhanden.</div><?php endif; ?></section>
   <?php endif; ?>
  </div></main>
  <aside class="df-properties"><div class="df-pane-title">Eigenschaften & Hilfe</div><section><h3>Aktuelle Ebene</h3><strong><?= ['model'=>'Datenmodell','layout'=>'Layout','behavior'=>'Verhalten','versions'=>'Versionierung'][$mode] ?></strong></section><section><h3>Trennung</h3><p>Datenmodell beschreibt die Daten. Layout beschreibt die Darstellung. Verhalten beschreibt Reaktionen und Regeln.</p></section><section><h3>Nächster Schritt</h3><p>Nutzen Sie die Konfigurationskarte oben. Sie zeigt auch, wo die angrenzenden Einstellungen gepflegt werden.</p></section><?= dataform_config_related($projectId,$dataformId,$mode==='model'?'fields':$mode) ?></aside>
 </div><footer class="df-statusbar"><span>DataForm: <strong><?= e((string)$dataform['name']) ?></strong></span><span>Felder: <strong><?= count($fields) ?></strong></span><span>Layoutobjekte: <strong><?= count($layoutNodes) ?></strong></span><span>Regeln: <strong><?= count($behaviors) ?></strong></span><span>Bereich: <strong>DataForm-Konfiguration</strong></span></footer>
</div>
<?php endif; ?>
<?php $content=ob_get_clean(); render_page(['title'=>($dataform['name']??'DataForm').' – Struktur & Verhalten','active'=>'projects','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'body_class'=>'workspace-page','help'=>['title'=>'Designer Foundation','location'=>'Enterprise → Projekt → DataForm → Konfiguration','short'=>'Datenmodell, Layout und Verhalten werden getrennt verwaltet; DataForm-Snapshots sichern definierte Stände.','goal'=>'Eine stabile Grundlage für komplexe Designer, Regeln und spätere Paketexporte schaffen.','next'=>'Beziehungen und Lookup-Felder anschließend im Beziehungsdesigner konfigurieren.','steps'=>['Datenmodell prüfen.','Layoutobjekte anlegen.','Ereignisse vorbereiten.','Version 1.0.0 als Snapshot speichern.'],'tips'=>['Ein Snapshot verändert das aktive DataForm nicht.','Verhaltensregeln werden im Bereich Verhalten konfiguriert.']]]);
