<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';
$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit('Developer Mode ist deaktiviert.');}
$commands=['make:module'=>'Modul','make:crud'=>'CRUD-Komplettgerüst','module:validate'=>'Modul-Abnahme / Quality Gate','module:package'=>'Validiertes Modul-ZIP erzeugen','make:provider'=>'Provider','make:event'=>'Event','make:listener'=>'Listener','make:migration'=>'Migration','make:model'=>'Model','make:controller'=>'Controller','make:view'=>'View','make:api'=>'API','make:theme'=>'Theme','make:job'=>'Job','make:command'=>'Command','make:test'=>'Test'];
ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Developer','href'=>'index.php'],['label'=>'SDK','href'=>'']]);
?>
<section class="hero"><span class="badge">RC1.8 · Developer 5.6</span><h1>SDK-Konsole</h1><p>Zentrale <code>make:*</code>-Generatoren über <code>php easyit</code>.</p></section>
<section class="card"><h2>Einstieg</h2><pre><code>php easyit list
php easyit make:module CRM --namespace=EasyIT\Modules\CRM
php easyit make:provider CrmServiceProvider --module=crm
php easyit make:event CustomerCreated --module=crm</code></pre></section>
<section class="card"><h2>Generatoren</h2><div class="table-wrap"><table><thead><tr><th>Befehl</th><th>Zweck</th></tr></thead><tbody>
<?php foreach($commands as $c=>$d):?><tr><td><code><?=e($c)?></code></td><td><?=e($d)?></td></tr><?php endforeach;?>
</tbody></table></div></section>

<section class="card"><h2>SDK-Dokumentation</h2><p>Die vollständige Entwicklerreferenz liegt unter <code>docs/SDK/README.md</code> und beschreibt Modulaufbau, CLI, Lifecycle, Quality Gate, Paketformat und Beispiele.</p></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'SDK-Konsole','active'=>'developer','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user]);
