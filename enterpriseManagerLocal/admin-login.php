<?php
declare(strict_types=1);

require __DIR__.'/system/app/bootstrap.php';
require __DIR__.'/system/ui/layout.php';

if ($current=enterprise_user()) {
    header('Location: '.enterprise_landing_path($current));
    exit;
}

$error='';
$success='';
$pdo=null;
$adminStoreReady=false;
$hasSuperadmin=false;
$setupError='';
$bootstrapUsername=trim((string)($_POST['username']??''));
$bootstrapEmail=trim((string)($_POST['email']??''));
$loginValue=trim((string)($_POST['login']??''));

/**
 * Count active superadministrators. Only an active account can satisfy the
 * login bootstrap guard; if every old superadmin is inactive, a new first
 * usable superadministrator may be created from this page.
 */
function enterprise_login_superadmin_count(PDO $pdo): int
{
    $stmt=$pdo->query("SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE r.name='superadmin' AND u.is_active=1");
    return (int)$stmt->fetchColumn();
}

/** @return array{id:int,username:string,email:string,roles:array<int,string>,permissions:array<int,string>} */
function enterprise_login_load_user(PDO $pdo, int $userId): array
{
    $stmt=$pdo->prepare("SELECT u.id,u.username,u.email,GROUP_CONCAT(r.name) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.id=? GROUP BY u.id LIMIT 1");
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    if (!$row) throw new RuntimeException('Administratorkonto konnte nach der Anmeldung nicht geladen werden.');
    return [
        'id'=>(int)$row['id'],
        'username'=>(string)$row['username'],
        'email'=>(string)($row['email']??''),
        'roles'=>!empty($row['roles'])?array_values(array_filter(explode(',',(string)$row['roles']))):[],
        'permissions'=>enterprise_permissions($pdo,(int)$row['id']),
    ];
}

try {
    $pdo=enterprise_pdo();
    enterprise_upgrade($pdo);
    $hasSuperadmin=enterprise_login_superadmin_count($pdo)>0;
    $adminStoreReady=true;
} catch(Throwable $e) {
    $setupError=$e->getMessage();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['action']??'login');
    try {
        enterprise_check_csrf((string)($_POST['csrf_token']??''));
        if (!$adminStoreReady || !($pdo instanceof PDO)) {
            throw new RuntimeException($setupError!==''?$setupError:'Die Administrationskonfiguration ist noch nicht vollständig. Bitte Setup durchführen.');
        }

        if ($action==='create_first_superadmin') {
            if (enterprise_login_superadmin_count($pdo)>0) {
                throw new RuntimeException('Es ist bereits ein aktiver Superadministrator vorhanden. Bitte melden Sie sich an.');
            }

            $username=trim((string)($_POST['username']??''));
            $email=trim((string)($_POST['email']??''));
            $password=(string)($_POST['password']??'');
            $passwordConfirm=(string)($_POST['password_confirm']??'');
            if ($password!==$passwordConfirm) {
                throw new RuntimeException('Die Kennwörter stimmen nicht überein.');
            }

            // Der Bootstrap darf niemals einen bereits vorhandenen Benutzer
            // stillschweigend zum Superadministrator hochstufen.
            $duplicate=$pdo->prepare('SELECT id FROM users WHERE username=? OR (? <> \'\' AND email=?) LIMIT 1');
            $duplicate->execute([$username,$email,$email]);
            if ($duplicate->fetchColumn()!==false) {
                throw new RuntimeException('Benutzername oder E-Mail-Adresse ist bereits vergeben.');
            }

            $installer=new \DataForm5\Installer\Core\AdminDatabaseInstaller();
            $installer->createAdmin($pdo,$username,$email,$password);
            enterprise_upgrade($pdo);
            $hasSuperadmin=enterprise_login_superadmin_count($pdo)>0;
            if (!$hasSuperadmin) {
                throw new RuntimeException('Das Superadministratorkonto wurde angelegt, konnte aber nicht als aktiver Superadministrator bestätigt werden.');
            }
            $success='Der erste Superadministrator wurde angelegt. Sie können sich jetzt anmelden.';
            $loginValue=$username;
            $bootstrapUsername='';
            $bootstrapEmail='';
        } elseif ($action==='login') {
            if (!$hasSuperadmin) {
                throw new RuntimeException('Es ist noch kein Superadministrator vorhanden. Legen Sie zuerst das Superadministratorkonto an.');
            }
            $stmt=$pdo->prepare("SELECT u.id,u.username,u.email,u.password_hash,u.is_active,GROUP_CONCAT(r.name) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.username=? OR u.email=? GROUP BY u.id LIMIT 1");
            $login=trim((string)($_POST['login']??''));
            $stmt->execute([$login,$login]);
            $row=$stmt->fetch();
            if (!$row || !(bool)$row['is_active'] || !password_verify((string)($_POST['password']??''),(string)$row['password_hash'])) {
                throw new RuntimeException('Benutzername/E-Mail oder Kennwort ist falsch.');
            }
            session_regenerate_id(true);
            $_SESSION['enterprise_user']=enterprise_login_load_user($pdo,(int)$row['id']);
            enterprise_audit($pdo,(int)$row['id'],'auth.login','user',(string)$row['id']);
            enterprise_event_dispatch('auth.user.logged_in',['user_id'=>(int)$row['id'],'username'=>(string)$row['username']],['source'=>'login']);
            $next=trim((string)($_GET['next']??''));
            // Only local application-relative targets are accepted. This allows
            // the public login page to lead an administrator directly to the
            // license-server setup without introducing an open redirect.
            $safeNext=$next!==''
                && !str_contains($next, '://')
                && !str_starts_with($next, '//')
                && !str_contains($next, "\\")
                && !preg_match('~(^|/)\.\.(/|$)~', $next);
            header('Location: '.($safeNext?$next:enterprise_landing_path($_SESSION['enterprise_user'])));
            exit;
        } else {
            throw new RuntimeException('Unbekannte Anmeldeaktion.');
        }
    } catch(Throwable $e) {
        $error=$e->getMessage();
        if ($setupError==='' && str_contains($error,'Konfiguration ADMIN_DB_')) {
            $setupError=$error;
        }
    }
}

ob_start();
?>
<section class="hero">
    <span class="badge">Enterprise-Anmeldung · Startseite</span>
    <h1><?= $adminStoreReady ? ($hasSuperadmin ? 'Bei easyIT Enterprise anmelden' : 'Ersten Superadministrator anlegen') : 'Administration einrichten' ?></h1>
    <?php if ($hasSuperadmin): ?>
        <p>Die Administration beginnt auf dieser Seite. Melden Sie sich mit Ihrem Superadministratorkonto an.</p>
    <?php elseif ($adminStoreReady): ?>
        <p>Es ist noch kein aktiver Superadministrator vorhanden. Legen Sie hier das erste Administratorkonto an; danach steht die Anmeldung auf derselben Startseite bereit.</p>
    <?php else: ?>
        <p>Die Administrationskonfiguration ist noch nicht vollständig. Öffnen Sie zuerst das Setup und kehren Sie anschließend zu dieser Startseite zurück.</p>
    <?php endif; ?>
</section>

<?php if($setupError): ?>
<div class="notice error" role="alert">
    <strong>Setup erforderlich:</strong> <?=e($setupError)?>
</div>
<?php endif; ?>

<?php if($error): ?><div class="notice error" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if($success): ?><div class="notice success" role="status"><?=e($success)?></div><?php endif; ?>

<?php if($adminStoreReady && !$hasSuperadmin): ?>
<section class="card login-card">
    <h2>Erstes Superadministratorkonto</h2>
    <form method="post" class="form-grid single-column" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?=e(enterprise_csrf())?>">
        <input type="hidden" name="action" value="create_first_superadmin">
        <label>Benutzername
            <input name="username" value="<?=e($bootstrapUsername)?>" minlength="3" maxlength="120" pattern="[A-Za-z0-9._-]{3,120}" required autofocus autocomplete="username">
        </label>
        <label>E-Mail-Adresse
            <input type="email" name="email" value="<?=e($bootstrapEmail)?>" maxlength="190" autocomplete="email">
        </label>
        <label>Kennwort
            <input type="password" name="password" minlength="10" required autocomplete="new-password">
            <small>Mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie mindestens eine Ziffer.</small>
        </label>
        <label>Kennwort wiederholen
            <input type="password" name="password_confirm" minlength="10" required autocomplete="new-password">
        </label>
        <div class="actions">
            <button class="button" <?= easyit_button_attributes('bestaetigen', 'setup_admin_create') ?> type="submit">Ersten Superadministrator anlegen</button>
            <a class="button secondary" <?= easyit_button_attributes('restore') ?> href="recovery.php">Recovery / Reset</a>
        </div>
    </form>
</section>
<?php elseif($adminStoreReady && $hasSuperadmin): ?>
<section class="card login-card">
    <form method="post" class="form-grid single-column">
        <input type="hidden" name="csrf_token" value="<?=e(enterprise_csrf())?>">
        <input type="hidden" name="action" value="login">
        <label>Benutzername oder E-Mail
            <input name="login" value="<?=e($loginValue)?>" required autofocus autocomplete="username">
        </label>
        <label>Kennwort
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <div class="actions">
            <button class="button" <?= easyit_button_attributes('anmelden') ?> type="submit">Anmelden</button>
            <a class="button secondary" <?= easyit_button_attributes('restore') ?> href="recovery.php">Recovery / Reset</a>
        </div>
    </form>
</section>
<?php else: ?>
<section class="card login-card">
    <h2>Installation vervollständigen</h2>
    <p>Eine Anmeldung oder Administratoranlage ist erst möglich, sobald der Administrationsspeicher konfiguriert und erreichbar ist.</p>
    <div class="actions">
        <a class="button" <?= easyit_button_attributes('einstellungen', 'setup') ?> href="setup.php">Setup durchführen</a>
        <a class="button secondary" <?= easyit_button_attributes('suchen') ?> href="health.php">System prüfen</a>
        <a class="button secondary" <?= easyit_button_attributes('restore') ?> href="recovery.php">Recovery / Reset</a>
    </div>
</section>
<?php endif; ?>

<?php
$content=ob_get_clean();
render_page([
    'title'=>'Administrator-Anmeldung',
    'active'=>'home',
    'content'=>$content,
    'help'=>[
        'title'=>'Administrator-Anmeldung',
        'location'=>'Enterprise → Startseite',
        'short'=>$hasSuperadmin?'Die Enterprise-Verwaltung beginnt direkt mit der Administrator-Anmeldung.':($adminStoreReady?'Noch kein aktiver Superadministrator vorhanden. Das erste Konto kann direkt hier angelegt werden.':'Die Administrationskonfiguration muss zuerst im Setup vervollständigt werden.'),
        'goal'=>$hasSuperadmin?'Geschützten Zugang zur Projektverwaltung erhalten.':($adminStoreReady?'Das erste Superadministratorkonto erzeugen und anschließend anmelden.':'Setup öffnen und den Administrationsspeicher vollständig konfigurieren.'),
        'next'=>$hasSuperadmin?'Benutzername oder E-Mail und Kennwort eingeben.':($adminStoreReady?'Benutzername und starkes Kennwort für den ersten Superadministrator festlegen.':'Setup durchführen.'),
        'tips'=>[
            'Das Datenbankkennwort ist nicht das Administratorkennwort.',
            'Ist noch kein Superadministrator vorhanden, wird auf dieser Startseite automatisch die Kontoanlage angeboten.',
            'Nur bei fehlender oder fehlerhafter Administrationskonfiguration führt ein sichtbarer Button „Setup durchführen“ direkt zum Setup.',
        ],
    ],
]);
