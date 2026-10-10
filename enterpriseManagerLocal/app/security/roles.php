<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'permissions.manage');
$pdo=enterprise_pdo();enterprise_upgrade($pdo);enterprise_sync_module_capabilities($pdo,dirname(__DIR__,2).'/modules');
$message='';$error='';

function role_valid_name(string $name): bool { return (bool)preg_match('/^[a-z][a-z0-9._-]{1,79}$/',$name); }
function role_cap_ids(PDO $pdo,array $names): array{
    $names=array_values(array_unique(array_filter(array_map('strval',$names),fn($v)=>$v!=='')));
    if($names===[])return [];
    $ph=implode(',',array_fill(0,count($names),'?'));$st=$pdo->prepare("SELECT id,name FROM capabilities WHERE name IN ($ph)");$st->execute($names);return $st->fetchAll();
}
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $name=trim((string)($_POST['name']??''));$label=trim((string)($_POST['label']??''));
            if(!role_valid_name($name)) throw new RuntimeException('Technischer Rollenname ist ungültig.');
            if($label==='') throw new RuntimeException('Bezeichnung darf nicht leer sein.');
            $st=$pdo->prepare('INSERT INTO roles(name,label) VALUES (?,?)');$st->execute([$name,$label]);$rid=(int)$pdo->lastInsertId();
            enterprise_audit($pdo,(int)$user['id'],'security.role.create','role',(string)$rid,['name'=>$name]);
            $message='Rolle wurde angelegt.';
        }elseif($action==='update'){
            $rid=(int)($_POST['role_id']??0);$label=trim((string)($_POST['label']??''));$newName=trim((string)($_POST['name']??''));
            $st=$pdo->prepare('SELECT * FROM roles WHERE id=?');$st->execute([$rid]);$role=$st->fetch();if(!$role)throw new RuntimeException('Rolle wurde nicht gefunden.');
            if($label==='')throw new RuntimeException('Bezeichnung darf nicht leer sein.');
            if(in_array($role['name'],['admin','superadmin'],true)){$newName=(string)$role['name'];}elseif(!role_valid_name($newName))throw new RuntimeException('Technischer Rollenname ist ungültig.');
            $caps=role_cap_ids($pdo,array_keys((array)($_POST['caps']??[])));
            $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE roles SET name=?,label=? WHERE id=?')->execute([$newName,$label,$rid]);
                if(!in_array($role['name'],['admin','superadmin'],true)){
                    $pdo->prepare('DELETE FROM role_capabilities WHERE role_id=?')->execute([$rid]);
                    $ins=$pdo->prepare('INSERT INTO role_capabilities(role_id,capability_id) VALUES (?,?)');foreach($caps as $c)$ins->execute([$rid,(int)$c['id']]);
                }
                enterprise_audit($pdo,(int)$user['id'],'security.role.update','role',(string)$rid,['name'=>$newName,'capabilities'=>array_column($caps,'name')]);
                $pdo->commit();$message='Rolle wurde aktualisiert.';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }elseif($action==='delete'){
            $rid=(int)($_POST['role_id']??0);$st=$pdo->prepare('SELECT * FROM roles WHERE id=?');$st->execute([$rid]);$role=$st->fetch();if(!$role)throw new RuntimeException('Rolle wurde nicht gefunden.');
            if(in_array($role['name'],['admin','superadmin'],true))throw new RuntimeException('Die Systemrollen admin und superadmin dürfen nicht gelöscht werden.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id=?');$q->execute([$rid]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Rolle ist Benutzern zugewiesen und kann erst nach Entfernung dieser Zuweisungen gelöscht werden.');
            enterprise_audit($pdo,(int)$user['id'],'security.role.delete','role',(string)$rid,['name'=>$role['name']]);
            $pdo->prepare('DELETE FROM roles WHERE id=?')->execute([$rid]);$message='Rolle wurde gelöscht.';
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}

$roles=$pdo->query('SELECT r.*,COUNT(DISTINCT ur.user_id) user_count FROM roles r LEFT JOIN user_roles ur ON ur.role_id=r.id GROUP BY r.id ORDER BY r.name')->fetchAll();
$caps=$pdo->query('SELECT * FROM capabilities ORDER BY module_name,name')->fetchAll();
$assigned=[];foreach($pdo->query('SELECT role_id,c.name FROM role_capabilities rc JOIN capabilities c ON c.id=rc.capability_id')->fetchAll() as $r)$assigned[(int)$r['role_id']][]=$r['name'];

ob_start();render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Benutzer & Rechte','href'=>'users.php'],['label'=>'Rollen','href'=>'']]);?>
<section class="hero"><span class="badge">HF9 · RBAC Administration</span><h1>Rollenverwaltung</h1><p>Rollen anlegen, bearbeiten und mit Capabilities verknüpfen.</p><div class="actions"><a class="button secondary" href="users.php">Benutzer verwalten</a><a class="button secondary" href="capabilities.php">Capability-Matrix</a><a class="button secondary" href="audit.php">Audit-Protokoll</a></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="card"><h2>Neue Rolle</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="create"><label>Technischer Name<input name="name" placeholder="viewer_external" required></label><label>Bezeichnung<input name="label" placeholder="Externer Leser" required></label><div><button class="button">Rolle anlegen</button></div></form></section>
<?php foreach($roles as $role):?><section class="card"><h2><?=e((string)$role['label'])?> <code><?=e((string)$role['name'])?></code></h2><p><?=(int)$role['user_count']?> Benutzer zugewiesen.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="role_id" value="<?=(int)$role['id']?>">
<div class="form-grid"><label>Technischer Name<input name="name" value="<?=e((string)$role['name'])?>" <?=in_array($role['name'],['admin','superadmin'],true)?'readonly':''?>></label><label>Bezeichnung<input name="label" value="<?=e((string)$role['label'])?>" required></label></div>
<h3>Capabilities</h3><?php if(in_array($role['name'],['admin','superadmin'],true)):?><p>Diese administrative Systemrolle besitzt systemweit alle Capabilities und ist geschützt.</p><?php endif;?>
<?php foreach($caps as $c):?><label style="display:block;margin:.35rem 0"><input type="checkbox" name="caps[<?=e((string)$c['name'])?>]" <?=(in_array($role['name'],['admin','superadmin'],true)||in_array($c['name'],$assigned[(int)$role['id']]??[],true))?'checked':''?> <?=in_array($role['name'],['admin','superadmin'],true)?'disabled':''?>> <code><?=e((string)$c['name'])?></code> — <?=e((string)$c['label'])?><?=!empty($c['module_name'])?' ['.e((string)$c['module_name']).']':''?></label><?php endforeach;?>
<button class="button" type="submit">Rolle speichern</button></form>
<?php if(!in_array($role['name'],['admin','superadmin'],true)):?><form method="post" onsubmit="return confirm('Rolle wirklich löschen?');" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="role_id" value="<?=(int)$role['id']?>"><button class="button secondary">Rolle löschen</button></form><?php endif;?>
</section><?php endforeach;?>
<?php $content=ob_get_clean();render_page(['title'=>'Rollenverwaltung','active'=>'security','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>[
'title'=>'Rollenverwaltung','location'=>'Enterprise → Benutzer & Rechte → Rollen','short'=>'Rollen und ihre Capabilities zentral pflegen.','goal'=>'Berechtigungen über nachvollziehbare Rollen statt Einzelrechte vergeben.','tips'=>['Die Rollen superadmin und admin sind geschützte Systemrollen.','Zugewiesene Rollen müssen vor dem Löschen von Benutzern entfernt werden.','Capability-Änderungen werden auditiert.']
]]);
