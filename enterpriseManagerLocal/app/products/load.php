<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
$productCode=strtolower(trim((string)($_GET['product']??'')));
$projectId=(int)($_GET['project_id']??0);
$error='';$project=null;$module=enterprise_local_product_module($productCode);
try{
    if($productCode===''||$projectId<1)throw new RuntimeException('Produkt oder Projekt fehlt.');
    $pdo=enterprise_pdo();enterprise_upgrade($pdo);$st=$pdo->prepare('SELECT id,name,slug,product_type,status FROM projects WHERE id=? LIMIT 1');$st->execute([$projectId]);$project=$st->fetch();if(!$project)throw new RuntimeException('Projekt wurde nicht gefunden.');
    enterprise_require_project_scope($user,$projectId,false);
    enterprise_require_project_product($user,$projectId,$productCode,(string)$project['slug']);
    if(empty($module['installed'])||empty($module['entry']))throw new RuntimeException('Die Projektlizenz ist aktiv, das Produktmodul ist in dieser Enterprise-Installation jedoch noch nicht installiert.');
    $entry='../../'.ltrim((string)$module['entry'],'/');$sep=str_contains($entry,'?')?'&':'?';header('Location: '.$entry.$sep.'project='.rawurlencode((string)$projectId),true,302);exit;
}catch(Throwable $e){$error=$e->getMessage();}

ob_start();render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Produkte','href'=>'index.php'],['label'=>$module['label']??$productCode,'href'=>'']]);?>
<section class="hero"><span class="badge">Produktmodul</span><h1><?=e((string)($module['label']??$productCode))?></h1><p>Projekt: <strong><?=e((string)($project['name']??$projectId))?></strong></p></section>
<div class="notice error"><?=e($error)?></div>
<p><a class="button secondary" href="../dashboard.php">Zum Dashboard</a></p>
<?php $content=ob_get_clean();render_page(['title'=>'Produktmodul','active'=>'products','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Produktmodul','location'=>'Enterprise → Produkte','short'=>'Projektbezogene Produktfreigabe und lokaler Modulstatus.']]);
