<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';
$user=enterprise_require_auth('../../');
$projectId=(int)($_GET['project']??$_GET['project_id']??0);$project=null;$error='';
try{
 if($projectId<1)throw new RuntimeException('Bitte wählen Sie ein Projekt.');
 $pdo=enterprise_pdo();enterprise_upgrade($pdo);$st=$pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');$st->execute([$projectId]);$project=$st->fetch();if(!$project)throw new RuntimeException('Projekt wurde nicht gefunden.');
 enterprise_require_project_scope($user,$projectId,false);
 enterprise_require_project_product($user,$projectId,'db-engine',(string)$project['slug']);
 enterprise_require_license_capability($user,'db-engine.export');
}catch(Throwable $e){$error=$e->getMessage();}
ob_start();render_breadcrumbs([['label'=>'Enterprise','href'=>'../../app/dashboard.php'],['label'=>'DB-Engine','href'=>'']]);?>
<section class="hero"><span class="badge">Produkt · DB-Engine</span><h1>DB-Engine</h1><p>Exportierbare Datenbank-Engine für lokale Kundendaten. Unterstützt CSV, SQLite, MySQL/MariaDB, PostgreSQL, Oracle XE und Microsoft SQL Server.</p></section>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php else:?><div class="card"><h2><?=e((string)$project['name'])?></h2><p>Die DB-Engine wird als eigenständiges Projekt exportiert. Sie kann auf dem Kundensystem separat vom Enterprise-Manager installiert werden.</p><p><a class="button" href="export.php?project_id=<?=$projectId?>">DB-Engine-Projekt exportieren</a></p></div><?php endif;?>
<?php $content=ob_get_clean();render_page(['title'=>'DB-Engine','active'=>'products','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'DB-Engine','location'=>'Enterprise → Produkte → DB-Engine','short'=>'Lokale Kundendaten über eine exportierte Datenbank-Engine betreiben.']]);
