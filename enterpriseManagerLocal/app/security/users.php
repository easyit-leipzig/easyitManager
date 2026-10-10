<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'permissions.manage');
$pdo=enterprise_pdo();
enterprise_upgrade($pdo);

function security_active_admin_count(PDO $pdo, ?int $excludeUserId=null): int {
    $sql="SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.name='admin'";
    $params=[];
    if($excludeUserId!==null){$sql.=' AND u.id<>?';$params[]=$excludeUserId;}
    $st=$pdo->prepare($sql);$st->execute($params);
    return (int)$st->fetchColumn();
}
function security_active_superadmin_count(PDO $pdo, ?int $excludeUserId=null): int {
    $sql="SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.name='superadmin'";
    $params=[];
    if($excludeUserId!==null){$sql.=' AND u.id<>?';$params[]=$excludeUserId;}
    $st=$pdo->prepare($sql);$st->execute($params);
    return (int)$st->fetchColumn();
}
function security_role_ids(PDO $pdo, array $raw): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>0)));
    if($ids===[]) return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT id FROM roles WHERE id IN ($ph)");
    $st->execute($ids);
    return array_map('intval',array_column($st->fetchAll(),'id'));
}
function security_has_admin_role(PDO $pdo,array $roleIds): bool {
    if($roleIds===[]) return false;
    $ph=implode(',',array_fill(0,count($roleIds),'?'));
    $st=$pdo->prepare("SELECT COUNT(*) FROM roles WHERE name='admin' AND id IN ($ph)");
    $st->execute($roleIds);
    return (int)$st->fetchColumn()>0;
}
function security_has_superadmin_role(PDO $pdo,array $roleIds): bool {
    if($roleIds===[]) return false;
    $ph=implode(',',array_fill(0,count($roleIds),'?'));
    $st=$pdo->prepare("SELECT COUNT(*) FROM roles WHERE name='superadmin' AND id IN ($ph)");
    $st->execute($roleIds);
    return (int)$st->fetchColumn()>0;
}

$message='';$error='';
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $username=trim((string)($_POST['username']??''));
            $email=trim((string)($_POST['email']??''));
            $password=(string)($_POST['password']??'');
            $roleIds=security_role_ids($pdo,(array)($_POST['roles']??[]));
            if($username==='' || !preg_match('/^[A-Za-z0-9._-]{3,120}$/',$username)) throw new RuntimeException('Benutzername muss 3–120 Zeichen lang sein und darf Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
            if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-Mail-Adresse ist ungültig.');
            if(strlen($password)<10) throw new RuntimeException('Kennwort muss mindestens 10 Zeichen lang sein.');
            if($roleIds===[]) throw new RuntimeException('Mindestens eine Rolle auswählen.');
            if(security_has_superadmin_role($pdo,$roleIds) && !enterprise_is_superadmin($user)) throw new RuntimeException('Nur ein Superadministrator darf die Superadmin-Rolle vergeben.');
            $pdo->beginTransaction();
            try{
                $st=$pdo->prepare('INSERT INTO users(username,email,password_hash,is_active) VALUES (?,?,?,1)');
                $st->execute([$username,$email!==''?$email:null,password_hash($password,PASSWORD_DEFAULT)]);
                $uid=(int)$pdo->lastInsertId();
                $ins=$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES (?,?)');
                foreach($roleIds as $rid)$ins->execute([$uid,$rid]);
                enterprise_audit($pdo,(int)$user['id'],'security.user.create','user',(string)$uid,['username'=>$username,'roles'=>$roleIds]);
                $pdo->commit();
                $message='Benutzer wurde angelegt.';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }elseif($action==='update'){
            $uid=(int)($_POST['user_id']??0);
            $st=$pdo->prepare('SELECT * FROM users WHERE id=?');$st->execute([$uid]);$target=$st->fetch();
            if(!$target) throw new RuntimeException('Benutzer wurde nicht gefunden.');
            $username=trim((string)($_POST['username']??''));
            $email=trim((string)($_POST['email']??''));
            $active=isset($_POST['is_active'])?1:0;
            $roleIds=security_role_ids($pdo,(array)($_POST['roles']??[]));
            if($username==='' || !preg_match('/^[A-Za-z0-9._-]{3,120}$/',$username)) throw new RuntimeException('Benutzername ist ungültig.');
            if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-Mail-Adresse ist ungültig.');
            if($roleIds===[]) throw new RuntimeException('Mindestens eine Rolle auswählen.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name='admin'");$q->execute([$uid]);$currentlyAdmin=(int)$q->fetchColumn()>0;
            $qs=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name='superadmin'");$qs->execute([$uid]);$currentlySuperadmin=(int)$qs->fetchColumn()>0;
            $willAdmin=security_has_admin_role($pdo,$roleIds);
            $willSuperadmin=security_has_superadmin_role($pdo,$roleIds);
            if(($currentlySuperadmin!==$willSuperadmin) && !enterprise_is_superadmin($user)) throw new RuntimeException('Nur ein Superadministrator darf die Superadmin-Rolle verändern.');
            if($currentlySuperadmin && (!$active || !$willSuperadmin) && security_active_superadmin_count($pdo,$uid)===0) throw new RuntimeException('Der letzte aktive Superadministrator darf nicht deaktiviert oder aus der Superadmin-Rolle entfernt werden.');
            if($currentlyAdmin && (!$active || !$willAdmin) && security_active_admin_count($pdo,$uid)===0) throw new RuntimeException('Der letzte aktive Administrator darf nicht deaktiviert oder aus der Admin-Rolle entfernt werden.');
            if($uid===(int)$user['id'] && !$active) throw new RuntimeException('Das aktuell angemeldete Konto kann nicht deaktiviert werden.');
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare('UPDATE users SET username=?,email=?,is_active=? WHERE id=?');$up->execute([$username,$email!==''?$email:null,$active,$uid]);
                $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$uid]);
                $ins=$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES (?,?)');foreach($roleIds as $rid)$ins->execute([$uid,$rid]);
                enterprise_audit($pdo,(int)$user['id'],'security.user.update','user',(string)$uid,['username'=>$username,'active'=>(bool)$active,'roles'=>$roleIds]);
                $pdo->commit();$message='Benutzer wurde aktualisiert.';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }elseif($action==='password'){
            $uid=(int)($_POST['user_id']??0);$password=(string)($_POST['new_password']??'');
            if(strlen($password)<10) throw new RuntimeException('Neues Kennwort muss mindestens 10 Zeichen lang sein.');
            $st=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');$st->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
            if($st->rowCount()===0) throw new RuntimeException('Benutzer wurde nicht gefunden oder Kennwort konnte nicht aktualisiert werden.');
            enterprise_audit($pdo,(int)$user['id'],'security.user.password_reset','user',(string)$uid);
            $message='Kennwort wurde neu gesetzt.';
        }elseif($action==='delete'){
            $uid=(int)($_POST['user_id']??0);
            if($uid===(int)$user['id']) throw new RuntimeException('Das aktuell angemeldete Konto kann nicht gelöscht werden.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name='admin'");$q->execute([$uid]);$isAdmin=(int)$q->fetchColumn()>0;
            $qs=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name='superadmin'");$qs->execute([$uid]);$isSuperadmin=(int)$qs->fetchColumn()>0;
            if($isSuperadmin && !enterprise_is_superadmin($user)) throw new RuntimeException('Nur ein Superadministrator darf ein Superadmin-Konto löschen.');
            if($isSuperadmin && security_active_superadmin_count($pdo,$uid)===0) throw new RuntimeException('Der letzte aktive Superadministrator darf nicht gelöscht werden.');
            if($isAdmin && security_active_admin_count($pdo,$uid)===0) throw new RuntimeException('Der letzte aktive Administrator darf nicht gelöscht werden.');
            $st=$pdo->prepare('SELECT username FROM users WHERE id=?');$st->execute([$uid]);$name=$st->fetchColumn();
            if($name===false) throw new RuntimeException('Benutzer wurde nicht gefunden.');
            enterprise_audit($pdo,(int)$user['id'],'security.user.delete','user',(string)$uid,['username'=>$name]);
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $message='Benutzer wurde gelöscht.';
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}

$roles=$pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
$rows=$pdo->query("SELECT u.id,u.username,u.email,u.is_active,u.created_at,GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS role_names,GROUP_CONCAT(r.id ORDER BY r.id SEPARATOR ',') AS role_ids FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.username")->fetchAll();

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Benutzer & Rechte','href'=>''],['label'=>'Benutzer','href'=>'']]);
?>
<section class="hero"><span class="badge">Identity Administration</span><h1>Benutzerverwaltung</h1><p>Enterprise-Anmeldekonten, Aktivstatus, Kennwörter und Rollenzuweisungen zentral verwalten. Technische MySQL-/PostgreSQL-Konten werden getrennt verwaltet.</p><div class="actions"><a class="button secondary" <?= easyit_button_attributes('database_benutzer') ?> href="database-users.php">DB-Benutzer</a><a class="button secondary" <?= easyit_button_attributes('security_rollen') ?> href="roles.php">Rollen verwalten</a><a class="button secondary" <?= easyit_button_attributes('security_capabilities') ?> href="capabilities.php">Capabilities</a><a class="button secondary" <?= easyit_button_attributes('verlauf') ?> href="audit.php">Audit-Protokoll</a></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="card"><h2>Neuen Benutzer anlegen</h2>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="create">
<label>Benutzername<input name="username" required minlength="3" maxlength="120" autocomplete="off"></label>
<label>E-Mail<input type="email" name="email" autocomplete="off"></label>
<label>Kennwort<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
<fieldset><legend>Rollen</legend><?php foreach($roles as $r):?><label style="display:block"><input type="checkbox" name="roles[]" value="<?=(int)$r['id']?>"> <?=e((string)$r['label'])?> <code><?=e((string)$r['name'])?></code></label><?php endforeach;?></fieldset>
<div><button class="button" type="submit">Benutzer anlegen</button></div></form></section>

<section><h2>Vorhandene Benutzer</h2>
<?php foreach($rows as $row):$assigned=array_values(array_filter(array_map('intval',explode(',',(string)($row['role_ids']??'')))));?>
<article class="card">
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?=(int)$row['id']?>">
<label>Benutzername<input name="username" value="<?=e((string)$row['username'])?>" required></label>
<label>E-Mail<input type="email" name="email" value="<?=e((string)($row['email']??''))?>"></label>
<label><input type="checkbox" name="is_active" value="1" <?=$row['is_active']?'checked':''?>> Aktiv</label>
<fieldset><legend>Rollen</legend><?php foreach($roles as $r):?><label style="display:block"><input type="checkbox" name="roles[]" value="<?=(int)$r['id']?>" <?=in_array((int)$r['id'],$assigned,true)?'checked':''?>> <?=e((string)$r['label'])?> <code><?=e((string)$r['name'])?></code></label><?php endforeach;?></fieldset>
<div><button class="button" type="submit">Änderungen speichern</button></div></form>
<form method="post" class="form-grid" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?=(int)$row['id']?>">
<label>Neues Kennwort<input type="password" name="new_password" minlength="10" required autocomplete="new-password"></label><div><button class="button secondary" type="submit">Kennwort neu setzen</button></div></form>
<?php if((int)$row['id']!==(int)$user['id']):?><form method="post" onsubmit="return confirm('Benutzer wirklich löschen?');" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?=(int)$row['id']?>"><button class="button secondary" type="submit">Benutzer löschen</button></form><?php endif;?>
</article><?php endforeach;?></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'Benutzerverwaltung','active'=>'security','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>[
'title'=>'Benutzer & Rechte','location'=>'Enterprise → Benutzer & Rechte → Benutzer','short'=>'Konten anlegen, Rollen zuweisen und Kennwörter sicher neu setzen.','goal'=>'Administrativen Zugriff nachvollziehbar und nach dem Least-Privilege-Prinzip verwalten.','tips'=>['Der letzte aktive Superadministrator ist geschützt.','Die Superadmin-Rolle kann nur durch einen Superadministrator vergeben oder entzogen werden.','Der letzte aktive Administrator ist geschützt.','Kennwörter werden ausschließlich als Passwort-Hash gespeichert.','Änderungen werden im Audit-Log protokolliert.']
]]);
