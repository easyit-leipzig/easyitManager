<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
$snapshot=enterprise_dashboard_snapshot();

$areas=[
    ['key'=>'jobs','label'=>'Jobs & Scheduler','href'=>'../background/index.php','capability'=>'background.view','description'=>'Queues, Worker, Scheduler und fehlgeschlagene Jobs.'],
    ['key'=>'monitoring','label'=>'Monitoring','href'=>'../monitoring/index.php','capability'=>'monitoring.view','description'=>'Health, Metriken, Heartbeats und Warnungen.'],
    ['key'=>'cluster','label'=>'Cluster','href'=>'../cluster/index.php','capability'=>'cluster.view','description'=>'Nodes, Leader, Locks und Clusterzustand.'],
    ['key'=>'storage','label'=>'Storage','href'=>'../storage/index.php','capability'=>'storage.view','description'=>'Local, Shared Storage und Providerzustand.'],
    ['key'=>'replication','label'=>'Replikation','href'=>'../replication/index.php','capability'=>'replication.view','description'=>'Cluster-Synchronisierung und Replikationsstatus.'],
];

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Betrieb','href'=>'']]);
?>
<section class="hero">
<span class="badge">RC1.8 · Betriebszentrale</span>
<h1>Betrieb</h1>
<p>Technische Betriebsfunktionen an einer Stelle – mit direktem Status und Zugriff auf die vorhandenen Detailseiten.</p>
</section>

<div class="metric-grid">
<div class="metric"><strong><?=e(strtoupper((string)$snapshot['overall']))?></strong><span>Gesamtzustand</span></div>
<div class="metric"><strong><?=e((string)$snapshot['jobs']['pending'])?></strong><span>Jobs wartend</span></div>
<div class="metric"><strong><?=e((string)$snapshot['jobs']['failed'])?></strong><span>Jobs fehlgeschlagen</span></div>
<div class="metric"><strong><?=e((string)$snapshot['monitoring']['alerts'])?></strong><span>Warnungen</span></div>
</div>

<section>
<h2>Betriebsbereiche</h2>
<div class="cards product-grid">
<?php foreach($areas as $area): if(!enterprise_can($user,$area['capability']))continue; $state=$snapshot[$area['key']]??[]; ?>
<article class="card">
<span class="status-pill"><?=e((string)($state['status']??'unknown'))?></span>
<h2><?=e($area['label'])?></h2>
<p><?=e($area['description'])?></p>
<a class="button secondary" href="<?=e($area['href'])?>">Details öffnen</a>
</article>
<?php endforeach;?>
</div>
</section>

<section class="card">
<h2>Cluster & Shared Services</h2>
<div class="table-wrap"><table><tbody>
<tr><th>Cluster</th><td><?=e((string)$snapshot['cluster']['online'])?> / <?=e((string)$snapshot['cluster']['nodes'])?> Nodes online</td><td>Leader: <code><?=e((string)($snapshot['cluster']['leader']??'—'))?></code></td></tr>
<tr><th>Storage</th><td><?=e((string)$snapshot['storage']['healthy'])?> / <?=e((string)$snapshot['storage']['total'])?> Provider healthy</td><td><?=e((string)$snapshot['storage']['status'])?></td></tr>
<tr><th>Replikation</th><td>Channel <code><?=e((string)($snapshot['replication']['channel']?:'—'))?></code></td><td><?=($snapshot['replication']['security']??false)?'Signiert':'Sicherheit deaktiviert'?></td></tr>
</tbody></table></div>
</section>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'Betrieb','active'=>'operations','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'Betriebszentrale','location'=>'Enterprise → Betrieb',
        'short'=>'Bündelt Jobs, Monitoring, Cluster, Storage und Replikation.',
        'goal'=>'Den technischen Zustand der Plattform ohne Wechsel durch viele Menüpunkte erfassen.',
        'next'=>'Zuerst Gesamtzustand und Warnungen prüfen, danach bei Bedarf die Detailseite öffnen.',
        'steps'=>['Gesamtzustand prüfen.','Fehlgeschlagene Jobs kontrollieren.','Warnungen ansehen.','Cluster/Storage/Replikation nur bei aktivem Clusterbetrieb prüfen.'],
        'tips'=>['Die Detailseiten bleiben vollständig erhalten.','Die Betriebszentrale verändert keine Konfiguration automatisch.']
    ]
]);
