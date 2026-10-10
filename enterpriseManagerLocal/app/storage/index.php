<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'storage.view');

$manager=enterprise_storage();
$health=$manager->health();
$default=(string)(getenv('STORAGE_DISK')?:'local');

ob_start();
?>
<h1>Storage</h1>
<p>Zentrale Storage-Abstraktion für Enterprise und Module.</p>

<section class="card">
<h2>Konfiguration</h2>
<p><strong>Standard-Disk:</strong> <code><?=e($default)?></code></p>
<p>Module sollten Dateien künftig über <code>enterprise_storage()-&gt;disk()</code> bzw. <code>StorageManager</code> speichern und keine festen lokalen Pfade voraussetzen.</p>
</section>

<section class="card">
<h2>Storage-Provider</h2>
<div class="table-wrap"><table><thead><tr><th>Disk</th><th>Driver</th><th>Status</th><th>Root/Bucket</th><th>Schreibbar</th><th>Frei</th></tr></thead><tbody>
<?php foreach($health as $name=>$row):?>
<tr>
<td><strong><?=e((string)$name)?></strong><?=$name===$default?' <small>(Standard)</small>':''?></td>
<td><?=e((string)($row['driver']??'—'))?></td>
<td><?=e((string)($row['status']??'—'))?></td>
<td><code><?=e((string)($row['root']??$row['bucket']??'—'))?></code></td>
<td><?=array_key_exists('writable',$row)?($row['writable']?'ja':'nein'):'—'?></td>
<td><?=isset($row['free_bytes'])&&is_int($row['free_bytes'])?e(number_format($row['free_bytes']/1073741824,2,',','.')).' GB':'—'?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
</section>

<section class="card">
<h2>Clusterbetrieb</h2>
<p>Für gemeinsam sichtbare Dateien kann <code>STORAGE_DISK=shared</code> und <code>STORAGE_SHARED_ROOT</code> auf ein NFS-/SMB-/SAN-Verzeichnis gesetzt werden.</p>
<p>Ein S3-kompatibler Disk ist bereits als Konfigurationsziel vorbereitet. Phase X öffnet jedoch absichtlich noch keinen eigenen S3-Netzwerkclient.</p>
</section>
<?php
$content=ob_get_clean();
render_page([
'title'=>'Storage','active'=>'storage','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
'help'=>[
'title'=>'Shared Storage','location'=>'Enterprise → Storage',
'short'=>'Zeigt die konfigurierten Storage-Disks und deren Zustand.',
'goal'=>'Dateizugriffe von lokalen Serverpfaden entkoppeln.',
'next'=>'Für Einzelserver local verwenden; für Cluster shared auf ein gemeinsames Dateisystem legen.',
'steps'=>['Standard-Disk prüfen.','Schreibbarkeit kontrollieren.','Shared-Root konfigurieren.','Module auf StorageManager umstellen.'],
'tips'=>['Shared-Filesystem bedeutet nicht automatisch Hochverfügbarkeit.','S3 ist vorbereitet, benötigt aber später einen Transportprovider.']
]
]);
