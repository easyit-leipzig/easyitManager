<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/system/ui/layout.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseCsvPdo.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseSqlitePdo.php';
require_once dirname(__DIR__) . '/system/app/EnterprisePgsqlPdo.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseOraclePdo.php';
require_once __DIR__ . '/admin-schema-gate.php';

$rootPath = dirname(__DIR__);
$envPath = $rootPath . '/DataForm5-Core/.env';
$messages = [];
$results = [];
$status = [];
$adminTotal = 0;
$superAdminTotal = 0;
$adminStatusReady = false;

if (!isset($_SESSION['easyit_csrf'])) {
    $_SESSION['easyit_csrf'] = bin2hex(random_bytes(32));
}

function admin_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_env(string $path): array
{
    $values = [];
    if (!is_file($path) || !is_readable($path)) {
        return $values;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

function admin_pdo(array $env): PDO
{
    $driver = strtolower(trim((string)($env['ADMIN_DB_DRIVER'] ?? 'mysql')));
    if ($driver === 'csv') {
        $database = trim((string)($env['ADMIN_DB_DATABASE'] ?? ''));
        if ($database === '') throw new RuntimeException('Die Einstellung ADMIN_DB_DATABASE fehlt. Führen Sie zuerst Schritt 6 vollständig aus.');
        $base = trim((string)($env['ADMIN_DB_CSV_BASE_PATH'] ?? 'storage/admin-csv'));
        $root = dirname(__DIR__);
        $normalized = str_replace('\\','/',$base);
        if (!preg_match('~^(?:[A-Za-z]:/|/)~',$normalized)) $base = $root . '/' . ltrim($normalized,'/');
        return new EnterpriseCsvPdo($base,$database);
    }
    if ($driver === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite ist nicht aktiv.');
        $database=trim((string)($env['ADMIN_DB_DATABASE']??''));
        if ($database==='' || preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$database)!==1) throw new RuntimeException('Die Einstellung ADMIN_DB_DATABASE fehlt oder ist für SQLite ungültig.');
        $base=trim((string)($env['ADMIN_DB_SQLITE_BASE_PATH']??'storage/admin-sqlite')) ?: 'storage/admin-sqlite';
        $root=dirname(__DIR__); $normalized=str_replace('\\','/',$base);
        if(!preg_match('~^(?:[A-Za-z]:/|/)~',$normalized))$normalized=$root.'/'.ltrim($normalized,'/');
        return new EnterpriseSqlitePdo(rtrim($normalized,'/').'/'.$database.'.sqlite');
    }
    if ($driver==='oracle') { return new EnterpriseOraclePdo((string)$env['ADMIN_DB_HOST'],(int)($env['ADMIN_DB_PORT']??1521),(string)($env['ADMIN_DB_ORACLE_SERVICE']??'XEPDB1'),(string)$env['ADMIN_DB_USERNAME'],(string)($env['ADMIN_DB_PASSWORD']??'')); }
    if (in_array($driver,['pgsql','postgres','postgresql'],true)) {
        foreach (['ADMIN_DB_HOST','ADMIN_DB_PORT','ADMIN_DB_DATABASE','ADMIN_DB_USERNAME'] as $key) {
            if (!isset($env[$key]) || trim((string)$env[$key])==='') throw new RuntimeException("Die Einstellung {$key} fehlt. Führen Sie zuerst Schritt 6 vollständig aus.");
        }
        return new EnterprisePgsqlPdo((string)$env['ADMIN_DB_HOST'],(int)$env['ADMIN_DB_PORT'],(string)$env['ADMIN_DB_DATABASE'],(string)$env['ADMIN_DB_USERNAME'],(string)($env['ADMIN_DB_PASSWORD']??''),(string)($env['ADMIN_DB_SCHEMA']??'public'));
    }
    if ($driver !== 'mysql' && $driver !== 'mariadb') throw new RuntimeException('Nicht unterstützter Administrationsspeicher: '.$driver);
    $required = ['ADMIN_DB_HOST', 'ADMIN_DB_PORT', 'ADMIN_DB_DATABASE', 'ADMIN_DB_USERNAME'];
    foreach ($required as $key) {
        if (!isset($env[$key]) || trim((string)$env[$key]) === '') {
            throw new RuntimeException("Die Einstellung {$key} fehlt. Führen Sie zuerst Schritt 6 vollständig aus.");
        }
    }
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('Die PHP-Erweiterung pdo_mysql ist nicht aktiv.');
    return new PDO(
        'mysql:host='.(string)$env['ADMIN_DB_HOST'].';port='.(int)$env['ADMIN_DB_PORT'].';dbname='.(string)$env['ADMIN_DB_DATABASE'].';charset=utf8mb4',
        (string)$env['ADMIN_DB_USERNAME'], (string)($env['ADMIN_DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]
    );
}

function adminRoleCount(PDO $pdo, string $roleName): int
{
    if (!in_array($roleName, ['admin','superadmin'], true)) {
        throw new InvalidArgumentException('Ungültige administrative Systemrolle.');
    }
    $sql = "SELECT COUNT(DISTINCT u.id)
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE r.name = '" . $roleName . "'";
    return (int)$pdo->query($sql)->fetchColumn();
}

$form = [
    'username' => '',
    'email' => '',
];

try {
    $env = admin_env($envPath);
    $adminDriver=strtolower((string)($env['ADMIN_DB_DRIVER'] ?? 'mysql'));
    if ($adminDriver==='mysql' && !extension_loaded('pdo_mysql')) throw new RuntimeException('Die PHP-Erweiterung pdo_mysql ist nicht aktiv.');
    if ($adminDriver==='sqlite' && !extension_loaded('pdo_sqlite')) throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite ist nicht aktiv.');
    if (in_array($adminDriver,['pgsql','postgres','postgresql'],true) && !extension_loaded('pdo_pgsql')) throw new RuntimeException('Die PHP-Erweiterung pdo_pgsql ist nicht aktiv.');
    if (in_array($adminDriver,['oracle','oci','oci8'],true) && !extension_loaded('pdo_oci')) throw new RuntimeException('Die PHP-Erweiterung pdo_oci ist nicht aktiv.');
    $pdo = admin_pdo($env);
    $schemaGate = easyit_admin_schema_assert_or_repair($pdo, __DIR__ . '/schema/admin');
    if ($schemaGate['repaired']) {
        $results[] = 'Administrationsschema automatisch repariert: ' . implode(', ', $schemaGate['applied']);
    }
    $adminTotal = adminRoleCount($pdo, 'admin');
    $superAdminTotal = adminRoleCount($pdo, 'superadmin');
    $adminStatusReady = true;
    if ($superAdminTotal > 0) {
        $_SESSION['easyit_admin_setup_complete'] = true;
    }
    $status = [
        'Administrationsspeicher' => (match(strtolower((string)($env['ADMIN_DB_DRIVER'] ?? 'mysql'))){'csv'=>'CSV · ','sqlite'=>'SQLite · ','pgsql','postgres','postgresql'=>'PostgreSQL · ','oracle'=>'Oracle XE · ',default=>'MariaDB/MySQL · '}) . (string)$env['ADMIN_DB_DATABASE'],
        'Schema-Gate' => 'bestanden',
        'Benutzertabelle' => 'vorhanden',
        'Rollentabelle' => 'vorhanden',
        'Vorhandene Superadministratoren' => (string)$superAdminTotal,
        'Vorhandene Administratoren' => (string)$adminTotal,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['email'] = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $token = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals((string)$_SESSION['easyit_csrf'], $token)) {
            throw new RuntimeException('Die Sicherheitsprüfung ist fehlgeschlagen. Laden Sie die Seite neu.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,120}$/', $form['username'])) {
            throw new RuntimeException('Der Benutzername muss 3 bis 120 Zeichen lang sein und darf Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
        }
        if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Die E-Mail-Adresse ist ungültig.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Das Kennwort muss mindestens 10 Zeichen lang sein.');
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new RuntimeException('Das Kennwort muss mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Ziffer enthalten.');
        }
        if ($password !== $passwordConfirm) {
            throw new RuntimeException('Die Kennwörter stimmen nicht überein.');
        }

        $pdo->beginTransaction();
        try {
            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            if ($roleId === false) {
                $pdo->prepare("INSERT INTO roles (name, label) VALUES ('admin', 'Administrator')")->execute();
                $roleId = $pdo->lastInsertId();
            }
            $superRoleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'superadmin' LIMIT 1");
            $superRoleStmt->execute();
            $superRoleId = $superRoleStmt->fetchColumn();
            if ($superRoleId === false) {
                $pdo->prepare("INSERT INTO roles (name, label) VALUES ('superadmin', 'Superadministrator')")->execute();
                $superRoleId = $pdo->lastInsertId();
            }
            $creatingFirstSuperadmin = $superAdminTotal === 0;

            $duplicate = $pdo->prepare('SELECT id FROM users WHERE username = ? OR (? <> \'\' AND email = ?) LIMIT 1');
            $duplicate->execute([$form['username'], $form['email'], $form['email']]);
            if ($duplicate->fetchColumn() !== false) {
                throw new RuntimeException('Benutzername oder E-Mail-Adresse ist bereits vergeben.');
            }

            $insertUser = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_active) VALUES (?, NULLIF(?, \'\'), ?, 1)');
            $insertUser->execute([
                $form['username'],
                $form['email'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int)$pdo->lastInsertId();

            $assignRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $assignRole->execute([$userId, (int)$roleId]);
            if ($creatingFirstSuperadmin) {
                $assignRole->execute([$userId, (int)$superRoleId]);
            }
            $pdo->commit();

            $_SESSION['easyit_admin_setup_complete'] = true;
            $results[] = $creatingFirstSuperadmin ? '✔ Superadministratorkonto wurde erstellt' : '✔ Administratorkonto wurde erstellt';
            $results[] = '✔ Rolle „Administrator“ wurde zugewiesen';
            if ($creatingFirstSuperadmin) {
                $results[] = '✔ Geschützte Rolle „Superadministrator“ wurde zugewiesen';
            }
            $results[] = '✔ Kennwort wurde ausschließlich als sicherer Hash gespeichert';
            $adminTotal = adminRoleCount($pdo, 'admin');
            $superAdminTotal = adminRoleCount($pdo, 'superadmin');
            $status['Vorhandene Superadministratoren'] = (string)$superAdminTotal;
            $status['Vorhandene Administratoren'] = (string)$adminTotal;
            $form = ['username' => '', 'email' => ''];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    $messages[] = $e->getMessage();
    if ($e instanceof PDOException && isset($e->errorInfo[1])) {
        $messages[] = 'MariaDB-Fehlernummer: ' . (string)$e->errorInfo[1];
    }
}

$hasAdministrator = $adminStatusReady && $superAdminTotal > 0;

ob_start();
?>
<section class="hero">
    <span class="badge">Setup · Schritt 7</span>
    <h1><?= $hasAdministrator ? 'Superadministrator bereits vorhanden' : 'Ersten Superadministrator anlegen' ?></h1>
    <?php if ($hasAdministrator): ?>
        <p>Mindestens ein lokales Superadministratorkonto ist bereits vorhanden. Schritt 7 ist damit erfüllt. Sie können direkt zu Schritt 8 weitergehen oder optional einen weiteren Administrator anlegen.</p>
    <?php else: ?>
        <p>Erstellen Sie jetzt das erste lokale Superadministratorkonto. Das Kennwort wird nicht in der <code>.env</code>, sondern ausschließlich als sicherer Hash im Administrationsspeicher gespeichert.</p>
    <?php endif; ?>
</section>

<?php foreach ($messages as $message): ?>
    <div class="notice error" role="alert"><?= admin_e($message) ?></div>
<?php endforeach; ?>

<?php if ($results): ?>
<section class="card">
    <h2>Ergebnis</h2>
    <ul class="result-list">
        <?php foreach ($results as $result): ?><li><?= admin_e($result) ?></li><?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($status): ?>
<section class="card">
    <h2>Status der Administrationsdatenbank</h2>
    <dl class="status-list">
        <?php foreach ($status as $label => $value): ?>
            <div><dt><?= admin_e($label) ?></dt><dd><code><?= admin_e($value) ?></code></dd></div>
        <?php endforeach; ?>
    </dl>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= $hasAdministrator ? 'Weiteren Administrator anlegen (optional)' : 'Superadministratorkonto' ?></h2>
    <form method="post" class="form-grid" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= admin_e((string)$_SESSION['easyit_csrf']) ?>">
        <label>Benutzername
            <input name="username" value="<?= admin_e($form['username']) ?>" minlength="3" maxlength="120" pattern="[A-Za-z0-9._-]{3,120}" required autocomplete="username">
            <small>Beispiel: admin oder olaf.admin</small>
        </label>
        <label>E-Mail-Adresse
            <input type="email" name="email" value="<?= admin_e($form['email']) ?>" maxlength="190" autocomplete="email">
            <small>Optional, aber für spätere Kennwortfunktionen empfohlen.</small>
        </label>
        <label>Kennwort
            <input type="password" name="password" minlength="10" required autocomplete="new-password" data-password-field>
            <button type="button" class="password-toggle" <?= easyit_button_attributes('anzeigen', 'password_show') ?> data-password-toggle></button>
            <small>Mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie mindestens eine Ziffer.</small>
        </label>
        <label>Kennwort wiederholen
            <input type="password" name="password_confirm" minlength="10" required autocomplete="new-password" data-password-field>
            <button type="button" class="password-toggle" <?= easyit_button_attributes('anzeigen', 'password_show') ?> data-password-toggle></button>
        </label>
        <div class="form-span button-row">
            <button class="button" <?= easyit_button_attributes('bestaetigen', 'setup_admin_create') ?> type="submit">Administrator sicher anlegen</button>
        </div>
    </form>
    <div class="notice"><strong>Sicherheit:</strong> Verwenden Sie nicht Ihr GitHub-, Windows- oder Datenbankkennwort. Das Kennwort wird niemals angezeigt, protokolliert oder in Git gespeichert.</div>
</section>

<script>
document.querySelectorAll('[data-password-toggle]').forEach(function(button){
 var input=button.parentElement.querySelector('input[data-password-field]');
 if(!input) return;
 button.addEventListener('click',function(){
  var show=input.type==='password';
  input.type=show?'text':'password';
  button.setAttribute('data-button-context',show?'password_hide':'password_show');
  if(window.EasyITButtons && typeof window.EasyITButtons.decorate==='function') window.EasyITButtons.decorate(button);
 });
});
</script>
<style>.password-toggle{margin-left:.35rem;border:1px solid #ccd5e2;border-radius:.4rem;padding:.35rem .55rem;cursor:pointer}</style>
<nav class="page-actions">
    <a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="database.php">← Zurück zu Schritt 6</a>
    <a class="button" <?= easyit_button_attributes('weiter') ?> href="../setup.php#step-8">Weiter zu Schritt 8 →</a>
</nav>
<?php
$content = ob_get_clean();
render_page([
    'title' => $hasAdministrator ? 'Administrator bereits vorhanden' : 'Administrator anlegen',
    'active' => 'setup',
    'base' => '../',
    'content' => $content,
    'help' => [
        'title' => 'Administrator-Assistent',
        'location' => 'Setup → Schritt 7 → Administrator',
        'short' => $hasAdministrator ? 'Mindestens ein Administratorkonto ist vorhanden; Schritt 7 ist bereits erfüllt.' : 'Hier erzeugen Sie das erste Konto für die Enterprise-Administration.',
        'goal' => $hasAdministrator ? 'Den vorhandenen Administratorstatus bestätigen und bei Bedarf einen weiteren Administrator sicher anlegen.' : 'Einen aktiven Benutzer mit der Rolle Administrator sicher in der Administrationsdatenbank speichern.',
        'next' => $hasAdministrator ? 'Direkt mit Schritt 8 fortfahren oder optional einen weiteren Administrator anlegen.' : 'Benutzername, optionale E-Mail und ein starkes Kennwort eingeben und das Konto anlegen.',
        'steps' => [
            'Prüfen, ob Schritt 6 vollständig abgeschlossen ist.',
            'Eindeutigen Benutzernamen eingeben.',
            'Ein starkes Kennwort zweimal eingeben.',
            'Administrator anlegen.',
            'Die Erfolgsmeldungen und die Administratoranzahl kontrollieren.',
        ],
        'examples' => ['Benutzername: admin', 'E-Mail: admin@example.local', 'Kennwort: mindestens 10 Zeichen'],
        'tips' => [
            'Benutzername und E-Mail dürfen noch nicht vergeben sein.',
            'Das Kennwort wird nur als Hash gespeichert.',
            'Die Datenbanktabellen users, roles und user_roles müssen vorhanden sein.',
            'Legen Sie zunächst nur ein persönliches Administratorkonto an.',
        ],
        'duration' => 'ca. 2 Minuten',
    ],
]);
