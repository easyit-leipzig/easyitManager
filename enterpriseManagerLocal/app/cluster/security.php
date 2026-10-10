<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'cluster.security');

$pdo=enterprise_pdo(); enterprise_upgrade($pdo);
$store=enterprise_cluster_trust_store();
$message='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='trust'){
            $nodeId=trim((string)($_POST['node_id']??''));
            $secret=(string)($_POST['secret']??'');
            $label=trim((string)($_POST['label']??''));
            if($nodeId===''||$secret==='') throw new RuntimeException('Node-ID und Secret sind Pflicht.');
            $store->trust($nodeId,$secret,$label);
            enterprise_audit($pdo,(int)($user['id']??0),'cluster.trust','cluster_node',$nodeId,['label'=>$label]);
            $message="Node {$nodeId} wurde als vertrauenswürdig registriert.";
        }elseif($action==='revoke'){
            $nodeId=trim((string)($_POST['node_id']??''));
            if(!$store->revoke($nodeId)) throw new RuntimeException('Node nicht im Trust Store gefunden.');
            enterprise_audit($pdo,(int)($user['id']??0),'cluster.revoke','cluster_node',$nodeId);
            $message="Vertrauen für {$nodeId} wurde entzogen.";
        }else throw new RuntimeException('Unbekannte Sicherheitsaktion.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$trusted=$store->publicView();
ob_start();
?>
<h1>Cluster-Sicherheit</h1>
<p>Vertrauenswürdige Nodes, Schlüssel-Fingerprints und Signaturkonfiguration.</p>
<?php if($message!==''):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error!==''):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="card">
<h2>Sicherheitsstatus</h2>
<p><strong>Aktiv:</strong> <?=filter_var(getenv('CLUSTER_SECURITY_ENABLED')?:'false',FILTER_VALIDATE_BOOL)?'ja':'nein'?></p>
<p><strong>Lokale Node-ID:</strong> <code><?=e((string)(getenv('CLUSTER_NODE_ID')?:gethostname()?:'node-local'))?></code></p>
<p><strong>Signaturalgorithmus:</strong> HMAC-SHA-256</p>
</section>

<section class="card">
<h2>Vertrauenswürdige Nodes</h2>
<?php if($trusted===[]):?><p>Noch keine Nodes im Trust Store.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Node</th><th>Label</th><th>Fingerprint</th><th>Vertraut seit</th><th>Aktion</th></tr></thead><tbody>
<?php foreach($trusted as $id=>$row):?><tr>
<td><code><?=e((string)$id)?></code></td>
<td><?=e((string)$row['label'])?></td>
<td><code><?=e((string)$row['fingerprint'])?></code></td>
<td><?=e((string)$row['trusted_at'])?></td>
<td><form method="post" onsubmit="return confirm('Vertrauen wirklich entziehen?');">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="revoke">
<input type="hidden" name="node_id" value="<?=e((string)$id)?>">
<button class="button secondary" type="submit">Entziehen</button>
</form></td>
</tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<section class="card">
<h2>Node vertrauen</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="trust">
<p><label>Node-ID<br><input name="node_id" required></label></p>
<p><label>Label<br><input name="label"></label></p>
<p><label>Node-Secret<br><input type="password" name="secret" required autocomplete="new-password"></label></p>
<button class="button" type="submit">Vertrauen speichern</button>
</form>
<p><small>Secrets werden nicht in dieser Oberfläche zurückgegeben. Angezeigt wird nur ein SHA-256-Fingerprint.</small></p>
</section>
<?php
$content=ob_get_clean();
render_page([
'title'=>'Cluster-Sicherheit','active'=>'cluster','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>[
'title'=>'Cluster-Sicherheit','location'=>'Enterprise → Cluster → Sicherheit',
'short'=>'Verwaltet die Node-Vertrauensbasis für signierte Heartbeats und Replikationsereignisse.',
'goal'=>'Nur bekannte Nodes als authentische Clusterteilnehmer akzeptieren.',
'next'=>'Für jeden Node einen eigenen Secret-Wert konfigurieren und auf den anderen Nodes als vertrauenswürdig eintragen.',
'steps'=>['CLUSTER_SECURITY_ENABLED aktivieren.','CLUSTER_NODE_SECRET pro Node setzen.','Gegenstellen im Trust Store eintragen.','Heartbeats und Replikation prüfen.'],
'tips'=>['Für jeden Node ein eigenes Secret verwenden.','Secrets niemals in Logs oder Screenshots veröffentlichen.','Bei Schlüsselwechsel alten Trust-Eintrag ersetzen.']
]
]);
