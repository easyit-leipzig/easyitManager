<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
require dirname(__DIR__).'/system/ui/layout.php';
require dirname(__DIR__).'/system/licensing/Autoload.php';
use EasyIT\Enterprise\Licensing\LicenseApiClient;

$user=enterprise_require_auth('../');
if(empty($user['customer'])||(!enterprise_can($user,'customer.users.manage')&&!enterprise_can($user,'customer.roles.manage'))){http_response_code(403);render_page(['title'=>'Benutzer & Rollen','active'=>'team','base'=>'../','content'=>'<div class="notice error">Für die Benutzer- und Rollenverwaltung fehlt die Berechtigung.</div>','app_nav'=>true,'user'=>$user]);exit;}
$root=dirname(__DIR__);require_once $root.'/system/licensing/LicenseConfigStore.php';$cfg=EnterpriseLicenseConfigStore::load($root);$token=(string)($_SESSION['enterprise_customer_account_token']??'');$error='';$message='';$team=[];
try{
    if($token==='')throw new RuntimeException('ACCOUNT_SESSION_INVALID');$api=new LicenseApiClient(is_array($cfg)?$cfg:[]);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        enterprise_check_csrf((string)($_POST['csrf']??''));$action=(string)($_POST['action']??'');
        $scopePayload=[
            'resource_scope_mode'=>(string)($_POST['resource_scope_mode']??''),
            'project_ids'=>array_values(array_map('strval',(array)($_POST['projects']??[]))),
            'dataform_uuids'=>array_values(array_map('strval',(array)($_POST['dataforms']??[]))),
        ];
        if($action==='create_user'){
            if(!enterprise_can($user,'customer.users.manage'))throw new RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
            $api->createAccountTeamUser($token,array_merge(['email'=>(string)($_POST['email']??''),'display_name'=>(string)($_POST['display_name']??''),'password'=>(string)($_POST['password']??''),'role_ids'=>array_keys((array)($_POST['roles']??[]))],$scopePayload));$message='Benutzer wurde angelegt.';
        }elseif($action==='set_user'){
            if(!enterprise_can($user,'customer.users.manage'))throw new RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
            $api->setAccountTeamUser($token,array_merge(['customer_user_id'=>(string)($_POST['customer_user_id']??''),'status'=>(string)($_POST['status']??'active'),'role_ids'=>array_keys((array)($_POST['roles']??[]))],$scopePayload));$message='Benutzer wurde aktualisiert.';
        }elseif($action==='reset_password'){
            if(!enterprise_can($user,'customer.users.manage'))throw new RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
            $api->resetAccountTeamUserPassword($token,(string)($_POST['customer_user_id']??''),(string)($_POST['new_password']??''),(string)($_POST['new_password_confirm']??''));$message='Kennwort wurde zurückgesetzt; bestehende Anmeldungen des Benutzers wurden beendet.';
        }elseif($action==='save_role'){
            if(!enterprise_can($user,'customer.roles.manage'))throw new RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
            $api->saveAccountTeamRole($token,array_merge(['role_id'=>(string)($_POST['role_id']??''),'role_name'=>(string)($_POST['role_name']??''),'label'=>(string)($_POST['label']??''),'capabilities'=>array_keys((array)($_POST['caps']??[]))],$scopePayload));$message='Rolle wurde gespeichert.';
        }else throw new RuntimeException('ACCOUNT_ACTION_INVALID');
    }
    $team=$api->accountTeam($token);
}catch(Throwable $e){$error='Die Benutzer-/Rollenverwaltung konnte nicht verarbeitet werden. ('.preg_replace('/[^A-Z0-9_]/','',$e->getMessage()).')';}
$roles=(array)($team['roles']??[]);$users=(array)($team['users']??[]);$catalog=array_merge((array)($team['capability_catalog']??[]),(array)($team['internal_catalog']??[]));
$resources=(array)($team['resource_catalog']??[]);$projects=(array)($resources['projects']??[]);$dataforms=(array)($resources['dataforms']??[]);

function hf86_scope_fields(array $projects,array $dataforms,array $scope,string $prefixMode,string $inheritLabel,bool $allowInherit): void
{
    $mode=(string)($scope['mode']??($allowInherit?'inherit':'unrestricted'));
    $selectedProjects=array_fill_keys(array_map('strval',(array)($scope['project_ids']??[])),true);
    $selectedDfs=array_fill_keys(array_map('strval',(array)($scope['dataform_uuids']??[])),true);
    ?>
    <fieldset class="full"><legend>Projekt-/DataForm-Zuordnung</legend>
        <?php if($allowInherit): ?>
            <label><input type="radio" name="resource_scope_mode" value="inherit"<?=$mode==='inherit'?' checked':''?>> <?=$inheritLabel?></label><br>
        <?php else: ?>
            <label><input type="radio" name="resource_scope_mode" value="unrestricted"<?=$mode==='unrestricted'?' checked':''?>> Alle Projekte und DataForms</label><br>
        <?php endif; ?>
        <label><input type="radio" name="resource_scope_mode" value="restricted"<?=$mode==='restricted'?' checked':''?>> Nur die nachfolgend ausgewählten Ressourcen</label>
        <div style="margin:.75rem 0 0 1.25rem">
            <strong>Ganze Projekte</strong><br>
            <?php if(!$projects): ?><small>Noch keine zentral registrierten DataForm-Projekte vorhanden.</small><?php endif; ?>
            <?php foreach($projects as $p):$pid=(string)$p['project_id'];?><label><input type="checkbox" name="projects[]" value="<?=e($pid)?>"<?=isset($selectedProjects[$pid])?' checked':''?>> <?=e((string)($p['label']??('Projekt '.$pid)))?> <small>(ID <?=e($pid)?>, <?=e((string)($p['dataform_count']??0))?> DataForms)</small></label><br><?php endforeach; ?>
            <strong>Einzelne DataForms</strong><br>
            <?php if(!$dataforms): ?><small>Noch keine zentral registrierten DataForms vorhanden.</small><?php endif; ?>
            <?php foreach($dataforms as $df):$uuid=(string)$df['dataform_uuid'];?><label><input type="checkbox" name="dataforms[]" value="<?=e($uuid)?>"<?=isset($selectedDfs[$uuid])?' checked':''?>> <?=e((string)$df['name'])?> <small>(Projekt <?=e((string)$df['project_id'])?> · DF <?=e((string)$df['local_dataform_id'])?> · <?=e((string)$df['status'])?>)</small></label><br><?php endforeach; ?>
        </div>
    </fieldset>
    <?php
}

ob_start();render_breadcrumbs([['label'=>'Enterprise','href'=>'dashboard.php'],['label'=>'Benutzer & Rollen','href'=>'']]);
?>
<section class="hero"><span class="badge">HF86 · Kunden-RBAC</span><h1>Benutzer & Rollen</h1><p>Wirksame Rechte ergeben sich aus Kundenlizenz, DataForm-Regeln, Rolle und der Projekt-/DataForm-Zuordnung. Eine direkte Benutzerzuordnung kann Rollenrechte nur weiter einschränken.</p></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<?php if(enterprise_can($user,'customer.users.manage')):?><section class="card"><h2>Benutzer anlegen</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="create_user"><label>E-Mail<input type="email" name="email" required></label><label>Anzeigename<input name="display_name"></label><label>Kennwort<input type="password" name="password" minlength="10" required></label><fieldset class="full"><legend>Rollen</legend><?php foreach($roles as $r):?><label><input type="checkbox" name="roles[<?=e((string)$r['role_id'])?>]"> <?=e((string)$r['label'])?></label> <?php endforeach;?></fieldset><?php hf86_scope_fields($projects,$dataforms,['mode'=>'inherit'],'user_new','Von den zugewiesenen Rollen übernehmen',true);?><div class="full"><button class="button">Benutzer anlegen</button></div></form></section><?php endif;?>
<section class="card"><h2>Benutzer</h2><div class="table-wrap"><table><thead><tr><th>Benutzer</th><th>Status</th><th>Rollen</th><th>Ressourcenumfang</th><th>Letzte Anmeldung</th><th>Aktion</th></tr></thead><tbody><?php foreach($users as $u):$assigned=array_fill_keys(array_map(fn($x)=>(string)$x['role_id'],(array)($u['roles']??[])),true);$uscope=(array)($u['resource_scope']??['mode'=>'inherit']);?><tr><td><?=e((string)($u['display_name']??''))?><br><small><?=e((string)($u['email']??$u['username']??''))?></small></td><td><?=e((string)$u['status'])?></td><td><?=e(implode(', ',array_map(fn($x)=>(string)$x['label'],(array)($u['roles']??[]))))?></td><td><?=($uscope['mode']??'inherit')==='inherit'?'Rollen übernehmen':'zusätzlich eingeschränkt'?></td><td><?=!empty($u['last_login_at'])?e(date('d.m.Y H:i',(int)$u['last_login_at'])):'—'?></td><td><?php if(enterprise_can($user,'customer.users.manage')):?><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="set_user"><input type="hidden" name="customer_user_id" value="<?=e((string)$u['customer_user_id'])?>"><label>Status<select name="status"><option value="active"<?=($u['status']==='active'?' selected':'')?>>aktiv</option><option value="disabled"<?=($u['status']==='disabled'?' selected':'')?>>gesperrt</option></select></label><fieldset class="full"><legend>Rollen</legend><?php foreach($roles as $r):$rid=(string)$r['role_id'];?><label><input type="checkbox" name="roles[<?=e($rid)?>]"<?=isset($assigned[$rid])?' checked':''?>> <?=e((string)$r['label'])?></label> <?php endforeach;?></fieldset><?php hf86_scope_fields($projects,$dataforms,$uscope,'user_edit','Von den zugewiesenen Rollen übernehmen',true);?><div class="full"><button class="button">Speichern</button></div></form><?php if((string)$u['customer_user_id']!==(string)$user['id']):?><form method="post" class="form-grid" style="margin-top:.75rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="customer_user_id" value="<?=e((string)$u['customer_user_id'])?>"><label>Neues Kennwort<input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label><label>Wiederholen<input type="password" name="new_password_confirm" minlength="10" autocomplete="new-password" required></label><div class="full"><button class="button" type="submit">Kennwort zurücksetzen</button></div></form><?php else:?><small>Eigenes Kennwort unter „Mein Konto“ ändern.</small><?php endif;?><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php if(enterprise_can($user,'customer.roles.manage')):?><section class="card"><h2>Rollen</h2><p>Eine Rolle kann nur Rechte innerhalb der zentralen Kundenlizenz vergeben. Die Kundenadministrator-Rolle bleibt vollständig und uneingeschränkt geschützt.</p><?php foreach($roles as $r):$system=(int)($r['is_system']??0)===1;$protected=$system&&(string)$r['role_name']==='customer_admin';$have=array_fill_keys(array_map('strval',(array)($r['capabilities']??[])),true);$rscope=(array)($r['resource_scope']??['mode'=>'unrestricted']);?><form method="post" class="form-grid" style="margin-bottom:1rem"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="save_role"><input type="hidden" name="role_id" value="<?=e((string)$r['role_id'])?>"><label>Rollenname<input name="role_name" value="<?=e((string)$r['role_name'])?>"<?=$system?' readonly':''?>></label><label>Bezeichnung<input name="label" value="<?=e((string)$r['label'])?>"<?=$protected?' readonly':''?>></label><fieldset class="full"><legend>Capabilities</legend><?php if($protected):?><strong>Alle innerhalb der Kundenlizenz + Benutzer-/Rollen-/Projektverwaltung</strong><?php else:?><?php foreach($catalog as $cap):$code=(string)$cap['capability_code'];?><label><input type="checkbox" name="caps[<?=e($code)?>]"<?=isset($have[$code])?' checked':''?>> <?=e((string)($cap['name']??$code))?> <small>(<?=e($code)?>)</small></label><br><?php endforeach;?><?php endif;?></fieldset><?php if($protected):?><input type="hidden" name="resource_scope_mode" value="unrestricted"><div class="full"><strong>Ressourcen:</strong> alle Projekte und DataForms</div><?php else:?><?php hf86_scope_fields($projects,$dataforms,$rscope,'role_edit','',false);?><div class="full"><button class="button">Rolle speichern</button></div><?php endif;?></form><?php endforeach;?>
<h3>Neue Rolle</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="save_role"><label>Rollenname<input name="role_name" placeholder="support" required></label><label>Bezeichnung<input name="label" placeholder="Support" required></label><fieldset class="full"><legend>Capabilities</legend><?php foreach($catalog as $cap):$code=(string)$cap['capability_code'];?><label><input type="checkbox" name="caps[<?=e($code)?>]"> <?=e((string)($cap['name']??$code))?> <small>(<?=e($code)?>)</small></label><br><?php endforeach;?></fieldset><?php hf86_scope_fields($projects,$dataforms,['mode'=>'unrestricted'],'role_new','',false);?><div class="full"><button class="button">Rolle anlegen</button></div></form></section><?php endif;?>
<?php $content=ob_get_clean();render_page(['title'=>'Benutzer & Rollen','active'=>'team','base'=>'../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Benutzer & Rollen','location'=>'Enterprise → Benutzer & Rollen','short'=>'Kundeninterne Benutzer, Rollen und Ressourcenzuordnungen verwalten.','goal'=>'Capabilities und sichtbare Projekte/DataForms nach dem Least-Privilege-Prinzip begrenzen.']]);
