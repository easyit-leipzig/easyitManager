<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');

if(!enterprise_developer_enabled($user)){
    http_response_code(404);
    exit('Developer Mode ist deaktiviert.');
}

enterprise_developer_trace()->event('developer.dashboard.opened',['user_id'=>$user['id']??null]);
$state=enterprise_developer_inspector()->snapshot();
$quality=enterprise_quality_center()->run();
$qualityStatus=(string)$quality['status'];
$qualityTests=(int)$quality['summary']['tests'];
$qualityFailed=(int)$quality['summary']['failed_tests'];

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Developer','href'=>'']]);
?>
<section class="hero">
<span class="badge">RC1.8 · Developer Dashboard 5.12</span>
<h1>Developer Mode</h1>
<p>Zentrale Entwickleroberfläche für Diagnose, SDK, Quality Gates, Profiler, Events, Hooks und Service Container.</p>
</section>

<div class="metric-grid">
<div class="metric"><strong><?=e(number_format((float)$state['runtime']['runtime_ms'],2,',','.'))?> ms</strong><span>Laufzeit</span></div>
<div class="metric"><strong><?=e(number_format(((int)$state['runtime']['memory_peak_bytes'])/1048576,2,',','.'))?> MB</strong><span>Peak Memory</span></div>
<div class="metric"><strong><?=e((string)$state['container']['resolved'])?> / <?=e((string)$state['container']['total'])?></strong><span>Services resolved</span></div>
<div class="metric"><strong><?=e((string)count($state['modules']))?></strong><span>Module</span></div>
<div class="metric"><strong><?=e($qualityStatus)?></strong><span>Quality Gate</span></div>
<div class="metric"><strong><?=e((string)$qualityTests)?></strong><span>Regressionstests</span></div>
<div class="metric"><strong><?=e((string)$qualityFailed)?></strong><span>Fehlgeschlagen</span></div>
</div>

<section class="card">
<h2>Werkzeuge</h2>
<p><a class="button" href="container.php">Service Container Inspector</a> <a class="button secondary" href="events.php">Event Inspector</a> <a class="button secondary" href="hooks.php">Hook Inspector</a> <a class="button secondary" href="profiler.php">Profiler</a> <a class="button secondary" href="sdk.php">SDK-Konsole</a> <a class="button secondary" href="tests.php">Test Center</a></p>
</section>

<section class="card">
<h2>Developer Workbench</h2>
<div class="table-wrap"><table><thead><tr><th>Werkzeug</th><th>Zweck</th><th>Öffnen</th></tr></thead><tbody>
<tr><td><strong>Quality Center</strong></td><td>Gesamt-Regression und Release-Gate</td><td><a href="tests.php">Tests öffnen</a></td></tr>
<tr><td><strong>SDK-Konsole</strong></td><td>Module erzeugen, validieren und paketieren</td><td><a href="sdk.php">SDK öffnen</a></td></tr>
<tr><td><strong>Profiler</strong></td><td>Laufzeit und Performance analysieren</td><td><a href="profiler.php">Profiler öffnen</a></td></tr>
<tr><td><strong>Container Inspector</strong></td><td>Services, Singletons und Auflösung prüfen</td><td><a href="container.php">Container öffnen</a></td></tr>
<tr><td><strong>Event Inspector</strong></td><td>Eventfluss und Kontext untersuchen</td><td><a href="events.php">Events öffnen</a></td></tr>
<tr><td><strong>Hook Inspector</strong></td><td>Hooks und Erweiterungspunkte untersuchen</td><td><a href="hooks.php">Hooks öffnen</a></td></tr>
</tbody></table></div>
</section>

<section class="card">
<h2>Entwickler-Workflow</h2>
<ol>
<li>Modul über die SDK-Konsole erzeugen.</li>
<li>Implementierung mit Inspector und Profiler prüfen.</li>
<li><code>module:validate</code> als Modul-Quality-Gate ausführen.</li>
<li>SDK- und Gesamttests im Quality Center ausführen.</li>
<li>Validiertes Modul mit <code>module:package</code> paketieren.</li>
</ol>
<p><code>docs/SDK/README.md</code> enthält die vollständige Entwicklerreferenz.</p>
</section>

<section class="card">
<h2>Laufzeit</h2>
<div class="table-wrap"><table><tbody>
<tr><th>PHP</th><td><?=e((string)$state['runtime']['php_version'])?></td></tr>
<tr><th>SAPI</th><td><?=e((string)$state['runtime']['sapi'])?></td></tr>
<tr><th>Memory aktuell</th><td><?=e(number_format(((int)$state['runtime']['memory_bytes'])/1048576,2,',','.'))?> MB</td></tr>
<tr><th>Memory Peak</th><td><?=e(number_format(((int)$state['runtime']['memory_peak_bytes'])/1048576,2,',','.'))?> MB</td></tr>
</tbody></table></div>
</section>

<section class="card">
<h2>Module</h2>
<div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Aktiv</th><th>Geladen</th><th>Probleme</th></tr></thead><tbody>
<?php foreach($state['modules'] as $module):?>
<tr><td><strong><?=e((string)$module['name'])?></strong></td><td><?=e((string)$module['version'])?></td><td><?=$module['enabled']?'ja':'nein'?></td><td><?=$module['loaded']?'ja':'nein'?></td><td><?=e((string)$module['issues'])?></td></tr>
<?php endforeach;?>
</tbody></table></div>
</section>

<section class="card">
<h2>Service Container</h2>
<p><strong><?=e((string)$state['container']['total'])?></strong> registrierte Services, <strong><?=e((string)$state['container']['singletons'])?></strong> shared/singleton.</p>
<details><summary>Services anzeigen</summary>
<div class="table-wrap"><table><thead><tr><th>Service</th><th>Singleton</th><th>Resolved</th><th>Tags</th></tr></thead><tbody>
<?php foreach($state['container']['services'] as $id=>$service):?>
<tr><td><code><?=e((string)$id)?></code></td><td><?=$service['shared']?'ja':'nein'?></td><td><?=$service['resolved']?'ja':'nein'?></td><td><?=e(implode(', ',(array)$service['tags']))?></td></tr>
<?php endforeach;?>
</tbody></table></div>
</details>
</section>

<section class="card">
<h2>Events</h2>
<?php if($state['events']['trace']===[]):?><p>Noch keine Developer-Events in diesem Request aufgezeichnet.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Offset</th><th>Event</th><th>Kontext</th></tr></thead><tbody>
<?php foreach(array_reverse($state['events']['trace']) as $event):?>
<tr><td><?=e((string)$event['offset_ms'])?> ms</td><td><code><?=e((string)$event['name'])?></code></td><td><code><?=e(json_encode($event['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></code></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<section class="card">
<h2>Queue & Scheduler</h2>
<p>Queue: <?=e((string)($state['queue']['stats']['pending']??0))?> wartend,
<?=e((string)($state['queue']['stats']['processing']??0))?> aktiv,
<?=e((string)($state['queue']['stats']['failed']??0))?> fehlgeschlagen.</p>
<p>Scheduler: <?=e((string)count($state['scheduler']['tasks']))?> registrierte Task(s).</p>
</section>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'Developer Mode','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'Developer Mode','location'=>'Enterprise → Developer',
        'short'=>'Diagnoseansicht für Laufzeit, Container, Module, Events, Queue und Scheduler.',
        'goal'=>'Technische Probleme ohne direkte Eingriffe in Produktionsdaten untersuchen.',
        'next'=>'Zuerst Laufzeit und Container prüfen, danach Module oder Event-Trace.',
        'steps'=>['DEVELOPER_MODE aktivieren.','Mit Adminrolle anmelden.','Developer öffnen.','Auffällige Services/Module untersuchen.'],
        'tips'=>['Developer Mode in Produktion standardmäßig deaktiviert lassen.','Secrets und Token werden im Trace redigiert.']
    ]
]);
