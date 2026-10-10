<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'cluster.view');
$pdo=enterprise_pdo(); enterprise_upgrade($pdo);
$cluster=enterprise_cluster();
$message=''; $error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_require_capability($user,'cluster.manage');
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='heartbeat'){
            $node=$cluster->heartbeat(['manual'=>true]);
            enterprise_audit($pdo,(int)($user['id']??0),'cluster.heartbeat','cluster_node',$node->id);
            $message="Heartbeat für {$node->id} geschrieben.";
        }elseif($action==='remove'){
            $nodeId=trim((string)($_POST['node_id']??''));
            if($nodeId===''||!$cluster->removeNode($nodeId)) throw new RuntimeException('Node nicht gefunden.');
            enterprise_audit($pdo,(int)($user['id']??0),'cluster.remove','cluster_node',$nodeId);
            $message="Node {$nodeId} wurde aus der Registry entfernt.";
        }else throw new RuntimeException('Unbekannte Clusteraktion.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$state=$cluster->health();
$canManage=enterprise_can($user,'cluster.manage');

ob_start();
?>
<h1>Cluster</h1>
<p>Node-Registry, Heartbeats, Leader-Ermittlung und Cluster-Health.</p>
<p><a class="button secondary" href="security.php">Cluster-Sicherheit</a></p>
<?php if($message!==''):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error!==''):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="card">
<h2>Clusterstatus</h2>
<p><strong>Status:</strong> <?=e((string)$state['status'])?></p>
<p><strong>Nodes:</strong> <?=e((string)$state['node_count'])?> &nbsp; <strong>Online:</strong> <?=e((string)$state['online_count'])?></p>
<p><strong>Leader:</strong> <?=e((string)($state['leader']['id']??'—'))?></p>
<?php if($canManage):?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="heartbeat">
<button class="button" type="submit">Heartbeat für diesen Node schreiben</button>
</form>
<?php endif;?>
</section>

<section class="card">
<h2>Verteilte Ausführung</h2>
<p><strong>Queue-Standard:</strong> <code><?=e((string)(getenv('QUEUE_CONNECTION') ?: 'sync'))?></code></p>
<p><strong>Scheduler-Mutex:</strong> <code><?=e((string)(getenv('SCHEDULER_MUTEX_DRIVER') ?: 'file'))?></code></p>
<p>Für mehrere Worker-Nodes kann <code>QUEUE_CONNECTION=database</code> gesetzt werden. Für clusterweite Scheduler-Locks wird <code>SCHEDULER_MUTEX_DRIVER=database</code> verwendet.</p>
</section>

<section class="card">
<h2>Nodes</h2>
<?php if($state['nodes']===[]):?><p>Noch keine Nodes registriert. Der aktuelle Server läuft faktisch im Standalone-Modus.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Node</th><th>Name</th><th>Host</th><th>Version</th><th>Status</th><th>Heartbeat</th><th>Alter</th><th>Rolle</th><?php if($canManage):?><th>Aktionen</th><?php endif;?></tr></thead><tbody>
<?php foreach($state['nodes'] as $node):?>
<tr>
<td><code><?=e((string)$node['id'])?></code></td>
<td><?=e((string)$node['name'])?></td>
<td><?=e((string)$node['host'])?></td>
<td><?=e((string)$node['version'])?></td>
<td><?=$node['online']?'<strong>online</strong>':'offline'?></td>
<td><?=e((string)$node['heartbeat_at'])?></td>
<td><?=e((string)$node['age_seconds'])?> s</td>
<td><?=$node['leader']?'<strong>Leader</strong>':'Follower'?></td>
<?php if($canManage):?><td>
<form method="post" onsubmit="return confirm('Node aus Registry entfernen?');">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="remove">
<input type="hidden" name="node_id" value="<?=e((string)$node['id'])?>">
<button class="button secondary" type="submit">Entfernen</button>
</form>
</td><?php endif;?>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'Cluster','active'=>'cluster','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'Cluster','location'=>'Enterprise → Cluster',
        'short'=>'Verwaltet Cluster-Nodes, Heartbeats und die deterministische Leader-Ermittlung.',
        'goal'=>'Eine sichere Grundlage für später verteilte Worker und Scheduler schaffen.',
        'next'=>'Im Einzelserverbetrieb zuerst einen lokalen Heartbeat schreiben.',
        'steps'=>['Node registrieren.','Heartbeat-Status prüfen.','Leader kontrollieren.','Veraltete Nodes bei Bedarf entfernen.'],
        'tips'=>['Phase V erzwingt noch keinen Mehrserverbetrieb.','Ohne registrierte Nodes bleibt Enterprise im Standalone-Modus.','Die eigentliche verteilte Queue kann darauf später aufbauen.']
    ]
]);
