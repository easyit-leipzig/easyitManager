<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/system/app/bootstrap.php';
require_once dirname(__DIR__,2).'/system/ui/layout.php';
require_once __DIR__.'/system/ProjectPackageManager.php';
require_once dirname(__DIR__,2).'/system/licensing/Autoload.php';

$user=enterprise_require_auth('../../');enterprise_require_customer_module($user,'dataform');
$licenseCanExport=enterprise_customer_has_module($user,'export') && enterprise_license_capability($user,'export.execute');
$projectId=(int)($_GET['project']??$_POST['project']??($_SESSION['active_project_id']??0));
enterprise_require_project_scope($user,$projectId,true);
$error='';
$success='';
$project=null;
$history=[];
$storedPackages=[];
$preview=null;
$importResult=null;
$catalog=['dataforms'=>[],'tables'=>[]];
$requestedLoadPackageId=trim((string)($_GET['load_package']??''));
if (isset($_SESSION['dataform_package_flash'])) {
    $success=(string)$_SESSION['dataform_package_flash'];
    unset($_SESSION['dataform_package_flash']);
}

try {
    $admin=enterprise_pdo();
    enterprise_upgrade($admin);
    $stmt=$admin->prepare('SELECT * FROM projects WHERE id=? AND product_type=? LIMIT 1');
    $stmt->execute([$projectId,'dataform']);
    $project=$stmt->fetch();
    if (!$project) {
        throw new RuntimeException('DataForm-Projekt wurde nicht gefunden.');
    }
    $_SESSION['active_project_id']=$projectId;
    $env=enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);
    ProjectPackageManager::ensureSchema($pdo);
    $catalog=ProjectPackageManager::exportCatalog($pdo);
    foreach($catalog['dataforms'] as &$catalogDf){try{$dfPolicy=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::policy(dirname(__DIR__,2),$pdo,$projectId,(int)$catalogDf['id'],false);$catalogDf['license_export_allowed']=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::allows($dfPolicy,'export.execute',false);}catch(Throwable){$catalogDf['license_export_allowed']=false;}}unset($catalogDf);

    // HF47 compatibility marker: legacy READ route used download_package=.
    // HF49: CRUD operations address stored packages by stable package ID.
    // A missing/stale file must no longer abort rendering the whole package page.
    $downloadPackageId=trim((string)($_GET['download_package_id']??''));
    $legacyDownloadFile=trim((string)($_GET['download_package']??''));
    if ($downloadPackageId!=='' || $legacyDownloadFile!=='') {
        try {
            if (!$licenseCanExport) { throw new RuntimeException('Paketdownload ist in der aktuellen Lizenz nicht freigegeben.'); }
            if ($downloadPackageId!=='') {
                try {
                    $download=ProjectPackageManager::storedPackageForDownloadById($project,$downloadPackageId);
                } catch (Throwable $idDownloadError) {
                    if ($legacyDownloadFile==='') {
                        throw $idDownloadError;
                    }
                    $download=ProjectPackageManager::storedPackageForDownload($project,$legacyDownloadFile);
                }
            } else {
                $download=ProjectPackageManager::storedPackageForDownload($project,$legacyDownloadFile);
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$download['filename'].'"');
            header('Content-Length: '.$download['size']);
            header('X-Checksum-SHA256: '.$download['sha256']);
            readfile($download['path']);
            exit;
        } catch (Throwable $downloadError) {
            $error='Paketdatei konnte nicht geöffnet werden: '.$downloadError->getMessage();
        }
    }

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if ($action==='export') {
            if (!$licenseCanExport) { throw new RuntimeException('Paketexport ist in der aktuellen Lizenz nicht freigegeben.'); }
            $selectedExportIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['dataform_ids']??[])),static fn(int $id):bool=>$id>0)));
            if(isset($_POST['include_dataforms'])){foreach($selectedExportIds as $exportDfId){$dfPolicy=\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::policy(dirname(__DIR__,2),$pdo,$projectId,$exportDfId,false);\EasyIT\Enterprise\Licensing\EnterpriseDataFormPolicyBridge::require($dfPolicy,'export.execute',false);}}
            $options=[
                'include_dataforms'=>isset($_POST['include_dataforms']),
                'include_relations'=>isset($_POST['include_relations']),
                'include_bindings'=>isset($_POST['include_bindings']),
                'include_workflow'=>isset($_POST['include_workflow']),
                'include_modules'=>isset($_POST['include_modules']),
                'include_table_schema'=>isset($_POST['include_table_schema']),
                'include_records'=>isset($_POST['include_records']),
                'package_name'=>(string)($_POST['package_name']??''),
                'dataform_ids'=>array_values((array)($_POST['dataform_ids']??[])),
                'base_tables'=>array_values((array)($_POST['base_tables']??[])),
            ];
            $result=ProjectPackageManager::export($pdo,$project,$user,$options);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.$result['filename'].'"');
            header('Content-Length: '.filesize($result['path']));
            header('X-Checksum-SHA256: '.$result['sha256']);
            // HF54: expose the immutable package ID so the browser can reload
            // exactly the package configuration that was just exported. This
            // keeps DataForm and base-table selections visible after download.
            header('X-DataForm-Package-ID: '.(string)($result['manifest']['packageId']??''));
            readfile($result['path']);
            exit;
        }
        if ($action==='preview_import') {
            $preview=ProjectPackageManager::inspectUpload(
                $_FILES['project_package']??[],
                dirname(__DIR__,2).'/workspace/project-packages/imports'
            );
            $_SESSION['project_package_preview']=$preview;
            $success='Paket wurde geprüft. Kontrollieren Sie Konfiguration, Tabellenschemata und Beispieldaten.';
        } elseif ($action==='import') {
            $preview=$_SESSION['project_package_preview']??null;
            if (!is_array($preview)) {
                throw new RuntimeException('Kein geprüftes Paket vorhanden.');
            }
            $importResult=ProjectPackageManager::import(
                $pdo,$preview,$user,(string)($_POST['policy']??'merge'),$projectId
            );
            unset($_SESSION['project_package_preview']);
            $preview=null;
            // HF53: the catalog was loaded before POST handling. Refresh it
            // after import so the just-imported DataForms and base tables are
            // visible immediately in the same response.
            $catalog=ProjectPackageManager::exportCatalog($pdo);
            $importedForms=(int)($importResult['tables']['dataforms']['inserted']??0)
                +(int)($importResult['tables']['dataforms']['updated']??0);
            $success='Projektpaket wurde importiert. DataForms übernommen: '.$importedForms.'.';
        } elseif ($action==='cancel') {
            $preview=$_SESSION['project_package_preview']??null;
            if (is_array($preview)) {
                ProjectPackageManager::cleanup($preview);
            }
            unset($_SESSION['project_package_preview']);
            $preview=null;
            $success='Paketvorschau verworfen.';
        } elseif ($action==='rename_package') {
            try {
                $packageId=trim((string)($_POST['package_id']??''));
                $packageFile=trim((string)($_POST['package_file']??''));
                if ($packageId!=='') {
                    try {
                        $renamed=ProjectPackageManager::renameStoredPackageById(
                            $pdo,$project,$user,$packageId,(string)($_POST['new_package_name']??'')
                        );
                    } catch (Throwable $idRenameError) {
                        if ($packageFile==='') {
                            throw $idRenameError;
                        }
                        $renamed=ProjectPackageManager::renameStoredPackage(
                            $pdo,$project,$user,$packageFile,(string)($_POST['new_package_name']??'')
                        );
                    }
                } else {
                    $renamed=ProjectPackageManager::renameStoredPackage(
                        $pdo,$project,$user,$packageFile,(string)($_POST['new_package_name']??'')
                    );
                }
                $_SESSION['dataform_package_flash']='Gespeichertes Paket wurde in „'.(string)$renamed['manifest']['packageName'].'“ umbenannt.';
                header('Location: packages.php?project='.$projectId);
                exit;
            } catch (Throwable $packageError) {
                $error='Paket konnte nicht bearbeitet werden: '.$packageError->getMessage();
            }
        } elseif ($action==='delete_package') {
            try {
                $packageId=trim((string)($_POST['package_id']??''));
                $packageFile=trim((string)($_POST['package_file']??''));
                $deleted=false;
                if ($packageId!=='') {
                    try {
                        ProjectPackageManager::deleteStoredPackageById($pdo,$project,$user,$packageId);
                        $deleted=true;
                    } catch (Throwable $idDeleteError) {
                        // HF50: Older/stale rows can still carry a valid filename while
                        // their manifest ID can no longer be resolved. Fall back to the
                        // physical filename before treating the package as already gone.
                        if ($packageFile!=='') {
                            try {
                                ProjectPackageManager::deleteStoredPackage($pdo,$project,$user,$packageFile);
                                $deleted=true;
                            } catch (Throwable $fileDeleteError) {
                                if (!ProjectPackageManager::isStoredPackageMissingError($fileDeleteError)) {
                                    throw $fileDeleteError;
                                }
                            }
                        } elseif (!ProjectPackageManager::isStoredPackageMissingError($idDeleteError)) {
                            throw $idDeleteError;
                        }
                    }
                } elseif ($packageFile!=='') {
                    try {
                        ProjectPackageManager::deleteStoredPackage($pdo,$project,$user,$packageFile);
                        $deleted=true;
                    } catch (Throwable $fileDeleteError) {
                        if (!ProjectPackageManager::isStoredPackageMissingError($fileDeleteError)) {
                            throw $fileDeleteError;
                        }
                    }
                }
                $_SESSION['dataform_package_flash']=$deleted
                    ? 'Gespeichertes Paket wurde gelöscht. Der Historieneintrag bleibt als Auditnachweis erhalten.'
                    : 'Das Paket war bereits nicht mehr in der Paketablage vorhanden. Die Liste wurde aktualisiert.';
                header('Location: packages.php?project='.$projectId);
                exit;
            } catch (Throwable $packageError) {
                $error='Paket konnte nicht gelöscht werden: '.$packageError->getMessage();
            }
        }
    }

    if ($preview===null && isset($_SESSION['project_package_preview']) && is_array($_SESSION['project_package_preview'])) {
        $preview=$_SESSION['project_package_preview'];
    }
    // HF52: Session previews created by older hotfixes did not contain the
    // human-readable relation detail list. Rebuild derived details from the
    // already checked archive on every page load and persist the refreshed
    // preview shape back to the session.
    if (is_array($preview)) {
        $preview=ProjectPackageManager::refreshPreviewDetails($preview);
        $_SESSION['project_package_preview']=$preview;
    }
    $storedPackages=ProjectPackageManager::storedPackages($project);
    $history=ProjectPackageManager::history($pdo);
} catch (Throwable $e) {
    $error=$e->getMessage();
}

ob_start();
?>
<div class="workspace-breadcrumbs"><?php render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../../app/dashboard.php'],
    ['label'=>'Projekte','href'=>'../../app/projects/index.php'],
    ['label'=>(string)($project['name']??'DataForm'),'href'=>$project?'../../app/projects/view.php?id='.(int)$project['id']:''],
    ['label'=>'DataForm Workspace','href'=>'index.php?project='.$projectId],
    ['label'=>'Projektpakete','href'=>''],
]);?></div>
<div class="df-workspace">
<header class="df-workspace-header">
    <div>
        <span class="badge">DataForm Workspace</span><?php /* Compatibility marker: DataForm Workspace · HF69 */ ?><?php /* Compatibility marker: DataForm Workspace · HF67 */ ?><?php /* Compatibility marker: DataForm Workspace · HF66 */ ?><?php /* Compatibility marker: DataForm Workspace · HF65 */ ?><?php /* Compatibility marker: DataForm Workspace · HF54 */ ?>
        <?php /* Compatibility markers: DataForm Workspace · HF53 · DataForm Workspace · HF51 · DataForm Workspace · HF50 · DataForm Workspace · HF49 · DataForm Workspace · HF48 · DataForm Workspace · HF47 · DataForm Workspace · HF46 · DataForm Workspace · HF45 */ ?>
        <h1>Projektpakete</h1>
        <p>DataForms, Beziehungen und physische Basistabellen gezielt als geprüftes <code>.dfpkg</code> exportieren und importieren.</p>
    </div>
    <div class="actions"><a class="button secondary" href="index.php?project=<?=$projectId?>">Zum Workspace</a></div>
</header>
<?php if($error):?><div class="notice error" role="alert"><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="notice success" role="status"><?=e($success)?></div><?php endif;?>

<div class="df-workspace-grid">
<aside class="df-explorer"><div class="df-pane-title">Explorer</div><nav>
<a href="index.php?project=<?=$projectId?>">Übersicht</a>
<a href="index.php?project=<?=$projectId?>&section=dataforms">DataForms</a>
<a href="relations.php?project=<?=$projectId?>">Beziehungen</a>
<a href="workflow.php?project=<?=$projectId?>">Workflow</a>
<a href="modules.php?project=<?=$projectId?>">Module</a>
<a class="active" href="packages.php?project=<?=$projectId?>">Projektpakete</a>
</nav></aside>

<main class="df-main">
<?php if($licenseCanExport):?>
<section class="card">
<h2>Projekt exportieren</h2>
<p>Bestimmen Sie ausdrücklich, welche Ebenen in das Paket aufgenommen werden. Tabellen, die von ausgewählten DataForms oder Basistabellen-Lookups benötigt werden, werden zusätzlich automatisch als Abhängigkeit aufgenommen.</p>
<div id="df-package-loaded-package" class="notice df-package-loaded-package" hidden aria-live="polite"></div>
<form method="post" action="packages.php?project=<?=$projectId?>" class="form-grid" id="df-package-export-form">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="project" value="<?=$projectId?>">
<input type="hidden" name="action" value="export">

<div class="full df-package-box">
    <h3>1. Paketname</h3>
    <p>Der Name des aktuellen Projekts und der tatsächlich verwendete Paketname werden hier ausdrücklich angezeigt. Optional können Sie für den Export einen eigenen Namen vergeben.</p>

    <div class="df-package-project-name">
        <span>Aktuelles Projekt</span>
        <strong><?=e((string)($project['name']??'DataForm-Projekt'))?></strong>
        <small>Dieser Projektname bleibt Bestandteil der Paket-Metadaten.</small>
    </div>

    <div class="df-package-name-grid">
        <label>
            <strong>Standardname – automatisch (Paket)</strong>
            <input id="df-standard-package-name" type="text" value="<?=e((string)($project['name']??'DataForm-Projekt'))?>" readonly>
            <small>Wird als Anzeigename verwendet, wenn kein eigener Paketname eingetragen ist.</small>
        </label>
        <label>
            <strong>Eigener Paketname – optional</strong>
            <input id="df-custom-package-name" type="text" name="package_name" maxlength="120" autocomplete="off" placeholder="z. B. ed-events-demo">
            <small>Der eingegebene Name wird sofort unten als wirksamer Paketname angezeigt und für dieses Projekt im Browser beibehalten.</small>
        </label>
    </div>

    <div class="df-package-name-preview" aria-live="polite">
        <div><span>Verwendeter Paketname</span><strong id="df-effective-package-name"><?=e((string)($project['name']??'DataForm-Projekt'))?></strong></div>
        <div><span>Voraussichtlicher Dateiname</span><code id="df-effective-package-file"><?=e((string)($project['slug']??'dataform-project'))?>-1.1.0-YYYYMMDD_HHMMSS.dfpkg</code></div>
    </div>
</div>

<div class="full df-package-box">
    <h3>2. Paketbestandteile</h3>
    <div class="df-package-options">
        <label><input type="checkbox" name="include_dataforms" value="1" checked> <strong>DataForms und Feld-/Layout-Konfiguration</strong><small>Exportiert die ausgewählten DataForms einschließlich Feldern, Layout, Verhalten, Versionen und DataForm-Konfiguration.</small></label>
        <label><input type="checkbox" name="include_relations" value="1" checked> <strong>Beziehungen und Lookups</strong><small>Exportiert 1:n-, n:1-/Lookup- und n:m-Beziehungen der ausgewählten DataForms.</small></label>
        <label><input type="checkbox" name="include_bindings" value="1" checked> <strong>Tabellenbindungen</strong><small>Speichert die persistente Zuordnung „DataForm → physische Tabelle“.</small></label>
        <label><input type="checkbox" name="include_table_schema" value="1" checked> <strong>Basistabellen / physische Tabellenschemata</strong><small>Ermöglicht, benötigte Anwendungstabellen beim Import in einem leeren Projekt neu anzulegen.</small></label>
        <label><input type="checkbox" name="include_workflow" value="1" checked> <strong>Workflows und Regeln</strong><small>Exportiert Workflow-Zustände, Übergänge, Aktionen und Berechtigungen der ausgewählten DataForms.</small></label>
        <label><input type="checkbox" name="include_modules" value="1"> <strong>Projektmodule</strong><small>Optional: aktuelle DataForm-Modulkonfiguration des Projekts aufnehmen.</small></label>
        <label><input type="checkbox" name="include_records" value="1"> <strong>Datensätze als Beispieldaten</strong><small>Exportiert die realen Datensätze der ausgewählten/benötigten Basistabellen und Legacy-DataForm-Datensätze. Für Demo-/Testpakete aktivieren.</small></label>
    </div>
</div>

<div class="full df-package-box">
    <div class="df-package-heading"><div><h3>3. DataForms auswählen</h3><p>Nur markierte DataForms werden als Anwendungsformulare exportiert.</p></div><div class="actions"><button type="button" class="button secondary" <?= easyit_button_attributes('auswahl_alle') ?> data-check-all="dataform_ids[]">Alle</button><button type="button" class="button secondary" <?= easyit_button_attributes('auswahl_keine') ?> data-check-none="dataform_ids[]">Keine</button></div></div>
    <?php if(!$catalog['dataforms']):?>
        <div class="empty-state">Keine DataForms vorhanden.</div>
    <?php else:?><div class="df-package-selector">
    <?php foreach($catalog['dataforms'] as $df):?>
        <label class="df-package-item<?=empty($df['license_export_allowed'])?' disabled':''?>">
            <input type="checkbox" name="dataform_ids[]" value="<?=(int)$df['id']?>" <?=empty($df['license_export_allowed'])?'disabled':''?>>
            <span><strong><?=e((string)$df['name'])?></strong><small><code><?=e((string)$df['slug'])?></code><?php if((string)($df['table_name']??'')!==''):?> · gebunden an <code><?=e((string)$df['table_name'])?></code><?php endif;?><?=empty($df['license_export_allowed'])?' · Export für dieses DataForm nicht lizenziert':''?></small></span>
        </label>
    <?php endforeach;?>
    </div><?php endif;?>
</div>

<div class="full df-package-box">
    <div class="df-package-heading"><div><h3>4. Basistabellen auswählen</h3><p>Physische Tabellen für Schema und – falls aktiviert – Beispieldaten. Gebundene Tabellen und Basistabellen-Lookup-Ziele werden automatisch ergänzt.</p></div><div class="actions"><button type="button" class="button secondary" <?= easyit_button_attributes('auswahl_alle') ?> data-check-all="base_tables[]">Alle</button><button type="button" class="button secondary" <?= easyit_button_attributes('auswahl_keine') ?> data-check-none="base_tables[]">Keine</button></div></div>
    <?php if(!$catalog['tables']):?>
        <div class="empty-state">Keine exportierbaren Anwendungstabellen vorhanden.</div>
    <?php else:?><div class="df-package-selector">
    <?php foreach($catalog['tables'] as $table):?>
        <label class="df-package-item">
            <input type="checkbox" name="base_tables[]" value="<?=e((string)$table['name'])?>">
            <span><strong><code><?=e((string)$table['name'])?></code></strong><small>physische Basistabelle · ca. <?=(int)$table['rows']?> Datensätze</small></span>
        </label>
    <?php endforeach;?>
    </div><?php endif;?>
</div>

<div class="full notice">
<strong>HF76-Paketlogik:</strong> Für ein selbsttragendes Paket werden Paketname, DataForm-Konfiguration, Beziehungen, Tabellenbindung und die ausgewählten physischen Tabellenschemata getrennt gespeichert. Bei aktivierten Beispieldaten bleiben die IDs erhalten, damit 1:n- und Lookup-Referenzen zusammenpassen. Datei-/Bildinhalte werden dedupliziert in das Paket aufgenommen und beim Import zielprojektbezogen neu gespeichert.
</div>
<div class="full actions"><button class="button" data-crud="create">.dfpkg erzeugen, speichern und herunterladen</button></div>
</form>
</section>
<?php endif;?>

<section class="card">
<h2>Projektpaket importieren</h2>
<?php if(!$preview):?>
<form method="post" enctype="multipart/form-data" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$projectId?>"><input type="hidden" name="action" value="preview_import">
<label class="full">Projektpaket<input type="file" name="project_package" accept=".dfpkg,.zip,application/zip" required></label>
<div class="full actions"><button class="button">Paket prüfen</button></div>
</form>
<?php else:$m=$preview['manifest'];?>
<div class="notice"><strong><?=e((string)($m['packageName']??$m['project']['name']??'Projekt'))?></strong> · Projekt <?=e((string)($m['project']['name']??'Projekt'))?> · Paketversion <?=e((string)($m['version']??''))?><br>Paket-ID <code><?=e((string)($m['packageId']??''))?></code><br>SHA-256 <code><?=e((string)$preview['sha256'])?></code></div>

<h3>Konfiguration</h3>
<?php if(!$preview['tables']):?><div class="empty-state">Keine Konfigurationsdaten.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Tabelle</th><th>Einträge</th></tr></thead><tbody><?php foreach($preview['tables'] as $table=>$count):?><tr><td><code><?=e($table)?></code></td><td><?=$count?><?= $table==='dataform_relations' && (int)$count>0 ? ' · Details unten' : '' ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>

<h3>Beziehungen und Lookups im Paket</h3>
<?php $previewRelations=(array)($preview['relations']??[]); ?>
<?php if(!$previewRelations):?>
<?php $relationCount=(int)(($preview['tables']['dataform_relations']??0)); ?>
<div class="empty-state"><?= $relationCount>0 ? 'Beziehungsdetails konnten aus diesem Vorschauzustand nicht rekonstruiert werden. Vorschau verwerfen und Paket erneut prüfen.' : 'Keine Beziehungen oder Lookups im Paket.' ?></div>
<?php else:?>
<div class="table-wrap"><table>
<thead><tr><th>Name</th><th>Typ</th><th>Ausgang / Eltern</th><th>Ziel / Referenz</th><th>Zuordnung</th><th>Anzeige</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($previewRelations as $relation):?>
<tr>
<td><?=e((string)($relation['name']??''))?></td>
<td><span class="badge"><?=e((string)($relation['type']??''))?></span></td>
<td><?=e((string)($relation['source']??''))?></td>
<td><?=e((string)($relation['target']??''))?></td>
<td><code><?=e((string)($relation['mapping']??''))?></code></td>
<td><?=e((string)($relation['display']??''))?></td>
<td><?=!empty($relation['enabled'])?'aktiv':'inaktiv'?><?=!empty($relation['required'])?' · Pflicht':''?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>

<h3>Medien</h3>
<div class="metric-grid"><div class="metric"><strong><?= (int)($preview['media']??0) ?></strong><span>portable Dateien/Bilder</span></div></div>
<p><small>Dateien und Bilder werden anhand ihrer SHA-256-Prüfsumme geprüft und beim Import über den Speichertreiber des Zielfelds neu abgelegt.</small></p>

<h3>Physische Tabellenschemata</h3>
<?php if(!$preview['schemas']):?><div class="empty-state">Keine physischen Tabellenschemata.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Basistabelle</th><th>Importwirkung</th></tr></thead><tbody><?php foreach($preview['schemas'] as $table):?><tr><td><code><?=e((string)$table)?></code></td><td>Wird angelegt, falls sie im Zielprojekt noch nicht existiert.</td></tr><?php endforeach;?></tbody></table></div><?php endif;?>

<h3>Beispieldaten physischer Tabellen</h3>
<?php if(!$preview['records']):?><div class="empty-state">Keine physischen Beispieldaten.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Basistabelle</th><th>Datensätze</th></tr></thead><tbody><?php foreach($preview['records'] as $table=>$count):?><tr><td><code><?=e((string)$table)?></code></td><td><?=(int)$count?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>

<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$projectId?>"><input type="hidden" name="action" value="import"><label>Konfliktstrategie<select name="policy"><option value="merge">Vorhandene IDs aktualisieren</option><option value="skip">Vorhandene IDs überspringen</option></select></label><div class="full actions"><button class="button">Geprüftes Paket importieren</button></div></form>
<form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$projectId?>"><input type="hidden" name="action" value="cancel"><button class="button secondary">Vorschau verwerfen</button></form>
<?php endif;?>
</section>

<?php if($importResult):?><section class="card"><h2>Importergebnis</h2><div class="metric-grid"><div class="metric"><strong><?=$importResult['inserted']?></strong><span>neu</span></div><div class="metric"><strong><?=$importResult['updated']?></strong><span>aktualisiert</span></div><div class="metric"><strong><?=$importResult['skipped']?></strong><span>übersprungen</span></div><div class="metric"><strong><?=(int)($importResult['schemas']['created']??0)?></strong><span>Tabellen neu</span></div><div class="metric"><strong><?=(int)($importResult['media']['restored']??0)?></strong><span>Medien neu abgelegt</span></div></div></section><?php endif;?>

<?php if($licenseCanExport):?>
<section class="card">
<div class="df-package-heading">
    <div>
        <h2>Gespeicherte Pakete</h2>
        <p>Dies ist die aktuelle Paketablage des Projekts. <strong>Klicken Sie auf eine Paketzeile, um deren gespeicherte Konfiguration oben in das Exportformular zu laden.</strong> Die CRUD-Aktionen arbeiten direkt auf der jeweiligen <code>.dfpkg</code>-Datei. HF50 löst Aktionen über Paket-ID <em>und</em> Dateiname auf und behandelt bereits entfernte Dateien idempotent.</p>
    </div>
    <div class="df-package-crud-legend"><span><strong>C</strong> Export oben</span><span><strong>R</strong> Herunterladen</span><span><strong>U</strong> Bearbeiten</span><span><strong>D</strong> Löschen</span></div>
</div>
<?php if(!$storedPackages):?>
<div class="empty-state">Noch keine gespeicherten Pakete für dieses Projekt vorhanden.</div>
<?php else:?>
<div class="table-wrap"><table class="df-package-store-table"><thead><tr><th>Paketname</th><th>Version</th><th>Datei</th><th>Größe</th><th>SHA-256</th><th>Aktionen</th></tr></thead><tbody>
<?php foreach($storedPackages as $pkg):
    $pkgRow='pkg-'.substr(sha1((string)$pkg['file_name']),0,12);
    $loadConfig=[
        'fileName'=>(string)$pkg['file_name'],
        'packageId'=>(string)$pkg['package_id'],
        'packageName'=>(string)$pkg['package_name'],
        'packageNameMode'=>(string)($pkg['package_name_mode']??'standard'),
        'includes'=>(array)($pkg['includes']??[]),
        'selection'=>(array)($pkg['selection']??[]),
    ];
    $loadToken=base64_encode((string)json_encode($loadConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
?>
<tr class="df-package-store-row" tabindex="0" role="button" aria-label="Paketkonfiguration <?=e((string)$pkg['package_name'])?> in das Exportformular laden" data-package-id="<?=e((string)$pkg['package_id'])?>" data-package-load="<?=e($loadToken)?>" data-package-href="packages.php?project=<?=$projectId?>&amp;load_package=<?=e(rawurlencode((string)$pkg['package_id']))?>#df-package-export-form">
    <td><strong><a class="df-package-load-link" href="packages.php?project=<?=$projectId?>&amp;load_package=<?=e(rawurlencode((string)$pkg['package_id']))?>#df-package-export-form"><?=e((string)$pkg['package_name'])?></a></strong><br><small>Projekt: <?=e((string)$pkg['project_name'])?></small></td>
    <td><?=e((string)$pkg['version'])?></td>
    <td><code><?=e((string)$pkg['file_name'])?></code><br><small><?=e((string)($pkg['updated_at']?:$pkg['created_at']))?></small></td>
    <td><?=e(number_format(((int)$pkg['size'])/1024,1,',','.'))?> KB</td>
    <td><code title="<?=e((string)$pkg['sha256'])?>"><?=e(substr((string)$pkg['sha256'],0,12))?>…</code></td>
    <td><div class="df-package-crud-actions">
        <a class="button" data-crud="read" href="packages.php?project=<?=$projectId?>&amp;download_package_id=<?=e(rawurlencode((string)$pkg['package_id']))?>&amp;download_package=<?=e(rawurlencode((string)$pkg['file_name']))?>">Herunterladen</a>
        <button class="button" data-crud="edit" type="button" data-package-edit="<?=$pkgRow?>">Bearbeiten</button>
        <form method="post" action="packages.php?project=<?=$projectId?>" class="inline-form" onsubmit="return confirm('Gespeichertes Paket „<?=e((string)$pkg['package_name'])?>“ wirklich löschen? Die Paketdatei wird entfernt; die Paket-Historie bleibt erhalten.');">
            <input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$projectId?>"><input type="hidden" name="action" value="delete_package"><input type="hidden" name="package_id" value="<?=e((string)$pkg['package_id'])?>"><input type="hidden" name="package_file" value="<?=e((string)$pkg['file_name'])?>">
            <button class="button" data-crud="delete" type="submit">Löschen</button>
        </form>
    </div></td>
</tr>
<tr id="<?=$pkgRow?>" class="df-package-edit-row" hidden><td colspan="6">
    <form method="post" action="packages.php?project=<?=$projectId?>" class="df-package-rename-form">
        <input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$projectId?>"><input type="hidden" name="action" value="rename_package"><input type="hidden" name="package_id" value="<?=e((string)$pkg['package_id'])?>"><input type="hidden" name="package_file" value="<?=e((string)$pkg['file_name'])?>">
        <label><strong>Neuer Paketname</strong><input type="text" name="new_package_name" maxlength="120" required value="<?=e((string)$pkg['package_name'])?>"></label>
        <div class="actions"><button class="button" data-crud="save" type="submit">Änderung speichern</button><button class="button secondary" type="button" data-package-cancel="<?=$pkgRow?>">Abbrechen</button></div>
        <small>Bearbeiten aktualisiert Paketname, Dateiname und die Paket-Metadaten im Archiv. Die Paket-ID bleibt erhalten.</small>
    </form>
</td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<section class="card"><h2>Paket-Historie</h2><p class="muted">Unveränderliches Auditprotokoll aller Export-, Import-, Bearbeitungs- und Löschaktionen. CRUD erfolgt ausschließlich in „Gespeicherte Pakete“.</p><?php if(!$history):?><div class="empty-state">Noch keine Projektpakete exportiert oder importiert.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Aktion</th><th>Projekt</th><th>Paketname</th><th>Version</th><th>Datei</th><th>Status</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=e((string)$h['created_at'])?></td><td><?=e((string)$h['action_name'])?></td><td><?=e((string)$h['project_name'])?></td><td><strong><?=e((string)($h['package_name']??$h['project_name']))?></strong></td><td><?=e((string)$h['package_version'])?></td><td><?=e((string)$h['file_name'])?></td><td><?=e((string)$h['status'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php endif;?>
</main>

<aside class="df-properties"><div class="df-pane-title">Kontexthilfe</div><h3>Ziel</h3><p>Eine vollständige oder gezielt reduzierte DataForm-Anwendung portabel sichern.</p><h3>DataForms</h3><p>Wählen Sie nur Formulare, die Bestandteil des Pakets sein sollen. Tabellenbindungen werden separat exportiert.</p><h3>Basistabellen</h3><p>Für ein auf einem leeren Projekt lauffähiges Paket müssen die physischen Tabellenstrukturen enthalten sein. Abhängigkeiten aus Bindings und Basistabellen-Lookups ergänzt HF44 automatisch.</p><h3>Datensätze</h3><p>Aktivieren Sie Beispieldaten nur für Demo-/Testpakete. Die Original-IDs bleiben zur Wahrung der Beziehungen erhalten.</p></aside>
</div></div>

<style>
.df-package-box{border:1px solid #d8e1ed;border-radius:10px;padding:14px;background:#fbfdff}.df-package-name-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.df-package-name-grid label{display:block}.df-package-name-grid input{width:100%;margin-top:6px}.df-package-name-grid small{display:block;color:#5f6f82;margin-top:5px;line-height:1.35}.df-package-box h3{margin:0 0 8px}.df-package-box p{margin:2px 0 8px;color:#526172}.df-package-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.df-package-options label,.df-package-item{display:flex;align-items:flex-start;gap:9px;border:1px solid #dde5ef;border-radius:8px;padding:10px;background:#fff}.df-package-options small,.df-package-item small{display:block;color:#5f6f82;margin-top:3px;line-height:1.35}.df-package-selector{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:330px;overflow:auto}.df-package-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.df-package-heading .actions{display:flex;gap:6px}.df-package-heading .button{padding:6px 10px}.df-package-item input{margin-top:3px}.df-package-project-name{display:flex;flex-direction:column;gap:3px;border:1px solid #b9d0ec;border-radius:9px;padding:11px 12px;margin:0 0 10px;background:#eef6ff}.df-package-project-name span,.df-package-name-preview span{font-size:.82rem;color:#526172}.df-package-project-name strong{font-size:1.08rem}.df-package-project-name small{color:#5f6f82}.df-package-name-preview{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:10px}.df-package-name-preview>div{display:flex;flex-direction:column;gap:4px;border:1px solid #cbd9e8;border-radius:8px;padding:10px;background:#fff}.df-package-name-preview strong{font-size:1.05rem}.df-package-name-preview code{white-space:normal;overflow-wrap:anywhere}.df-package-crud-legend{display:flex;gap:8px;flex-wrap:wrap}.df-package-crud-legend span{border:1px solid #d7e1ed;border-radius:999px;padding:5px 8px;background:#fff;font-size:.82rem}.df-package-crud-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.df-package-crud-actions .inline-form{display:inline-flex;margin:0}.df-package-edit-row td{background:#f7fbff}.df-package-rename-form{display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:10px;align-items:end}.df-package-rename-form label{display:grid;gap:5px}.df-package-rename-form input{width:100%}.df-package-rename-form small{grid-column:1/-1;color:#5f6f82}.df-package-store-table td{vertical-align:middle}.df-package-store-row{cursor:pointer;transition:background .15s ease,box-shadow .15s ease}.df-package-store-row:hover td,.df-package-store-row:focus td{background:#eef6ff}.df-package-store-row.is-selected td{background:#e2f0ff;box-shadow:inset 0 1px 0 #9cc4ef,inset 0 -1px 0 #9cc4ef}.df-package-load-link{color:inherit;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}.df-package-load-link:hover{color:#0b4f91}.df-package-loaded-package{margin:8px 0 12px}.df-package-loaded-package strong{display:inline-block;margin-right:4px}@media(max-width:1000px){.df-package-name-grid,.df-package-name-preview,.df-package-options,.df-package-selector,.df-package-rename-form{grid-template-columns:1fr}}
</style>
<script>
(function(){
    var custom=document.getElementById('df-custom-package-name');
    var standard=document.getElementById('df-standard-package-name');
    var effective=document.getElementById('df-effective-package-name');
    var file=document.getElementById('df-effective-package-file');
    var projectId=<?=json_encode((string)$projectId)?>;
    var projectSlug=<?=json_encode((string)($project['slug']??'dataform-project'))?>;
    var storageKey='easyit.dataform.packageName.'+projectId;
    var exportStateKey='easyit.dataform.packageExportState.'+projectId;

    function fileBase(value){
        value=(value||'').trim();
        if(!value)return projectSlug||'dataform-project';
        try{value=value.normalize('NFD').replace(/[\u0300-\u036f]/g,'');}catch(e){}
        value=value.replace(/[^a-zA-Z0-9_-]+/g,'-').replace(/-+/g,'-').replace(/^[-_]+|[-_]+$/g,'');
        return value||'dataform-project';
    }
    function updatePackageName(){
        if(!custom||!standard||!effective||!file)return;
        var entered=custom.value.trim();
        var display=entered||standard.value;
        effective.textContent=display;
        file.textContent=fileBase(entered)+'-1.1.0-YYYYMMDD_HHMMSS.dfpkg';
        try{localStorage.setItem(storageKey,custom.value);}catch(e){}
    }

    // HF54: keep the complete export selection stable while a package download
    // is followed by a page refresh/redirect. The stored package remains the
    // authoritative long-term configuration; sessionStorage is only a fallback
    // for browsers/proxies that do not expose the package-ID response header.
    function currentExportState(){
        if(!exportForm)return null;
        var state={packageName:custom?custom.value:'',checks:{},dataformIds:[],baseTables:[]};
        exportForm.querySelectorAll('input[type="checkbox"]').forEach(function(input){
            if(input.name==='dataform_ids[]'){if(input.checked)state.dataformIds.push(String(input.value));return;}
            if(input.name==='base_tables[]'){if(input.checked)state.baseTables.push(String(input.value));return;}
            if(input.name){state.checks[input.name]=!!input.checked;}
        });
        return state;
    }
    function persistExportState(){
        var state=currentExportState();
        if(!state)return;
        try{sessionStorage.setItem(exportStateKey,JSON.stringify(state));}catch(e){}
    }
    function restoreExportState(){
        if(!exportForm)return false;
        var state=null;
        try{state=JSON.parse(sessionStorage.getItem(exportStateKey)||'null');}catch(e){}
        if(!state)return false;
        if(custom && typeof state.packageName==='string'){custom.value=state.packageName;updatePackageName();}
        Object.keys(state.checks||{}).forEach(function(name){setNamedCheckbox(name,state.checks[name]);});
        setMultiSelection('dataform_ids',state.dataformIds||[]);
        setMultiSelection('base_tables',state.baseTables||[]);
        try{sessionStorage.removeItem(exportStateKey);}catch(e){}
        return true;
    }

    // HF48: reload the persisted package configuration into the export form.
    function setNamedCheckbox(name,checked){
        var input=exportForm?exportForm.querySelector('input[type="checkbox"][name="'+name+'"]'):null;
        if(input)input.checked=!!checked;
    }
    function setMultiSelection(name,values){
        if(!exportForm)return;
        var wanted={};
        (values||[]).forEach(function(value){wanted[String(value)]=true;});
        exportForm.querySelectorAll('input[type="checkbox"][name="'+name+'[]"]').forEach(function(input){
            input.checked=!!wanted[String(input.value)];
        });
    }
    function decodePackageLoad(token){
        try{
            var binary=atob(token||'');
            var bytes=Uint8Array.from(binary,function(c){return c.charCodeAt(0);});
            return JSON.parse(new TextDecoder('utf-8').decode(bytes));
        }catch(e){
            try{return JSON.parse(decodeURIComponent(escape(atob(token||''))));}catch(ignore){return null;}
        }
    }
    function loadStoredPackageConfig(row){
        if(!row||!exportForm)return;
        var config=decodePackageLoad(row.getAttribute('data-package-load'));
        if(!config){
            var fallback=row.getAttribute('data-package-href');
            if(fallback){window.location.href=fallback;}
            return;
        }
        var includes=config.includes||{};
        var selection=config.selection||{};

        if(custom){
            custom.value=(config.packageNameMode==='standard')?'':(config.packageName||'');
            updatePackageName();
        }
        setNamedCheckbox('include_dataforms',includes.dataforms);
        setNamedCheckbox('include_relations',includes.relations);
        setNamedCheckbox('include_bindings',includes.bindings);
        setNamedCheckbox('include_workflow',includes.workflow);
        setNamedCheckbox('include_modules',includes.modules);
        setNamedCheckbox('include_table_schema',includes.tableSchema);
        setNamedCheckbox('include_records',includes.records);
        setMultiSelection('dataform_ids',selection.dataformIds||[]);
        setMultiSelection('base_tables',selection.physicalTables||[]);

        document.querySelectorAll('.df-package-store-row.is-selected').forEach(function(item){item.classList.remove('is-selected');});
        row.classList.add('is-selected');
        // HF49: keep the selected stored package stable across reloads without
        // resolving a possibly stale filename. The package ID is immutable.
        var packageId=row.getAttribute('data-package-id')||config.packageId||'';
        if(packageId && window.history && window.URL){
            try{
                var current=new URL(window.location.href);
                current.searchParams.set('project',projectId);
                current.searchParams.set('load_package',packageId);
                current.searchParams.delete('download_package');
                current.searchParams.delete('download_package_id');
                window.history.replaceState(null,'',current.pathname+'?'+current.searchParams.toString());
            }catch(e){}
        }
        var notice=document.getElementById('df-package-loaded-package');
        if(notice){
            notice.hidden=false;
            notice.textContent='';
            var strong=document.createElement('strong');
            strong.textContent='Gespeicherte Paketkonfiguration geladen:';
            notice.appendChild(strong);
            notice.appendChild(document.createTextNode(' '+(config.packageName||config.fileName||'Paket')+' '));
            var small=document.createElement('small');
            small.textContent='– Änderungen oben erzeugen beim nächsten Export einen neuen Paketstand.';
            notice.appendChild(small);
        }
        exportForm.scrollIntoView({behavior:'smooth',block:'start'});
    }
    if(custom){
        try{var remembered=localStorage.getItem(storageKey);if(remembered!==null)custom.value=remembered;}catch(e){}
        custom.addEventListener('input',updatePackageName);
        custom.addEventListener('change',updatePackageName);
        updatePackageName();
    }

    var exportForm=document.getElementById('df-package-export-form');
    if(exportForm && window.fetch && window.FormData && window.URL){
        exportForm.addEventListener('submit',async function(event){
            event.preventDefault();
            var submit=exportForm.querySelector('button[type="submit"],button:not([type])');
            if(submit){submit.disabled=true;}
            try{
                var response=await fetch(exportForm.getAttribute('action')||window.location.href,{method:'POST',body:new FormData(exportForm),credentials:'same-origin'});
                var contentType=(response.headers.get('Content-Type')||'').toLowerCase();
                if(!response.ok || contentType.indexOf('application/zip')===-1){
                    var html=await response.text();
                    document.open();document.write(html);document.close();
                    return;
                }
                var packageId=(response.headers.get('X-DataForm-Package-ID')||'').trim();
                var blob=await response.blob();
                var disposition=response.headers.get('Content-Disposition')||'';
                var match=disposition.match(/filename="?([^";]+)"?/i);
                var filename=match?match[1]:'dataform-project.dfpkg';
                var url=URL.createObjectURL(blob);
                var a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();
                // HF47 compatibility marker: window.location.reload() used to refresh
                // the repository here. HF54 now reloads the exact package by ID.
                // Preserve the exact DataForm/table checkboxes after export. If
                // possible, reload the just-created stored package by immutable
                // package ID. Otherwise restore the transient browser state.
                persistExportState();
                setTimeout(function(){
                    URL.revokeObjectURL(url);
                    if(packageId){
                        window.location.href='packages.php?project='+encodeURIComponent(projectId)+'&load_package='+encodeURIComponent(packageId)+'#df-package-export-form';
                    }else{
                        window.location.href='packages.php?project='+encodeURIComponent(projectId)+'#df-package-export-form';
                    }
                },250);
            }catch(error){
                if(submit){submit.disabled=false;}
                alert('Paketexport fehlgeschlagen: '+(error&&error.message?error.message:error));
            }
        });
    }

    document.addEventListener('click',function(event){
        var packageRow=event.target.closest('.df-package-store-row[data-package-load]');
        if(packageRow && !event.target.closest('a,button,input,select,textarea,label,form')){
            loadStoredPackageConfig(packageRow);
            return;
        }
        var edit=event.target.closest('[data-package-edit]');
        if(edit){
            var row=document.getElementById(edit.getAttribute('data-package-edit'));
            if(row){row.hidden=!row.hidden;if(!row.hidden){var input=row.querySelector('input[name="new_package_name"]');if(input){input.focus();input.select();}}}
            return;
        }
        var cancel=event.target.closest('[data-package-cancel]');
        if(cancel){var cancelRow=document.getElementById(cancel.getAttribute('data-package-cancel'));if(cancelRow){cancelRow.hidden=true;}return;}
        var all=event.target.closest('[data-check-all]');
        var none=event.target.closest('[data-check-none]');
        var button=all||none;
        if(!button)return;
        var name=all?all.getAttribute('data-check-all'):none.getAttribute('data-check-none');
        document.querySelectorAll('#df-package-export-form input[name="'+name+'"]')
            .forEach(function(input){input.checked=!!all;});
    });
    document.querySelectorAll('.df-package-store-row[data-package-load]').forEach(function(row){
        row.addEventListener('keydown',function(event){
            if(event.key==='Enter'||event.key===' '){
                event.preventDefault();
                loadStoredPackageConfig(row);
            }
        });
    });

    // HF49: a selected package survives page reloads by stable package ID.
    var requestedLoadPackageId=<?=json_encode($requestedLoadPackageId)?>;
    if(requestedLoadPackageId){
        var requestedRow=null;
        document.querySelectorAll('.df-package-store-row[data-package-id]').forEach(function(row){
            if(!requestedRow && row.getAttribute('data-package-id')===requestedLoadPackageId){requestedRow=row;}
        });
        if(requestedRow){
            loadStoredPackageConfig(requestedRow);
        }else{
            var missingNotice=document.getElementById('df-package-loaded-package');
            if(missingNotice){
                missingNotice.hidden=false;
                missingNotice.classList.add('warning');
                missingNotice.textContent='Das zuvor ausgewählte Paket ist nicht mehr in der aktuellen Paketablage vorhanden. Bitte wählen Sie unten einen vorhandenen Paketstand aus.';
            }
        }
    }else{
        // HF54 fallback for an export response where the package-ID header was
        // stripped by a proxy/browser: restore the previous selections once.
        restoreExportState();
    }

})();
</script>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'DataForm-Projektpakete',
    'active'=>'projects',
    'base'=>'../../',
    'content'=>$content,
    'app_nav'=>true,
    'user'=>$user,
    'body_class'=>'workspace-page',
    'styles'=>['products/dataform/assets/workspace.css'],
    'help'=>[
        'title'=>'Projektpakete',
        'location'=>'DataForm → Projektpakete',
        'goal'=>'DataForms, Beziehungen und physische Tabellen exportieren/importieren und gespeicherte Projektpakete mit vollständigem CRUD verwalten.',
        'next'=>'Paket auf einem leeren Testprojekt importieren und Runtime prüfen.',
        'duration'=>'ca. 5 Minuten',
    ],
]);
