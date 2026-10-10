<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';
$user=enterprise_require_auth('../../'); enterprise_require_capability($user,'monitoring.view');
$pdo=enterprise_pdo(); enterprise_upgrade($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    enterprise_require_capability($user,'monitoring.manage'); enterprise_check_csrf((string)($_POST['csrf']??''));
    enterprise_monitoring_heartbeat()->beat('manual-monitor',['user_id'=>(int)($user['id']??0)]);
    enterprise_audit($pdo,(int)($user['id']??0),'monitoring.refresh','monitoring','health');
}
$snapshot=enterprise_monitoring()->snapshot(true); $m=$snapshot['metrics'];
ob_start(); ?>
<h1>Monitoring</h1><p>Zentraler Health- und Betriebsstatus des Enterprise-Core.</p>
<section class="card"><h2>Gesamtzustand</h2><p><strong><?=e(strtoupper((string)$snapshot['status']))?></strong> · Stand <?=e((string)$snapshot['generated_at'])?></p>
<?php if(enterprise_can($user,'monitoring.manage')):?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><button class="button" type="submit">Snapshot aktualisieren</button></form><?php endif;?></section>
<section class="card"><h2>Systemmetriken</h2><div class="table-wrap"><table><tbody>
<tr><th>PHP</th><td><?=e((string)$m['php']['version'])?></td></tr>
<tr><th>PHP Memory</th><td><?=number_format(((int)$m['php']['memory_usage'])/1048576,1,',','.')?> MB<?php if(is_numeric($m['php']['memory_percent'])):?> (<?=e((string)$m['php']['memory_percent'])?> %)<?php endif;?></td></tr>
<tr><th>Host</th><td><?=e((string)$m['system']['hostname'])?></td></tr>
<tr><th>Load 1m</th><td><?=e((string)($m['system']['load_1m']??'—'))?></td></tr>
<tr><th>Freier Datenträger</th><td><?=is_numeric($m['system']['disk_free_percent'])?e((string)$m['system']['disk_free_percent']).' %':'—'?></td></tr>
<tr><th>Queue wartend</th><td><?=e((string)$m['queue']['pending'])?></td></tr>
<tr><th>Queue fehlgeschlagen</th><td><?=e((string)$m['queue']['failed'])?></td></tr>
</tbody></table></div></section>
<section class="card"><h2>Heartbeats</h2><?php if($snapshot['heartbeats']===[]):?><p>Noch keine Worker-/Scheduler-Heartbeats vorhanden.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Name</th><th>Zeit</th><th>Host</th><th>PID</th></tr></thead><tbody><?php foreach($snapshot['heartbeats'] as $h):?><tr><td><?=e((string)$h['name'])?></td><td><?=e((string)$h['time'])?></td><td><?=e((string)$h['host'])?></td><td><?=e((string)$h['pid'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<section class="card"><h2>Warnungen</h2><?php if($snapshot['alerts']===[]):?><p>Keine aktiven Warnungen.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Stufe</th><th>Code</th><th>Meldung</th></tr></thead><tbody><?php foreach($snapshot['alerts'] as $a):?><tr><td><?=e((string)$a['level'])?></td><td><code><?=e((string)$a['code'])?></code></td><td><?=e((string)$a['message'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php $content=ob_get_clean(); render_page(['title'=>'Monitoring','active'=>'monitoring','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Monitoring','location'=>'Enterprise → Monitoring','short'=>'Zeigt Systemmetriken, Queue-Zustand, Heartbeats und Warnschwellen.','goal'=>'Produktionsprobleme früh erkennen.','next'=>'Warnungen prüfen und Heartbeats kontrollieren.','steps'=>['Gesamtzustand prüfen.','Queue und Speicher kontrollieren.','Heartbeats prüfen.','Warnungen bearbeiten.'],'tips'=>['Worker und Scheduler sollten regelmäßig Heartbeats schreiben.','Warnschwellen stehen in DataForm5-Core/config/monitoring.php.']]]);
