<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/system/ui/layout.php';

$rootPath = dirname(__DIR__);
$examplePath = $rootPath . '/DataForm5-Core/.env.example';
$envPath = $rootPath . '/DataForm5-Core/.env';
$message = null;
$messageType = 'notice';

if (!isset($_SESSION['easyit_csrf'])) {
    $_SESSION['easyit_csrf'] = bin2hex(random_bytes(32));
}

function installerDirectoryWritable(string $dir): bool
{
    if (!is_dir($dir)) return false;

    $probe = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '.easyit-write-test-' . bin2hex(random_bytes(6)) . '.tmp';
    $written = @file_put_contents($probe, 'easyit');
    if ($written === false) return false;

    @unlink($probe);
    return true;
}

function detectAppUrl(): string
{
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('~/installer/local-config\.php$~', '', $scriptName) ?: '';
    return rtrim($scheme . '://' . $host . $basePath, '/');
}

function prepareEnvironmentTemplate(string $template, string $appUrl): string
{
    $template = preg_replace('/^APP_NAME=.*$/m', 'APP_NAME="easyIT Enterprise"', $template) ?? $template;
    $template = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $appUrl, $template) ?? $template;
    $template = preg_replace('/^ADMIN_DB_DATABASE=.*$/m', 'ADMIN_DB_DATABASE=', $template) ?? $template;
    $template = preg_replace('/^PROJECT_DB_DATABASE=.*$/m', 'PROJECT_DB_DATABASE=', $template) ?? $template;
    $template = preg_replace('/^ADMIN_DB_PASSWORD=.*$/m', 'ADMIN_DB_PASSWORD=', $template) ?? $template;
    $template = preg_replace('/^PROJECT_DB_PASSWORD=.*$/m', 'PROJECT_DB_PASSWORD=', $template) ?? $template;
    return $template;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['easyit_csrf'], $token)) {
        $message = 'Die Sicherheitsprüfung ist fehlgeschlagen. Laden Sie die Seite neu und versuchen Sie es erneut.';
        $messageType = 'error';
    } elseif (is_file($envPath)) {
        $message = 'Die lokale Konfiguration ist bereits vorhanden. Sie wurde aus Sicherheitsgründen nicht überschrieben.';
        $messageType = 'notice';
    } elseif (!is_file($examplePath) || !is_readable($examplePath)) {
        $message = 'Die Vorlage DataForm5-Core/.env.example fehlt oder ist nicht lesbar.';
        $messageType = 'error';
    } elseif (!installerDirectoryWritable(dirname($envPath))) {
        $message = 'Der PHP/Apache-Prozess kann DataForm5-Core tatsächlich nicht beschreiben. Prüfen Sie die Windows-ACL des Verzeichnisses.';
        $messageType = 'error';
    } else {
        $template = file_get_contents($examplePath);
        if ($template === false) {
            $message = 'Die Konfigurationsvorlage konnte nicht gelesen werden.';
            $messageType = 'error';
        } else {
            $contents = prepareEnvironmentTemplate($template, detectAppUrl());
            $written = @file_put_contents($envPath, $contents, LOCK_EX);
            if ($written === false) {
                $last = error_get_last();
                $detail = is_array($last) && isset($last['message']) ? ' (' . $last['message'] . ')' : '';
                $message = 'Die Datei DataForm5-Core/.env konnte nicht erzeugt werden.' . $detail;
                $messageType = 'error';
            } else {
                @chmod($envPath, 0600);
                $message = 'Die lokale Datei DataForm5-Core/.env wurde erfolgreich erzeugt.';
                $messageType = 'success';
            }
        }
    }
}

$envExists = is_file($envPath);
$templateExists = is_file($examplePath);
$directoryWritable = installerDirectoryWritable(dirname($envPath));

ob_start();
?>
<section class="hero">
  <span class="badge">Setup · Schritt 5</span>
  <h1>Lokale Konfiguration</h1>
  <p>Der Assistent erzeugt die lokale <code>.env</code>-Datei aus der mitgelieferten Vorlage. Sie müssen keine Datei manuell kopieren.</p>
</section>

<?php if ($message !== null): ?>
<div class="notice <?= e($messageType) ?>" role="status"><?= e($message) ?></div>
<?php endif; ?>

<section class="card">
  <h2>Status</h2>
  <dl class="status-list">
    <div><dt>Vorlage</dt><dd><?= $templateExists ? '✔ .env.example vorhanden' : '✘ .env.example fehlt' ?></dd></div>
    <div><dt>Zielverzeichnis</dt><dd><?= $directoryWritable ? '✔ beschreibbar' : '✘ nicht beschreibbar' ?></dd></div>
    <div><dt>Lokale Konfiguration</dt><dd><?= $envExists ? '✔ .env vorhanden' : '○ .env noch nicht vorhanden' ?></dd></div>
  </dl>
</section>

<section class="card">
  <h2>.env erzeugen</h2>
  <p>Beim Erzeugen werden die aktuelle lokale URL und sichere Entwicklungsstandardwerte eingetragen. Datenbanknamen und Passwörter bleiben zunächst leer und werden in einem späteren Installationsschritt erfasst.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['easyit_csrf']) ?>">
    <button class="button" type="submit" <?= ($envExists || !$templateExists || !$directoryWritable) ? 'disabled' : '' ?>><?= $envExists ? '.env bereits vorhanden' : '.env jetzt erzeugen' ?></button>
  </form>
  <div class="notice"><strong>Git-Schutz:</strong> <code>DataForm5-Core/.env</code> ist in der mitgelieferten <code>.gitignore</code> ausgeschlossen. Echte Passwörter dürfen trotzdem niemals committed werden.</div>
</section>

<section class="card">
  <h2>Vorgesehene Trennung</h2>
  <pre>ADMIN_DB_*    # Benutzer, Projekte und Administration
PROJECT_DB_*  # fachliche Daten des ausgewählten Projekts</pre>
</section>

<nav class="page-actions" aria-label="Seitennavigation">
  <a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="../setup.php#step-5">← Zurück zum Tutorial</a>
  <a class="button" <?= easyit_button_attributes('weiter') ?> href="../setup.php#step-6">Weiter zu Schritt 6 →</a>
</nav>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Lokale Konfiguration',
    'active' => 'setup',
    'base' => '../',
    'content' => $content,
    'help' => [
        'title' => 'Lokale Konfiguration',
        'location' => 'Setup → Schritt 5 → .env',
        'short' => 'Hier erzeugt der Installer die lokale Konfigurationsdatei automatisch.',
        'goal' => 'Eine nicht versionierte lokale Konfiguration für diese Installation anlegen.',
        'next' => $envExists ? 'Kehren Sie zum Tutorial zurück und bereiten Sie die beiden Datenbanken vor.' : 'Prüfen Sie den Status und klicken Sie auf „.env jetzt erzeugen“.',
        'steps' => ['Vorlage und Schreibrecht prüfen.', 'Schaltfläche zum Erzeugen verwenden.', 'Erfolgsmeldung kontrollieren.', 'Mit Schritt 6 fortfahren.'],
        'examples' => ['DataForm5-Core/.env.example', 'DataForm5-Core/.env'],
        'tips' => ['Eine bestehende .env wird niemals automatisch überschrieben.', 'Passwörter werden erst später erfasst.', 'Bei einem Schreibfehler die Rechte des Ordners DataForm5-Core prüfen.'],
        'duration' => 'ca. 1 Minute',
    ],
]);
