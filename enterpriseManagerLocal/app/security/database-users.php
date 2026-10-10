<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/app/database_users.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'permissions.manage');
$appPdo=enterprise_pdo();
enterprise_upgrade($appPdo);
$env=enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env');
$sources=enterprise_dbuser_sources($env);
$selected=(string)($_GET['source']??$_POST['source']??'');
if ($selected==='' || !isset($sources[$selected])) $selected=(string)(array_key_first($sources)??'');
$message='';$error='';$rows=[];$databases=[];$source=null;

try {
    if ($selected==='') throw new RuntimeException('Für die Datenbank-Benutzerverwaltung ist derzeit weder ein MySQL- noch ein PostgreSQL-Administrations- oder Projektspeicher konfiguriert.');
    $source=enterprise_dbuser_source($env,$selected);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        $username=trim((string)($_POST['username']??''));
        $host=trim((string)($_POST['host']??'localhost')) ?: 'localhost';
        if ($action==='create') {
            $password=(string)($_POST['password']??'');
            $enabled=isset($_POST['login_enabled']);
            enterprise_dbuser_create($source,$username,$host,$password,$enabled);
            $database=trim((string)($_POST['database']??''));
            $schema=trim((string)($_POST['schema']??($source['schema']??'public'))) ?: 'public';
            $access=(string)($_POST['access']??'none');
            if ($database!=='' && $access!=='none') enterprise_dbuser_set_access($source,$username,$host,$database,$schema,$access);
            enterprise_audit($appPdo,(int)$user['id'],'database.user.create','database_user',$username,['source'=>$selected,'driver'=>$source['driver'],'host'=>$source['driver']==='mysql'?$host:null,'database'=>$database,'schema'=>$source['driver']==='pgsql'?$schema:null,'access'=>$access,'login_enabled'=>$enabled]);
            $message='Datenbank-Benutzer wurde angelegt.';
        } elseif ($action==='password') {
            enterprise_dbuser_set_password($source,$username,$host,(string)($_POST['new_password']??''));
            enterprise_audit($appPdo,(int)$user['id'],'database.user.password','database_user',$username,['source'=>$selected,'driver'=>$source['driver']]);
            $message='Kennwort wurde aktualisiert.';
        } elseif ($action==='login') {
            $enabled=(string)($_POST['enabled']??'0')==='1';
            enterprise_dbuser_set_login($source,$username,$host,$enabled);
            enterprise_audit($appPdo,(int)$user['id'],'database.user.login','database_user',$username,['source'=>$selected,'driver'=>$source['driver'],'enabled'=>$enabled]);
            $message=$enabled?'Datenbank-Benutzer wurde freigeschaltet.':'Datenbank-Benutzer wurde gesperrt.';
        } elseif ($action==='access') {
            $database=trim((string)($_POST['database']??''));
            $schema=trim((string)($_POST['schema']??($source['schema']??'public'))) ?: 'public';
            $access=(string)($_POST['access']??'none');
            enterprise_dbuser_set_access($source,$username,$host,$database,$schema,$access);
            enterprise_audit($appPdo,(int)$user['id'],'database.user.access','database_user',$username,['source'=>$selected,'driver'=>$source['driver'],'database'=>$database,'schema'=>$source['driver']==='pgsql'?$schema:null,'access'=>$access]);
            $message='Datenbankzugriff wurde aktualisiert.';
        } elseif ($action==='delete') {
            enterprise_dbuser_drop($source,$username,$host);
            enterprise_audit($appPdo,(int)$user['id'],'database.user.delete','database_user',$username,['source'=>$selected,'driver'=>$source['driver'],'host'=>$source['driver']==='mysql'?$host:null]);
            $message='Datenbank-Benutzer wurde gelöscht.';
        }
    }
    $rows=enterprise_dbuser_list($source);
    $databases=enterprise_dbuser_databases($source);
} catch (Throwable $e) { $error=$e->getMessage(); }

$accessLabels=['none'=>'Kein Zugriff','read'=>'Nur lesen','readwrite'=>'Lesen / Schreiben','full'=>'Vollzugriff auf Datenbank / Schema'];
ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Benutzer & Rechte','href'=>'users.php'],
    ['label'=>'DB-Benutzer','href'=>''],
]);
?>
<section class="hero">
  <span class="badge">MySQL / PostgreSQL</span>
  <h1>Datenbank-Benutzerverwaltung</h1>
  <p>Verwaltet technische Datenbankkonten getrennt von den Enterprise-Anmeldekonten. Es werden ausschließlich die konfigurierten MySQL- und PostgreSQL-Server angeboten.</p>
  <div class="actions">
    <a class="button secondary" <?= easyit_button_attributes('security_benutzer') ?> href="users.php">Enterprise-Benutzer</a>
    <a class="button secondary" <?= easyit_button_attributes('security_rollen') ?> href="roles.php">Rollen</a>
    <a class="button secondary" <?= easyit_button_attributes('security_capabilities') ?> href="capabilities.php">Capabilities</a>
  </div>
</section>

<?php if ($sources): ?>
<section class="card">
  <h2>Datenbankserver auswählen</h2>
  <form method="get" class="form-grid">
    <label>Konfiguration
      <select name="source" onchange="this.form.submit()">
        <?php foreach($sources as $key=>$s): ?>
          <option value="<?=e($key)?>" <?=$selected===$key?'selected':''?>><?=e($s['label'].' · '.($s['driver']==='pgsql'?'PostgreSQL':'MySQL').' · '.$s['host'].':'.$s['port'])?><?=$s['available']?'':' · PDO fehlt'?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button class="button" type="submit">Anzeigen</button></noscript>
  </form>
</section>
<?php endif; ?>

<?php if ($message!==''): ?><div class="notice success" role="status"><?=e($message)?></div><?php endif; ?>
<?php if ($error!==''): ?><div class="notice error" role="alert"><?=e($error)?></div><?php endif; ?>

<?php if (is_array($source) && $error===''): ?>
<section class="card">
  <h2>Neuen Datenbank-Benutzer anlegen</h2>
  <p>Die Zugriffsstufen gelten nur für die gewählte Datenbank<?= $source['driver']==='pgsql' ? ' und das angegebene Schema' : '' ?>. Globale Superuser-/Serveradministratorrechte werden hier nicht vergeben.</p>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
    <input type="hidden" name="source" value="<?=e($selected)?>">
    <input type="hidden" name="action" value="create">
    <label>Benutzername<input name="username" required pattern="[A-Za-z][A-Za-z0-9_]*" maxlength="<?=$source['driver']==='mysql'?32:63?>"></label>
    <?php if ($source['driver']==='mysql'): ?><label>Host<input name="host" value="localhost" required></label><?php endif; ?>
    <label>Kennwort<input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
    <label class="check"><input type="checkbox" name="login_enabled" value="1" checked> Anmeldung erlaubt</label>
    <label>Datenbank
      <select name="database">
        <option value="">— zunächst keine Zuweisung —</option>
        <?php foreach($databases as $db): ?><option value="<?=e($db)?>" <?=$db===($source['database']??'')?'selected':''?>><?=e($db)?></option><?php endforeach; ?>
      </select>
    </label>
    <?php if ($source['driver']==='pgsql'): ?><label>Schema<input name="schema" value="<?=e((string)($source['schema']??'public'))?>" required></label><?php endif; ?>
    <label>Zugriffsstufe<select name="access"><?php foreach($accessLabels as $k=>$v):?><option value="<?=e($k)?>" <?=$k==='full'?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
    <div><button class="button" <?=easyit_button_attributes('neu','database_user')?>>Datenbank-Benutzer anlegen</button></div>
  </form>
</section>

<section class="card">
  <h2>Vorhandene Datenbank-Benutzer</h2>
  <?php if (!$rows): ?><p>Keine Benutzer gefunden oder das Verwaltungskonto besitzt keine Leserechte auf die Systembenutzertabelle.</p><?php endif; ?>
  <?php foreach($rows as $r):
      $name=(string)($r['username']??'');
      $host=(string)($r['host']??'localhost');
      $protected=enterprise_dbuser_is_protected($source,$name,$host);
      $loginEnabled=$source['driver']==='pgsql' ? !empty($r['can_login']) : strtoupper((string)($r['account_locked']??'N'))!=='Y';
  ?>
  <article class="card" style="margin:1rem 0">
    <h3><code><?=e($name)?></code><?php if($source['driver']==='mysql'):?>@<code><?=e($host)?></code><?php endif;?> <?=$protected?'<span class="status-pill">geschützt</span>':''?></h3>
    <p>Status: <strong><?=$loginEnabled?'Anmeldung erlaubt':'gesperrt'?></strong><?php if($source['driver']==='pgsql'):?> · Superuser: <?=!empty($r['is_super'])?'ja':'nein'?> · CREATEDB: <?=!empty($r['can_create_db'])?'ja':'nein'?> · CREATEROLE: <?=!empty($r['can_create_role'])?'ja':'nein'?><?php elseif(!empty($r['plugin'])):?> · Plugin: <code><?=e((string)$r['plugin'])?></code><?php endif;?></p>
    <?php if(!$protected): ?>
      <div class="actions">
        <form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="source" value="<?=e($selected)?>"><input type="hidden" name="action" value="login"><input type="hidden" name="username" value="<?=e($name)?>"><input type="hidden" name="host" value="<?=e($host)?>"><input type="hidden" name="enabled" value="<?=$loginEnabled?'0':'1'?>"><button class="button secondary" type="submit"><?=$loginEnabled?'Benutzer sperren':'Benutzer freischalten'?></button></form>
      </div>
      <details>
        <summary>Kennwort ändern</summary>
        <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="source" value="<?=e($selected)?>"><input type="hidden" name="action" value="password"><input type="hidden" name="username" value="<?=e($name)?>"><input type="hidden" name="host" value="<?=e($host)?>"><label>Neues Kennwort<input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label><div><button class="button" type="submit">Kennwort speichern</button></div></form>
      </details>
      <details>
        <summary>Datenbankzugriff ändern</summary>
        <form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="source" value="<?=e($selected)?>"><input type="hidden" name="action" value="access"><input type="hidden" name="username" value="<?=e($name)?>"><input type="hidden" name="host" value="<?=e($host)?>">
          <label>Datenbank<select name="database" required><?php foreach($databases as $db):?><option value="<?=e($db)?>" <?=$db===($source['database']??'')?'selected':''?>><?=e($db)?></option><?php endforeach;?></select></label>
          <?php if($source['driver']==='pgsql'):?><label>Schema<input name="schema" value="<?=e((string)($source['schema']??'public'))?>" required></label><?php endif;?>
          <label>Zugriffsstufe<select name="access"><?php foreach($accessLabels as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label>
          <div><button class="button" type="submit">Zugriff speichern</button></div>
        </form>
      </details>
      <details>
        <summary>Benutzer löschen</summary>
        <form method="post" onsubmit="return confirm('Datenbank-Benutzer <?=e($name)?> wirklich löschen?')"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="source" value="<?=e($selected)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="username" value="<?=e($name)?>"><input type="hidden" name="host" value="<?=e($host)?>"><button class="button danger" <?=easyit_button_attributes('loeschen','database_user')?>>Datenbank-Benutzer löschen</button></form>
      </details>
    <?php else: ?><p>Das Laufzeitkonto und Systemkonten sind gegen Sperren, Kennwortänderung und Löschen über diese Oberfläche geschützt.</p><?php endif; ?>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'Datenbank-Benutzerverwaltung','active'=>'db-users','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'DB-Benutzer','location'=>'Enterprise → Benutzer & Rechte → DB-Benutzer',
        'short'=>'Technische MySQL- und PostgreSQL-Konten verwalten.',
        'goal'=>'Anwendungs- und Projektkonten mit genau dem erforderlichen Datenbankzugriff bereitstellen.',
        'tips'=>[
            'Enterprise-Anmeldekonten und Datenbankkonten sind voneinander getrennt.',
            'Das aktuell verwendete Datenbankkonto sowie bekannte Systemkonten sind geschützt.',
            'PostgreSQL-Rechte werden immer als Kombination aus Datenbank und Schema vergeben.',
            'Vollzugriff bezieht sich auf die gewählte Datenbank bzw. das gewählte Schema und vergibt keine PostgreSQL-SUPERUSER- oder MySQL-Globalrechte.',
        ],
    ],
]);
