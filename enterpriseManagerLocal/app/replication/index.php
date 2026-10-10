<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'replication.view');

$replication=enterprise_replication();
$snapshots=enterprise_replication_snapshots();
$base=dirname(__DIR__,2);
$message='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_require_capability($user,'replication.manage');
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='publish_modules'){
            $payload=['modules'=>$snapshots->moduleMetadata($base.'/modules')];
            $event=$replication->publish('modules.snapshot',$payload);
            $message="Modul-Metadaten als Event {$event->id} publiziert.";
        }elseif($action==='consume'){
            $results=$replication->consume(100);
            $message=$results===[]?'Keine neuen Replikationsereignisse.':count($results).' Ereignis(se) verarbeitet.';
        }else throw new RuntimeException('Unbekannte Replikationsaktion.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$health=$replication->health();
$modules=$snapshots->moduleMetadata($base.'/modules');
$canManage=enterprise_can($user,'replication.manage');

ob_start();
?>
<h1>Replikation</h1>
<p>Clusterweite Synchronisierung von Metadaten über den gemeinsamen Storage-Layer.</p>
<?php if($message!==''):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error!==''):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="card">
<h2>Status</h2>
<p><strong>Node:</strong> <code><?=e((string)$health['node_id'])?></code></p>
<p><strong>Channel:</strong> <code><?=e((string)$health['channel'])?></code></p>
<p><strong>Transport:</strong> <?=e((string)($health['transport']['driver']??'—'))?></p>
<p><strong>Transportstatus:</strong> <?=e((string)($health['transport']['storage']['status']??'—'))?></p>
</section>

<section class="card">
<h2>Modul-Metadaten</h2>
<?php if($modules===[]):?><p>Keine Enterprise-Module vorhanden.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Aktiv</th><th>Manifest SHA-256</th></tr></thead><tbody>
<?php foreach($modules as $row):?><tr>
<td><strong><?=e((string)$row['name'])?></strong></td>
<td><?=e((string)$row['version'])?></td>
<td><?=$row['enabled']?'ja':'nein'?></td>
<td><code><?=e((string)$row['checksum'])?></code></td>
</tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<?php if($canManage):?>
<section class="card">
<h2>Aktionen</h2>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="publish_modules">
<button class="button" type="submit">Modul-Metadaten publizieren</button>
</form>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="consume">
<button class="button secondary" type="submit">Neue Events einlesen</button>
</form>
</section>
<?php endif;?>

<section class="card">
<h2>Sicherheitsmodell</h2>
<p>Phase Y repliziert bewusst nur deklarierte Metadaten/Ereignisse. Es werden keine beliebigen Dateien oder PHP-Quelltexte automatisch auf andere Nodes kopiert.</p>
</section>
<?php
$content=ob_get_clean();
render_page([
'title'=>'Replikation','active'=>'replication','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>[
'title'=>'Cluster-Replikation','location'=>'Enterprise → Replikation',
'short'=>'Publiziert und konsumiert signaturgeprüfte Replikationsereignisse über Shared Storage.',
'goal'=>'Cluster-Metadaten kontrolliert zwischen Nodes synchronisieren.',
'next'=>'Im Cluster zuerst Shared Storage konfigurieren und anschließend Modul-Metadaten publizieren.',
'steps'=>['Shared Storage prüfen.','Modul-Snapshot publizieren.','Auf anderem Node Events konsumieren.','Checksummen vergleichen.'],
'tips'=>['Phase Y kopiert keinen ausführbaren PHP-Code automatisch.','Ereignisse enthalten SHA-256-Integritätsprüfung.','Automatische Fachhandler können später pro Eventtyp ergänzt werden.']
]
]);
