<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}

enterprise_developer_trace()->event('developer.events.opened',['user_id'=>$user['id']??null]);
$state=enterprise_event_inspector()->inspect();
$q=strtolower(trim((string)($_GET['q']??'')));
$onlyListeners=(string)($_GET['listeners']??'')==='1';
$onlyDispatched=(string)($_GET['dispatched']??'')==='1';
$events=array_filter($state['events'],function(array $row)use($q,$onlyListeners,$onlyDispatched):bool{
    if($onlyListeners&&(int)$row['listener_count']===0)return false;
    if($onlyDispatched&&(int)$row['dispatch_count']===0)return false;
    if($q==='')return true;
    return str_contains(strtolower($row['name'].' '.$row['description'].' '.$row['producer']),$q);
});
ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Developer','href'=>'index.php'],['label'=>'Events','href'=>'']]);
?>
<section class="hero"><span class="badge">RC1.8 · Developer 5.3</span><h1>Event Inspector</h1>
<p>Eventkatalog, registrierte Listener, Prioritäten und Dispatch-Trace des aktuellen Requests.</p></section>
<div class="metric-grid">
<div class="metric"><strong><?=e((string)$state['summary']['events'])?></strong><span>Events gesamt</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['catalogued'])?></strong><span>im Katalog</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['listener_total'])?></strong><span>Listener</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['dispatched'])?></strong><span>im Request dispatched</span></div>
</div>
<section class="card"><h2>Filter</h2><form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
<label>Suche<br><input name="q" value="<?=e((string)($_GET['q']??''))?>" placeholder="Event, Beschreibung, Producer"></label>
<label><input type="checkbox" name="listeners" value="1" <?=$onlyListeners?'checked':''?>> nur mit Listenern</label>
<label><input type="checkbox" name="dispatched" value="1" <?=$onlyDispatched?'checked':''?>> nur dispatched</label>
<button class="button secondary" <?= easyit_button_attributes('filter','filter') ?> type="submit">Filtern</button><a class="button secondary" <?= easyit_button_attributes('filter_loeschen','filter') ?> href="events.php">Zurücksetzen</a>
</form></section>
<section class="card"><h2>Events</h2><div class="table-wrap"><table>
<thead><tr><th>Event</th><th>Katalog</th><th>Producer</th><th>Listener</th><th>Prioritäten</th><th>Dispatches</th><th>Letzter Offset</th></tr></thead><tbody>
<?php foreach($events as $row):?><tr>
<td><code><?=e((string)$row['name'])?></code><?php if($row['description']!==''):?><br><small><?=e((string)$row['description'])?></small><?php endif;?></td>
<td><?=$row['catalogued']?'ja':'nein'?></td><td><?=e((string)($row['producer']?:'—'))?></td>
<td><?=e((string)$row['listener_count'])?></td>
<td><?=e($row['priorities']===[]?'—':implode(', ',array_map(static fn($p,$c):string=>$p.':'.$c,array_keys($row['priorities']),array_values($row['priorities']))))?></td>
<td><?=e((string)$row['dispatch_count'])?></td>
<td><?=$row['last_offset_ms']===null?'—':e(number_format((float)$row['last_offset_ms'],3,',','.')).' ms'?></td>
</tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Dispatch-Trace</h2>
<?php if($state['trace']===[]):?><p>Im aktuellen Request wurden noch keine Developer-Events aufgezeichnet.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Offset</th><th>Event</th><th>Kontext</th></tr></thead><tbody>
<?php foreach(array_reverse($state['trace']) as $row):?><tr><td><?=e(number_format((float)($row['offset_ms']??0),3,',','.'))?> ms</td>
<td><code><?=e((string)($row['name']??''))?></code></td>
<td><code><?=e(json_encode($row['context']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></code></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></section>
<p><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">Zurück zum Developer Dashboard</a></p>
<?php
$content=ob_get_clean();
render_page(['title'=>'Event Inspector','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>['title'=>'Event Inspector','location'=>'Enterprise → Developer → Events','short'=>'Zeigt Eventkatalog, Listener, Prioritäten und Dispatch-Trace.',
'goal'=>'Ereignisflüsse und Listener-Konfigurationen nachvollziehen.','next'=>'Event filtern und Listener/Prioritäten prüfen.',
'steps'=>['Event suchen.','Listenerzahl prüfen.','Prioritäten kontrollieren.','Dispatch-Trace vergleichen.'],
'tips'=>['Der Trace zeigt nur den aktuellen Request.','Sensible Kontextwerte werden redigiert.']]]);
