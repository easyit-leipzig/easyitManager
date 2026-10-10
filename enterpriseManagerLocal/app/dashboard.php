<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';
require dirname(__DIR__).'/system/ui/layout.php';
require dirname(__DIR__).'/system/licensing/Autoload.php';

use EasyIT\Enterprise\Licensing\LicenseApiClient;

/*
 * HF152: Dashboard hard gate.
 * Ohne vollständig eingerichtete Lizenzserver-Kopplung darf das Dashboard
 * nicht geöffnet werden. Die Installation ist der einzige zulässige Einstieg.
 */
$enterpriseRoot=dirname(__DIR__);
$dashboardLicenseCfg=EnterpriseLicenseConfigStore::load($enterpriseRoot);
$dashboardLicenseReady=
    is_array($dashboardLicenseCfg)
    && !empty($dashboardLicenseCfg['enabled'])
    && trim((string)($dashboardLicenseCfg['server_url']??''))!==''
    && !empty($dashboardLicenseCfg['trusted_server_keys'])
    && is_array($dashboardLicenseCfg['trusted_server_keys']);
if(!$dashboardLicenseReady){
    header('Location: ../setup.php?required=license-server#license-server');
    exit;
}

$user=enterprise_require_auth('../');
if(!empty($user['customer'])){
    $isDemo=enterprise_is_demo($user);$surfaceAllowed=enterprise_surface_allowed($user);$error='';$message='';$projects=[];$portal=['products'=>[],'project_requests'=>[],'project_entitlements'=>[]];
    $root=dirname(__DIR__);require_once $root.'/system/licensing/LicenseConfigStore.php';$cfg=EnterpriseLicenseConfigStore::load($root);if(!is_array($cfg))$cfg=[];$token=(string)($_SESSION['enterprise_customer_account_token']??'');
    try{
        $pdo=enterprise_pdo();enterprise_upgrade($pdo);$projects=$pdo->query("SELECT id,name,slug,product_type,status FROM projects ORDER BY name,id")->fetchAll();if(!$isDemo)$projects=enterprise_filter_projects_for_scope($user,$projects);
        require_once dirname(__DIR__).'/system/licensing/Autoload.php';$api=new LicenseApiClient($cfg);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!$surfaceAllowed)throw new RuntimeException('SURFACE_POLICY_NOT_READY');
            enterprise_check_csrf((string)($_POST['csrf']??''));$action=(string)($_POST['action']??'');
            if($action==='request_project_product'){
                $productCode=strtolower(trim((string)($_POST['product_code']??'')));$selected=(string)($_POST['project_id']??'');$projectId='';$projectName='';$projectSlug='';
                if($selected!==''&&$selected!=='planned'){
                    foreach($projects as $pr)if((string)$pr['id']===$selected){$projectId=(string)$pr['id'];$projectName=(string)$pr['name'];$projectSlug=(string)$pr['slug'];break;}
                    if($projectId==='')throw new RuntimeException('Das ausgewählte Projekt wurde nicht gefunden.');
                }else{
                    $projectName=trim((string)($_POST['planned_project_name']??''));$projectSlug=strtolower(trim((string)($_POST['planned_project_slug']??'')));$projectSlug=preg_replace('/[^a-z0-9-]+/','-',$projectSlug)??'';$projectSlug=trim($projectSlug,'-');
                    if($projectName===''||$projectSlug==='')throw new RuntimeException('Für ein geplantes Projekt werden Name und Slug benötigt.');$projectId='planned:'.$projectSlug;
                }
                $portal=$api->requestAccountProjectProduct($token,['project_id'=>$projectId,'project_name'=>$projectName,'project_slug'=>$projectSlug,'product_code'=>$productCode,'term_months'=>(string)($_POST['term_months']??'12'),'notes'=>(string)($_POST['notes']??'')]);$message='Die Projektlizenz für '.$projectName.' wurde angefordert.';
            }elseif($action==='cancel_project_product_request'){
                $portal=$api->cancelAccountProjectProductRequest($token,(string)($_POST['request_id']??''));$message='Die offene Projektlizenzanfrage wurde zurückgezogen.';
            }
        }
        if(!$isDemo&&$surfaceAllowed&&!$portal['products'])$portal=$api->accountProjectProducts($token);
    }catch(Throwable $e){$error=$e->getMessage();}
    $customerKey=(string)($user['customer_key']??$user['email']??'');$localModules=enterprise_product_module_registry();$entitlements=(array)($portal['project_entitlements']??[]);$requests=(array)($portal['project_requests']??[]);$products=(array)($portal['products']??[]);
    $projectById=[];foreach($projects as $pr)$projectById[(string)$pr['id']]=$pr;
    $requestState=function(string $productCode,array $project)use($requests):?array{$pid=(string)$project['id'];$slug=(string)($project['slug']??'');foreach($requests as $r)if((string)($r['product_code']??'')===$productCode&&(string)($r['status']??'')==='pending'&&((string)($r['project_id']??'')===$pid||($slug!==''&&(string)($r['project_slug']??'')===$slug)))return$r;return null;};
    $entState=function(string $productCode,array $project)use($entitlements):?array{$pid=(string)$project['id'];$slug=(string)($project['slug']??'');$now=time();foreach($entitlements as $e)if((string)($e['product_code']??'')===$productCode&&(string)($e['status']??'')==='active'&&((string)($e['project_id']??'')===$pid||($slug!==''&&(string)($e['project_slug']??'')===$slug))&&(($e['valid_until']??null)===null||(int)$e['valid_until']>=$now))return$e;return null;};
    ob_start();render_breadcrumbs([['label'=>'Enterprise','href'=>'dashboard.php'],['label'=>$isDemo?'Demo':'Dashboard','href'=>'']]);?>
    <section class="hero"><span class="badge"><?=$isDemo?'Demo-Version':'Kundenbereich'?></span><h1>Enterprise-Manager</h1><p>Kundenschlüssel: <strong><?=e($customerKey)?></strong>. Produkte werden als Module angeboten und projektbezogen freigegeben.</p><div class="actions"><a class="button secondary" href="account.php">Mein Konto</a><a class="button secondary" href="../logout.php">Abmelden</a></div></section>
    <?php if(isset($_SESSION['enterprise_license_transition_notice'])):$transitionNotice=(string)$_SESSION['enterprise_license_transition_notice'];unset($_SESSION['enterprise_license_transition_notice']);?><div class="notice success"><?=e($transitionNotice)?></div><?php endif;?>
    <?php if(!$isDemo&&!$surfaceAllowed):?><div class="notice error"><strong>Lizenz aktiv – Domain/IP noch nicht freigegeben.</strong><br>Die Lizenz wurde übernommen und an diese Installation gebunden. Produktive Funktionen bleiben bis zur Herkunftsfreigabe gesperrt. <a href="account.php">Jetzt unter „Mein Konto“ freigeben</a>.</div><?php endif;?>
    <?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
    <?php if($isDemo):?><div class="notice"><strong>Demo-Modus aktiv.</strong> Produktmodule und vorhandene Projektbezeichnungen bleiben sichtbar. Produktive Inhalte werden erst nach einer projektbezogenen Freigabe geladen.</div><?php endif;?>
    <div class="metric-grid"><div class="metric"><strong><?=count($products)?></strong><span>Produkte im Katalog</span></div><div class="metric"><strong><?=count($projects)?></strong><span>lokale Projekte</span></div><div class="metric"><strong><?=count(array_filter($requests,fn($r)=>(string)($r['status']??'')==='pending'))?></strong><span>offene Projektanfragen</span></div><div class="metric"><strong><?=count(array_filter($entitlements,fn($e)=>(string)($e['status']??'')==='active'))?></strong><span>aktive Projektfreigaben</span></div></div>
    <section><div class="section-head"><div><h2>Produkte & Module</h2><p>Wählen Sie je Produkt das gewünschte Projekt. Eine Freigabe gilt nur für diese Enterprise-Installation und dieses Projekt.</p></div><a href="account.php#license-request">Gesamtlizenz differenziert anfragen</a></div>
    <div class="cards product-grid">
    <?php foreach($products as $product):$code=(string)$product['product_code'];$local=$localModules[$code]??['installed'=>false,'entry'=>null,'label'=>$product['name']??$code];?>
      <article class="card"><div class="title-row"><div><span class="status-pill <?=e((string)$product['status'])?>"><?=e((string)$product['status'])?></span><h2><?=e((string)$product['name'])?></h2></div><span class="badge"><?=!empty($local['installed'])?'lokal installiert':'Modulpaket nicht installiert'?></span></div>
      <p><?=e((string)($product['description']??''))?></p><p><small>Version: <?=e((string)($product['version']??'—'))?> · Produktcode: <code><?=e($code)?></code></small></p>
      <?php if($projects):?><h3>Projektstatus</h3><div class="table-wrap"><table><thead><tr><th>Projekt</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($projects as $pr):$ent=$entState($code,$pr);$req=$requestState($code,$pr);?><tr><td><?=e((string)$pr['name'])?><br><small><?=e((string)$pr['slug'])?></small></td><td><?php if($ent):?><span class="status-pill available">freigegeben</span><?php elseif($req):?><span class="status-pill planned">angefragt</span><?php else:?>nicht lizenziert<?php endif;?></td><td><?php if($ent):?><?php if(!empty($local['installed'])):?><a class="button" href="products/load.php?project_id=<?=(int)$pr['id']?>&amp;product=<?=rawurlencode($code)?>">Modul laden</a><?php else:?><span class="button secondary" aria-disabled="true">Freigegeben · Paket fehlt</span><?php endif;?><?php elseif($req):?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="cancel_project_product_request"><input type="hidden" name="request_id" value="<?=e((string)$req['request_id'])?>"><button class="button secondary" type="submit">Anfrage zurückziehen</button></form><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="request_project_product"><input type="hidden" name="product_code" value="<?=e($code)?>"><input type="hidden" name="project_id" value="<?=(int)$pr['id']?>"><input type="hidden" name="term_months" value="12"><button class="button" type="submit">Lizenz anfordern</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
      <details><summary>Für ein geplantes Projekt anfragen</summary><form method="post" class="form-grid" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="request_project_product"><input type="hidden" name="product_code" value="<?=e($code)?>"><input type="hidden" name="project_id" value="planned"><label>Projektname<input name="planned_project_name" required></label><label>Projekt-Slug<input name="planned_project_slug" pattern="[a-z0-9][a-z0-9-]{1,158}" required></label><label>Laufzeit<select name="term_months"><option value="12">12 Monate</option><option value="24">24 Monate</option><option value="36">36 Monate</option><option value="0">unbegrenzt angefragt</option></select></label><label class="full">Hinweis<textarea name="notes" rows="3"></textarea></label><div class="full"><button class="button" type="submit">Projektlizenz anfordern</button></div></form></details>
      </article>
    <?php endforeach;?>
    <?php if(!$products):?><div class="empty-state"><h3>Produktkatalog nicht verfügbar</h3><p>Der zentrale Lizenzserver hat derzeit keine sichtbaren Produktmodule geliefert.</p></div><?php endif;?>
    </div></section>
    <?php $content=ob_get_clean();render_page(['title'=>$isDemo?'Demo-Dashboard':'Dashboard','active'=>'dashboard','base'=>'../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Produkt-/Projekt-Dashboard','location'=>'Enterprise → Dashboard','short'=>'Zentraler Produktkatalog mit projektbezogenen Lizenzanfragen.','goal'=>'Produktmodule sehen, je Projekt lizenzieren und nach Freigabe laden.']]);exit;
}
$error='';$projects=[];$products=[];$counts=['projects'=>0,'users'=>0,'products'=>0,'licenses'=>0];
try{
    $pdo=enterprise_pdo();
    enterprise_upgrade($pdo);
    $projects=$pdo->query('SELECT * FROM projects ORDER BY updated_at DESC LIMIT 8')->fetchAll();
    $products=$pdo->query('SELECT * FROM installed_products ORDER BY id')->fetchAll();
    $counts['projects']=(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $counts['users']=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $counts['products']=(int)$pdo->query("SELECT COUNT(*) FROM installed_products WHERE status='available'")->fetchColumn();
    $counts['licenses']=(int)$pdo->query("SELECT COUNT(*) FROM enterprise_licenses WHERE enabled=1")->fetchColumn();
}catch(Throwable $e){$error=$e->getMessage();}

$ops=enterprise_dashboard_snapshot();
try{$moduleCards=enterprise_module_ui()->dashboard($user);}catch(Throwable){$moduleCards=[];}

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'dashboard.php'],['label'=>'Dashboard','href'=>'']]);
?>
<section class="hero">
<span class="badge">RC1.8 · Enterprise Control Center</span>
<h1>Enterprise-Dashboard</h1>
<p>Willkommen, <strong><?=e((string)$user['username'])?></strong>. Projekte, Produkte und Betriebszustand sind hier zusammengeführt.</p>
<div class="actions">
<a class="button" <?= easyit_button_attributes('neu','project') ?> href="projects/create.php">Neues Projekt anlegen</a>
<a class="button secondary" <?= easyit_button_attributes('projekt_registrieren') ?> href="projects/register.php">Vorhandenes Projekt registrieren</a>
<a class="button secondary" <?= easyit_button_attributes('anzeigen','overview') ?> href="operations/index.php">Betriebszentrale</a>
<?php if(enterprise_can($user,'permissions.manage')):?><a class="button secondary" <?= easyit_button_attributes('security_benutzer') ?> href="security/users.php">Benutzer & Rechte</a><?php endif;?><?php if(enterprise_can($user,'permissions.manage')):?><a class="button secondary" <?= easyit_button_attributes('database_benutzer') ?> href="security/database-users.php">DB-Benutzer</a><?php endif;?><?php if(enterprise_can($user,'permissions.manage')):?><a class="button secondary" <?= easyit_button_attributes('verlauf') ?> href="security/audit.php">Audit-Protokoll</a><?php endif;?>
<a class="button secondary" <?= easyit_button_attributes('setup') ?> href="../setup.php">Installation / Setup</a>
<a class="button secondary" <?= easyit_button_attributes('datenbank_assistent') ?> href="../installer/database.php">Datenbank-Assistent</a>
<a class="button secondary" <?= easyit_button_attributes('restore') ?> href="../recovery.php">Recovery / Reset</a>
</div>
</section>

<?php if($error):?>
<div class="notice error">
<strong>Administrationskonfiguration unvollständig.</strong><br>
<?=e($error)?>
<div class="actions" style="margin-top:1rem">
<a class="button" <?= easyit_button_attributes('setup') ?> href="../setup.php">Installation / Setup öffnen</a>
<a class="button secondary" <?= easyit_button_attributes('datenbank_assistent') ?> href="../installer/database.php">Datenbank-Assistent öffnen</a>
<a class="button secondary" <?= easyit_button_attributes('restore') ?> href="../recovery.php">Recovery / Reset</a>
</div>
</div>
<?php endif;?>

<div class="metric-grid">
<div class="metric"><strong><?=$counts['projects']?></strong><span>Projekte</span></div>
<div class="metric"><strong><?=$counts['products']?></strong><span>Produkte</span></div>
<div class="metric"><strong><?=e((string)$ops['modules']['active'])?></strong><span>aktive Module</span></div>
<div class="metric"><strong><?=e(strtoupper((string)$ops['overall']))?></strong><span>Betriebszustand</span></div>
</div>

<section>
<div class="section-head"><h2>Betriebsstatus</h2><a href="operations/index.php">Alle Betriebsdetails</a></div>
<div class="cards product-grid">
<article class="card"><span class="status-pill"><?=e((string)$ops['monitoring']['status'])?></span><h2>Monitoring</h2><p><?=e((string)$ops['monitoring']['alerts'])?> aktive Warnung(en).</p><a class="button secondary" href="monitoring/index.php">Öffnen</a></article>
<article class="card"><span class="status-pill"><?=e((string)$ops['jobs']['status'])?></span><h2>Jobs</h2><p><?=e((string)$ops['jobs']['pending'])?> wartend · <?=e((string)$ops['jobs']['failed'])?> fehlgeschlagen.</p><a class="button secondary" href="background/index.php">Öffnen</a></article>
<article class="card"><span class="status-pill"><?=e((string)$ops['cluster']['status'])?></span><h2>Cluster</h2><p><?=e((string)$ops['cluster']['online'])?> / <?=e((string)$ops['cluster']['nodes'])?> Nodes online.</p><a class="button secondary" href="cluster/index.php">Öffnen</a></article>
<article class="card"><span class="status-pill"><?=e((string)$ops['storage']['status'])?></span><h2>Storage</h2><p><?=e((string)$ops['storage']['healthy'])?> / <?=e((string)$ops['storage']['total'])?> Provider healthy.</p><a class="button secondary" href="storage/index.php">Öffnen</a></article>
</div>
</section>

<section>
<h2>Produkte</h2>
<div class="cards product-grid">
<?php foreach($products as $p):?>
<article class="card">
<span class="status-pill <?=e((string)$p['status'])?>"><?=e((string)$p['status'])?></span>
<h2><?=e((string)$p['label'])?></h2>
<p><?=$p['status']==='available'?'Für Projekte verfügbar.':'Derzeit nicht für neue Projekte aktiviert.'?></p>
</article>
<?php endforeach;?>
</div>
</section>

<?php if($moduleCards!==[]):?>
<section><h2>Modulfunktionen</h2><div class="cards product-grid">
<?php foreach($moduleCards as $card):$href=(string)$card['href'];if(!preg_match('~^(?:https?://|/)~',$href))$href='../'.ltrim($href,'/');?>
<article class="card"><h2><?=e((string)$card['label'])?></h2><p><?=e((string)($card['description']??'Modulfunktion öffnen.'))?></p><a class="button secondary" href="<?=e($href)?>">Öffnen</a></article>
<?php endforeach;?>
</div></section>
<?php endif;?>

<section>
<div class="section-head"><h2>Letzte Projekte</h2><a href="projects/index.php">Alle anzeigen</a></div>
<?php if(!$projects):?>
<div class="empty-state"><h3>Noch kein Projekt vorhanden</h3><p>Legen Sie jetzt das erste Produktprojekt an.</p><a class="button" href="projects/create.php">Projekt anlegen</a></div>
<?php else:?>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Produkt</th><th>Datenbank</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>
<?php foreach($projects as $p):?><tr><td><strong><?=e((string)$p['name'])?></strong></td><td><?=e((string)($p['product_type']??'dataform'))?></td><td><code><?=e((string)$p['database_name'])?></code></td><td><?=e((string)$p['status'])?></td><td><div class="project-crud-actions"><a class="button" <?= easyit_button_attributes('anzeigen','project') ?> href="projects/view.php?id=<?=(int)$p['id']?>">Öffnen</a><?php if(enterprise_can($user,'projects.update')):?><a class="button" <?= easyit_button_attributes('bearbeiten','project') ?> href="projects/edit.php?id=<?=(int)$p['id']?>">Bearbeiten</a><?php endif;?><?php if(enterprise_can($user,'projects.delete')):?><a class="button" <?= easyit_button_attributes('loeschen','project') ?> href="projects/delete.php?id=<?=(int)$p['id']?>">Projekt löschen</a><?php endif;?></div></td></tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<?php
$content=ob_get_clean();
render_page([
    'title'=>'Dashboard','active'=>'dashboard','base'=>'../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'Enterprise Control Center','location'=>'Enterprise → Dashboard',
        'short'=>'Zentrale Übersicht über Projekte, Produkte, Module und Betriebszustand.',
        'goal'=>'Die Plattform aus einer gemeinsamen Übersicht steuern.',
        'next'=>'Bei Warnungen die Betriebszentrale öffnen; andernfalls Projekt oder Produkt auswählen.',
        'steps'=>['Betriebszustand prüfen.','Produkte und Module kontrollieren.','Projekt auswählen oder neu anlegen.'],
        'tips'=>['Technische Detailseiten sind unter Betrieb gebündelt.','Das Dashboard führt nur Status zusammen und verändert nichts automatisch.']
    ]
]);
