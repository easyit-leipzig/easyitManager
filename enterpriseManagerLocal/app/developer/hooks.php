<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}

enterprise_developer_trace()->event('developer.hooks.opened',['user_id'=>$user['id']??null]);
$state=enterprise_hook_inspector()->inspect();
$q=strtolower(trim((string)($_GET['q']??'')));
$hooks=array_filter($state['hooks'],function(array $row)use($q):bool{
    if($q==='') return true;
    return str_contains(strtolower($row['module'].' '.$row['hook'].' '.$row['handler']),$q);
});

ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Developer','href'=>'index.php'],
    ['label'=>'Hooks','href'=>'']
]);
?>
<section class="hero">
<span class="badge">RC1.8 · Developer 5.4</span>
<h1>Hook Inspector</h1>
<p>Lifecycle- und Plugin-Hooks mit Handlern, Reihenfolge und Laufzeitstatus.</p>
</section>

<div class="metric-grid">
<div class="metric"><strong><?=e((string)$state['summary']['hooks'])?></strong><span>Hooks</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['modules'])?></strong><span>Module mit Hooks</span></div>
<div class="metric"><strong><?=e((string)$state['summary']['with_runtime'])?></strong><span>mit Runtime-Trace</span></div>
</div>

<section class="card">
<h2>Filter</h2>
<form method="get" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
<label>Suche<br><input name="q" value="<?=e((string)($_GET['q']??''))?>" placeholder="Modul, Hook, Handler"></label>
<button class="button secondary" type="submit">Filtern</button>
<a class="button secondary" <?= easyit_button_attributes('filter_loeschen','filter') ?> href="hooks.php">Zurücksetzen</a>
</form>
</section>

<section class="card">
<h2>Lifecycle-Hooks</h2>
<div class="table-wrap"><table>
<thead><tr><th>Modul</th><th>Hook</th><th>Handler</th><th>Priorität</th><th>Aktiv</th><th>Runtime</th></tr></thead>
<tbody>
<?php foreach($hooks as $row):?>
<tr>
<td><strong><?=e((string)$row['module'])?></strong></td>
<td><code><?=e((string)$row['hook'])?></code></td>
<td><code><?=e((string)$row['handler'])?></code></td>
<td><?=e((string)$row['priority'])?></td>
<td><?=$row['enabled']?'ja':'nein'?></td>
<td><?php if($row['runtime']===null):?>—<?php else:?>
<code><?=e((string)$row['runtime']['event'])?></code><br>
<?=e(number_format((float)$row['runtime']['offset_ms'],3,',','.'))?> ms
<?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
</section>

<section class="card">
<h2>Reihenfolge</h2>
<p>Die Anzeige priorisiert install → update → enable → disable → uninstall. Das ist eine Analyseansicht; sie verändert keine tatsächliche Ausführungsreihenfolge.</p>
</section>

<p><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">Zurück zum Developer Dashboard</a></p>
<?php
$content=ob_get_clean();
render_page([
'title'=>'Hook Inspector','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>[
'title'=>'Hook Inspector','location'=>'Enterprise → Developer → Hooks',
'short'=>'Zeigt Lifecycle- und Plugin-Hooks mit Handlern und Laufzeitinformationen.',
'goal'=>'Hook-Konfigurationen und Handlerzuordnungen nachvollziehen.',
'next'=>'Nach Modul oder Hook filtern und Handler prüfen.',
'steps'=>['Hook suchen.','Handler prüfen.','Aktivstatus kontrollieren.','Runtime-Trace vergleichen.'],
'tips'=>['Diese Ansicht führt keine Hooks aus.','Die Priorität ist eine Diagnose-/Sortierhilfe.']
]
]);
