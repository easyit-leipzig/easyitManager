<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}

$profiler=enterprise_profiler();
\DataForm5\Core\Developer\ProfilerHub::record('http','developer.profiler.page',0,['method'=>$_SERVER['REQUEST_METHOD']??'GET']);
$state=$profiler->snapshot();
$category=trim((string)($_GET['category']??''));
$records=$state['records'];
if($category!=='')$records=array_values(array_filter($records,static fn(array $r):bool=>$r['category']===$category));

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Developer','href'=>'index.php'],['label'=>'Profiler','href'=>'']]);
?>
<section class="hero"><span class="badge">RC1.8 · Developer 5.5</span><h1>Request Profiler</h1>
<p>Laufzeiten und Messpunkte für PHP, SQL, Cache, Filesystem, HTTP/API, Module, Queue und Scheduler.</p></section>

<div class="metric-grid">
<div class="metric"><strong><?=e(number_format((float)$state['runtime_ms'],2,',','.'))?> ms</strong><span>Request-Laufzeit</span></div>
<div class="metric"><strong><?=e(number_format(((int)$state['memory_peak'])/1048576,2,',','.'))?> MB</strong><span>Peak Memory</span></div>
<div class="metric"><strong><?=e((string)count($state['records']))?></strong><span>Messpunkte</span></div>
<div class="metric"><strong><?=e((string)$state['open_spans'])?></strong><span>offene Spans</span></div>
</div>

<section class="card"><h2>Kategorien</h2>
<div class="table-wrap"><table><thead><tr><th>Kategorie</th><th>Messpunkte</th><th>Gesamtdauer</th><th>Maximum</th><th></th></tr></thead><tbody>
<?php foreach($state['summary'] as $name=>$row):?>
<tr><td><strong><?=e((string)$name)?></strong></td><td><?=e((string)$row['count'])?></td>
<td><?=e(number_format((float)$row['duration_ms'],3,',','.'))?> ms</td>
<td><?=e(number_format((float)$row['max_ms'],3,',','.'))?> ms</td>
<td><a href="?category=<?=e(urlencode((string)$name))?>">filtern</a></td></tr>
<?php endforeach;?>
</tbody></table></div></section>

<section class="card"><h2>Timeline<?=$category!==''?' · '.e($category):''?></h2>
<?php if($records===[]):?><p>Noch keine Messpunkte in dieser Kategorie.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Offset</th><th>Kategorie</th><th>Operation</th><th>Dauer</th><th>Kontext</th></tr></thead><tbody>
<?php foreach(array_reverse($records) as $row):?>
<tr><td><?=e(number_format((float)$row['offset_ms'],3,',','.'))?> ms</td>
<td><code><?=e((string)$row['category'])?></code></td><td><?=e((string)$row['operation'])?></td>
<td><?=e(number_format((float)$row['duration_ms'],3,',','.'))?> ms</td>
<td><code><?=e(json_encode($row['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></code></td></tr>
<?php endforeach;?>
</tbody></table></div><?php endif;?>
<p><a class="button secondary" href="profiler.php">Alle Kategorien</a></p></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'Profiler','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>['title'=>'Request Profiler','location'=>'Enterprise → Developer → Profiler',
'short'=>'Sammelt Laufzeitmessungen des aktuellen Requests.','goal'=>'Langsame oder häufige Operationen identifizieren.',
'next'=>'Kategorien mit hoher Gesamtdauer oder hohen Maximalwerten prüfen.',
'steps'=>['Kategorieübersicht ansehen.','Kategorie filtern.','Langsame Operation suchen.','Kontext prüfen.'],
'tips'=>['Profiler nur im Developer Mode aktivieren.','Sensitive Kontextfelder werden redigiert.']]]) ;
