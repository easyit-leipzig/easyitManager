<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
$user=enterprise_require_auth('../../');
if (!enterprise_is_admin($user)) { http_response_code(403); exit('Nur Administratoren.'); }

$kernel=enterprise_kernel();
$container=$kernel->container();
$manager=$container->get(\DataForm5\Modules\Core\ModuleManager::class);
$registry=$container->get(\DataForm5\Modules\Core\ModuleRegistry::class);
$status=$manager->status();
$paths=$manager->scanPaths();
$loaded=count($registry->names());
$enabled=count(array_filter($status,static fn(array $row): bool => (bool)$row['enabled']));
$broken=count(array_filter($status,static fn(array $row): bool => $row['missing_dependencies']!==[]));

ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Entwicklung','href'=>'events.php'],
    ['label'=>'Module','href'=>''],
]); ?>
<section class="hero"><span class="badge">RC1.7 · Phase D</span><h1>Modul- und Plugin-Registry</h1><p>Zentrale Discovery für Core- und Enterprise-Module mit Abhängigkeits- und Ladezustand.</p></section>
<section class="grid cols-3">
  <article class="card"><h2><?=count($status)?></h2><p>entdeckte Module</p></article>
  <article class="card"><h2><?=$loaded?> / <?=$enabled?></h2><p>geladen / aktiviert</p></article>
  <article class="card"><h2><?=$broken?></h2><p>Module mit fehlenden Abhängigkeiten</p></article>
</section>
<section class="card"><h2>Suchpfade</h2><ul><?php foreach($paths as $path): ?><li><code><?=e($path)?></code></li><?php endforeach; ?></ul></section>
<section class="card"><h2>Registry</h2>
<?php if($status===[]): ?><p>Noch keine Module installiert. Module können sowohl unter <code>DataForm5-Core/modules/</code> als auch unter <code>modules/</code> abgelegt werden.</p>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Status</th><th>Abhängigkeiten</th><th>Pfad</th></tr></thead><tbody>
<?php foreach($status as $row): ?><tr>
<td><strong><?=e((string)$row['name'])?></strong><br><small><?=e((string)$row['description'])?></small></td>
<td><?=e((string)$row['version'])?></td>
<td><?=!$row['enabled']?'deaktiviert':($row['loaded']?'geladen':'nicht geladen')?><?= $row['missing_dependencies']!==[] ? '<br><strong>Abhängigkeit fehlt</strong>' : '' ?></td>
<td><?=e($row['dependencies']===[]?'—':implode(', ',$row['dependencies']))?></td>
<td><code><?=e((string)$row['path'])?></code></td>
</tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<section class="card"><h2>Manifest-Minimum</h2><pre><code>{
  "name": "vendor.module",
  "version": "1.0.0",
  "entry": "Vendor\\Module\\Module",
  "dependencies": [],
  "enabled": true
}</code></pre></section>
<section class="card"><h2>Entwicklerplattform</h2><p><a class="button" href="events.php">Events</a> <a class="button secondary" href="container.php">Dependency Injection</a></p></section>
<?php $content=ob_get_clean();
render_page(['title'=>'Enterprise Module','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>[
    'title'=>'Modul-Registry','location'=>'Enterprise → Entwicklung → Module','short'=>'Zeigt alle von der gemeinsamen Discovery gefundenen Core- und Enterprise-Module.','goal'=>'Erweiterungen über eine einzige, nachvollziehbare Registry laden.','next'=>'Neue Enterprise-Erweiterungen unter modules/<name>/ mit module.json und bootstrap.php anlegen.','tips'=>['Modulnamen müssen global eindeutig sein.','Abhängigkeiten werden vor dem eigentlichen Modul geladen.','Ein Modul darf nicht direkt von einer Produktimplementierung abhängen, wenn ein Core-Service verfügbar ist.']
]]);
