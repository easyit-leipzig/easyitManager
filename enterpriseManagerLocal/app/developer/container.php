<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}

enterprise_developer_trace()->event('developer.container.opened',['user_id'=>$user['id']??null]);
$state=enterprise_container_inspector()->inspect();
$q=trim((string)($_GET['q']??''));
$onlyResolved=(string)($_GET['resolved']??'')==='1';
$onlySingleton=(string)($_GET['singleton']??'')==='1';

$services=array_filter($state['services'],function(array $row)use($q,$onlyResolved,$onlySingleton):bool{
    if($onlyResolved && !$row['resolved'])return false;
    if($onlySingleton && !$row['shared'])return false;
    if($q==='')return true;
    $haystack=strtolower($row['id'].' '.($row['class']??'').' '.implode(' ',$row['aliases']).' '.implode(' ',$row['tags']));
    return str_contains($haystack,strtolower($q));
});

ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Developer','href'=>'index.php'],
    ['label'=>'Container','href'=>'']
]);
?>
<section class="hero">
<span class="badge">RC1.8 · Developer 5.2</span>
<h1>Service Container Inspector</h1>
<p>Registrierung, Singleton-Status, Auflösungszustand, Aliases und Constructor-Abhängigkeiten.</p>
</section>

<div class="metric-grid">
<div class="metric"><strong><?=e((string)$state['summary']['services'])?></strong><span>Services</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['resolved'])?></strong><span>Resolved</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['singletons'])?></strong><span>Singletons</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['aliases'])?></strong><span>Aliases</span></div>
</div>

<section class="card">
<h2>Filter</h2>
<form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
<label>Suche<br><input name="q" value="<?=e($q)?>" placeholder="Service, Klasse, Alias, Tag"></label>
<label><input type="checkbox" name="resolved" value="1" <?=$onlyResolved?'checked':''?>> nur resolved</label>
<label><input type="checkbox" name="singleton" value="1" <?=$onlySingleton?'checked':''?>> nur Singleton</label>
<button class="button secondary" type="submit">Filtern</button>
<a class="button secondary" <?= easyit_button_attributes('filter_loeschen','filter') ?> href="container.php">Zurücksetzen</a>
</form>
</section>

<section class="card">
<h2>Services</h2>
<div class="table-wrap"><table>
<thead><tr><th>Service</th><th>Singleton</th><th>Resolved</th><th>Aliases</th><th>Tags</th><th>Abhängigkeiten</th></tr></thead>
<tbody>
<?php foreach($services as $row):?>
<tr>
<td><code><?=e((string)$row['id'])?></code></td>
<td><?=$row['shared']?'ja':'nein'?></td>
<td><?=$row['resolved']?'<strong>ja</strong>':'nein'?></td>
<td><?=e($row['aliases']===[]?'—':implode(', ',$row['aliases']))?></td>
<td><?=e($row['tags']===[]?'—':implode(', ',$row['tags']))?></td>
<td>
<?php if($row['dependencies']===[]):?>—<?php else:?>
<details><summary><?=count($row['dependencies'])?> Parameter</summary>
<ul>
<?php foreach($row['dependencies'] as $dep):?>
<li><code>$<?=e((string)$dep['parameter'])?></code>:
<?=e($dep['types']===[]?'untyped':implode('|',$dep['types']))?>
<?=$dep['optional']?'(optional)':''?>
</li>
<?php endforeach;?>
</ul>
</details>
<?php endif;?>
</td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<p><small><?=count($services)?> von <?=e((string)$state['summary']['services'])?> Services angezeigt.</small></p>
</section>

<section class="card">
<h2>Alias-Matrix</h2>
<?php if($state['aliases']===[]):?><p>Keine Aliases registriert.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Alias</th><th>Zielservice</th></tr></thead><tbody>
<?php foreach($state['aliases'] as $alias=>$target):?>
<tr><td><code><?=e((string)$alias)?></code></td><td><code><?=e((string)$target)?></code></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<p><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">Zurück zum Developer Dashboard</a></p>
<?php
$content=ob_get_clean();
render_page([
'title'=>'Container Inspector','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>[
'title'=>'Service Container Inspector','location'=>'Enterprise → Developer → Container',
'short'=>'Zeigt Containerregistrierungen ohne Services für die Analyse zwangsweise zu instanziieren.',
'goal'=>'Abhängigkeiten und DI-Probleme sichtbar machen.',
'next'=>'Nach einem Service suchen und Constructor-Abhängigkeiten aufklappen.',
'steps'=>['Service filtern.','Resolved/Singleton prüfen.','Aliases kontrollieren.','Constructor-Parameter untersuchen.'],
'tips'=>['Resolved bedeutet: im aktuellen Request bereits instanziiert.','Reflection löst keine zusätzlichen Services aus.']
]
]);
