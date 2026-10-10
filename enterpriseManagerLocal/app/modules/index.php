<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Modules\SDK\ModuleValidator;
use DataForm5\Modules\Packages\{ModuleArchiveExtractor,ModulePackageInstaller,ModulePackageRegistry,ModuleInstallHistory,ModuleCatalog};
use DataForm5\Modules\Versioning\ModuleCompatibility;
use DataForm5\Modules\Migrations\{ModuleMigrationRepository,ModuleMigrationManager};

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'modules.manage');
$pdo=enterprise_pdo(); enterprise_upgrade($pdo); enterprise_sync_module_capabilities($pdo,dirname(__DIR__,2).'/modules');
$base=dirname(__DIR__,2); $fs=new Filesystem();
$registry=new ModulePackageRegistry($base.'/modules/.installed.json',$fs);
$history=new ModuleInstallHistory($base.'/modules/.history.json',$fs);
$compatibility=new ModuleCompatibility((string)@file_get_contents($base.'/DataForm5-Core/VERSION') ?: '0.0.0');
$migrationRepository=new ModuleMigrationRepository($pdo);
$migrationManager=new ModuleMigrationManager($pdo,$migrationRepository);
$installer=new ModulePackageInstaller($base.'/modules',$base.'/DataForm5-Core/storage/framework/module-packages',$fs,new ModuleValidator(),$registry,new ModuleArchiveExtractor($fs),$history,$compatibility,$migrationManager);
$message=''; $error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if(in_array($action,['install','update'],true)){
            if(!class_exists('ZipArchive')) throw new RuntimeException('PHP-Erweiterung ext-zip / ZipArchive ist nicht aktiviert.');
            $upload=$_FILES['module_package']??null;
            if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Bitte ein gültiges Modul-ZIP auswählen.');
            if((int)($upload['size']??0)>20*1024*1024) throw new RuntimeException('Das Modul-ZIP darf maximal 20 MB groß sein.');
            $original=(string)($upload['name']??'module.zip');
            if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='zip') throw new RuntimeException('Es sind nur ZIP-Pakete erlaubt.');
            $tmp=(string)($upload['tmp_name']??''); if($tmp===''||!is_uploaded_file($tmp)) throw new RuntimeException('Upload konnte nicht verifiziert werden.');
            $result=$action==='install'?$installer->installArchive($tmp):$installer->updateArchive($tmp);
            $name=(string)($result['module']['name']??''); $version=(string)($result['module']['version']??'');
            enterprise_audit($pdo,(int)($user['id']??0),'module.'.$action,'module',$name,['version'=>$version,'file'=>$original]);
            enterprise_event_dispatch('module.'.$action.'ed',['name'=>$name,'version'=>$version],['user_id'=>$user['id']??null]);
            $message=$action==='install'?"Modul {$name} {$version} wurde installiert.":"Modul {$name} wurde auf {$version} aktualisiert.";
        } elseif($action==='remove'){
            $name=trim((string)($_POST['module']??'')); if($name==='') throw new RuntimeException('Modulname fehlt.');
            $installer->uninstall($name); enterprise_audit($pdo,(int)($user['id']??0),'module.remove','module',$name); enterprise_event_dispatch('module.removed',['name'=>$name],['user_id'=>$user['id']??null]); $message="Modul {$name} wurde entfernt.";
        } elseif(in_array($action,['enable','disable'],true)){
            $name=trim((string)($_POST['module']??'')); if($name==='') throw new RuntimeException('Modulname fehlt.');
            $enabled=$action==='enable'; $installer->setEnabled($name,$enabled); enterprise_audit($pdo,(int)($user['id']??0),'module.'.$action,'module',$name); enterprise_event_dispatch('module.'.$action.'d',['name'=>$name],['user_id'=>$user['id']??null]); $message="Modul {$name} wurde ".($enabled?'aktiviert.':'deaktiviert.');
        } else throw new RuntimeException('Unbekannte Modulaktion.');
    }catch(Throwable $e){$error=$e->getMessage(); $history->add((string)($_POST['action']??'unknown'),trim((string)($_POST['module']??($_FILES['module_package']['name']??'unknown'))),'error',['message'=>$error]);}
}

$installed=$installer->installed();
$kernel=enterprise_kernel(); $manager=$kernel->container()->get(\DataForm5\Modules\Core\ModuleManager::class); $status=$manager->status();
$catalog=new ModuleCatalog($registry,$manager,$base.'/packages',$compatibility); $catalogInstalled=$catalog->installed(); $availablePackages=$catalog->availablePackages(); $historyRows=$history->recent(50);
$statusByName=[]; foreach($status as $row)$statusByName[(string)$row['name']]=$row;
ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Module','href'=>'']]); ?>
<section class="hero"><span class="badge">RC1.7 · Phase K</span><h1>Modulkatalog, Versionen &amp; Historie</h1><p>Enterprise-Module installieren, verwalten und Core-Kompatibilität, Versionsregeln und Updates nachvollziehen.</p></section>
<?php if($message!==''):?><div class="card"><strong><?=e($message)?></strong></div><?php endif;?>
<?php if($error!==''):?><div class="card"><strong>Fehler:</strong> <?=e($error)?></div><?php endif;?>
<section class="grid cols-3">
<article class="card"><h2><?=count($installed)?></h2><p>als Paket installierte Module</p></article>
<article class="card"><h2><?=count(array_filter($status,fn($r)=>(bool)$r['enabled']))?></h2><p>aktivierte Module</p></article>
<article class="card"><h2><?=e($compatibility->coreVersion())?></h2><p>erkannte Core-Version</p></article>
</section>
<section class="card"><h2>Modul installieren</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="install"><p><input type="file" name="module_package" accept=".zip,application/zip" required></p><button class="button" type="submit">ZIP installieren</button></form></section>
<section class="card"><h2>Modul aktualisieren</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="update"><p><input type="file" name="module_package" accept=".zip,application/zip" required></p><button class="button" type="submit">Update installieren</button></form></section>
<section class="card"><h2>Installierte Enterprise-Module</h2>
<?php if($installed===[]):?><p>Noch keine Enterprise-Module über den Paketinstaller installiert.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Zustand</th><th>Installiert</th><th>Aktionen</th></tr></thead><tbody>
<?php foreach($installed as $name=>$record): $row=$statusByName[$name]??null; $enabled=$row!==null?(bool)$row['enabled']:(bool)($record['enabled']??true); ?>
<tr><td><strong><?=e((string)$name)?></strong></td><td><?=e((string)($record['version']??'—'))?></td><td><?=$enabled?'aktiv':'deaktiviert'?><?=($row&&$row['missing_dependencies']!==[])?'<br><strong>Abhängigkeit fehlt</strong>':''?></td><td><?=e((string)($record['installed_at']??'—'))?></td><td>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="module" value="<?=e((string)$name)?>"><input type="hidden" name="action" value="<?=$enabled?'disable':'enable'?>"><button class="button" <?= easyit_button_attributes($enabled?'sperren':'entsperren') ?> type="submit"><?=$enabled?'Deaktivieren':'Aktivieren'?></button></form>
<a class="button secondary" href="config.php?module=<?=e((string)$name)?>">Konfiguration</a>
<form method="post" style="display:inline" onsubmit="return confirm('Modul wirklich entfernen?');"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="module" value="<?=e((string)$name)?>"><input type="hidden" name="action" value="remove"><button class="button secondary" type="submit">Entfernen</button></form>
</td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>

<section class="card"><h2>Modulkatalog</h2>
<?php if($catalogInstalled===[]):?><p>Keine über den Paketinstaller registrierten Enterprise-Module vorhanden.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Modul</th><th>Version</th><th>Herkunft</th><th>Status</th><th>Installiert</th><th>Aktualisiert</th><th>Update</th></tr></thead><tbody>
<?php foreach($catalogInstalled as $entry):?><tr><td><strong><?=e((string)$entry['name'])?></strong></td><td><?=e((string)$entry['version'])?></td><td><?=e((string)$entry['source'])?></td><td><?=$entry['enabled']?'aktiv':'deaktiviert'?><?=($entry['missing_dependencies']??[])!==[]?'<br><strong>Abhängigkeit fehlt</strong>':''?></td><td><?=e((string)($entry['installed_at']??'—'))?></td><td><?=e((string)($entry['updated_at']??'—'))?></td><td><?=($entry['update_available']??false)?'<strong>'.e((string)$entry['available_version']).' verfügbar</strong>':'aktuell'?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section>
<section class="card"><h2>Verfügbare Pakete</h2>
<?php if($availablePackages===[]):?><p>Im Verzeichnis <code>packages/</code> liegen derzeit keine Modul-ZIPs.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Datei</th><th>Modul</th><th>Version</th><th>Größe</th><th>Core</th><th>Kompatibilität</th><th>Manifest</th></tr></thead><tbody>
<?php foreach($availablePackages as $pkg):?><tr><td><?=e((string)$pkg['file'])?></td><td><?=e((string)($pkg['name']??'—'))?></td><td><?=e((string)($pkg['version']??'—'))?></td><td><?=number_format(((int)$pkg['size'])/1024,1,',','.')?> KB</td><td><?=e((string)($pkg['core_version']??'*'))?></td><td><?=($pkg['compatible']===null?'—':($pkg['compatible']?'<strong>kompatibel</strong>':'<strong>nicht kompatibel</strong>'))?></td><td><?=$pkg['readable']?'gelesen':(class_exists('ZipArchive')?'nicht gefunden':'ext-zip fehlt')?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section>

<section class="card"><h2>Modulmigrationen</h2>
<?php if($installed===[]):?><p>Keine installierten Enterprise-Module vorhanden.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Modul</th><th>Migrationen</th><th>Ausgeführt</th><th>Ausstehend</th><th>Integrität</th></tr></thead><tbody>
<?php foreach($installed as $name=>$record): $ms=$migrationManager->status((string)$name,(string)($record['path']??$base.'/modules/'.$name)); $appliedCount=count(array_filter($ms,fn($m)=>(bool)$m['applied'])); $pendingCount=count(array_filter($ms,fn($m)=>!(bool)$m['applied'])); $changedCount=count(array_filter($ms,fn($m)=>(bool)$m['changed'])); ?>
<tr><td><strong><?=e((string)$name)?></strong></td><td><?=count($ms)?></td><td><?=$appliedCount?></td><td><?=$pendingCount?></td><td><?=$changedCount===0?'OK':'<strong>'.$changedCount.' verändert/fehlt</strong>'?></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<section class="card"><h2>Installationshistorie</h2>
<?php if($historyRows===[]):?><p>Noch keine Modulaktionen protokolliert.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Aktion</th><th>Modul/Paket</th><th>Ergebnis</th><th>Details</th></tr></thead><tbody>
<?php foreach($historyRows as $h):?><tr><td><?=e((string)($h['time']??'—'))?></td><td><?=e((string)($h['action']??'—'))?></td><td><?=e((string)($h['module']??'—'))?></td><td><?=e((string)($h['status']??'—'))?></td><td><code><?=e(json_encode($h['context']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></code></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section>
<section class="card"><h2>Entwicklerdiagnose</h2><p><a class="button secondary" href="../developer/modules.php">Modul-Registry öffnen</a></p></section>
<?php $content=ob_get_clean();
render_page(['title'=>'Modulkatalog & Historie','active'=>'modules','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>[
'title'=>'Modulkatalog & Historie','location'=>'Enterprise → Module','short'=>'Verwaltet Enterprise-Module, Versionen, Datenbankmigrationen und Installationshistorie.','goal'=>'Module sicher über die zentrale Paketplattform verwalten.','next'=>'Ein mit dem Modul-SDK erzeugtes ZIP auswählen und installieren.','steps'=>['ZIP auswählen.','Installation oder Update starten.','Status und Abhängigkeiten prüfen.','Modul bei Bedarf aktivieren oder deaktivieren.'],'tips'=>['Nur Administratoren dürfen Module verändern.','Für ZIP-Installationen muss PHP ext-zip aktiviert sein.','Updates müssen eine höhere Versionsnummer besitzen.','Module mit abhängigen Modulen können nicht entfernt werden.']
]]);
