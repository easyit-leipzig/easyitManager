<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}

$group=trim((string)($_GET['group']??''));
$result=enterprise_quality_center()->run($group!==''?$group:null);

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Developer','href'=>'index.php'],['label'=>'Test Center','href'=>'']]);
?>
<section class="hero">
<span class="badge">RC1.8 · Developer 5.10</span>
<h1>Developer Quality Center</h1>
<p>Zentrale Abnahme für Core, Module, Installer, Cluster, Storage, Replikation, SDK und Developer-Werkzeuge.</p>
</section>

<div class="metric-grid">
<div class="metric"><strong><?=e($result['status'])?></strong><span>Gesamtstatus</span></div>
<div class="metric"><strong><?=e((string)$result['summary']['groups'])?></strong><span>Testgruppen</span></div>
<div class="metric"><strong><?=e((string)$result['summary']['tests'])?></strong><span>Tests</span></div>
<div class="metric"><strong><?=e((string)$result['summary']['failed_tests'])?></strong><span>Fehler</span></div>
</div>

<section class="card">
<h2>Testgruppen</h2>
<div class="table-wrap"><table><thead><tr><th>Gruppe</th><th>Status</th><th>Tests</th><th>Fehler</th><th>Warnungen</th><th></th></tr></thead><tbody>
<?php foreach($result['groups'] as $name=>$row):?>
<tr>
<td><strong><?=e($name)?></strong></td><td><?=e($row['status'])?></td><td><?=e((string)$row['total'])?></td><td><?=e((string)$row['failed'])?></td><td><?=e((string)$row['warnings'])?></td>
<td><a href="?group=<?=e(urlencode($name))?>">Details</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<p><a class="button secondary" href="tests.php">Alle Gruppen</a></p>
</section>

<?php foreach($result['groups'] as $name=>$row):?>
<section class="card"><h2><?=e($name)?> · <?=e($row['status'])?></h2>
<div class="table-wrap"><table><thead><tr><th>Test</th><th>Status</th><th>Dauer</th><th>Ausgabe</th></tr></thead><tbody>
<?php foreach($row['tests'] as $test):?>
<tr><td><code><?=e($test['name'])?></code></td><td><?=e($test['status'])?></td><td><?=e(number_format((float)$test['duration_ms'],2,',','.'))?> ms</td>
<td><details><summary>anzeigen</summary><pre><code><?=e($test['output'])?></code></pre></details></td></tr>
<?php endforeach;?>
</tbody></table></div></section>
<?php endforeach;?>

<?php
$content=ob_get_clean();
render_page(['title'=>'Developer Quality Center','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>['title'=>'Developer Quality Center','location'=>'Enterprise → Developer → Tests','short'=>'Führt alle konsolidierten Regressionstests gruppiert aus.',
'goal'=>'Vor Releases schnell erkennen, welcher technische Bereich fehlschlägt.','next'=>'Gesamtstatus prüfen und bei FAIL die betroffene Gruppe öffnen.',
'steps'=>['Gesamtstatus ansehen.','Fehlergruppe öffnen.','Testausgabe prüfen.','Fehler beheben und erneut ausführen.'],
'tips'=>['Das Test Center verändert keine Produktionsdaten absichtlich.','Für CI kann quality:center --json verwendet werden.']]]) ;
