<?php
declare(strict_types=1);
require __DIR__ . '/system/ui/layout.php';

/**
 * Windows-/Unix-sicherer Schreibtest.
 *
 * is_writable() kann insbesondere unter Windows/IIS/Apache nach ACL-
 * Aenderungen oder bei bestimmten Verzeichnisattributen irrefuehrende
 * Ergebnisse liefern. Entscheidend fuer easyIT ist deshalb, ob der aktuell
 * laufende PHP-Prozess im Zielverzeichnis wirklich ein Unterverzeichnis und
 * eine Datei anlegen und danach wieder entfernen kann.
 *
 * @return array{0:bool,1:string}
 */
function easyit_directory_write_probe(string $path): array
{
    clearstatcache(true, $path);

    if (!is_dir($path)) {
        return [false, 'Verzeichnis fehlt: ' . $path];
    }

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $suffix = str_replace('.', '', uniqid('', true));
    }

    $probeDir = $path . DIRECTORY_SEPARATOR . '.easyit-write-probe-' . $suffix;
    $probeFile = $probeDir . DIRECTORY_SEPARATOR . 'probe.tmp';

    // Alte PHP-Fehlermeldung vor dem Test verwerfen.
    error_clear_last();

    if (!@mkdir($probeDir, 0700)) {
        $error = error_get_last();
        $detail = 'PHP konnte kein Testverzeichnis anlegen';
        if (is_array($error) && !empty($error['message'])) {
            $detail .= ': ' . $error['message'];
        }
        return [false, $detail];
    }

    $payload = 'easyIT write probe ' . gmdate('c');
    $written = @file_put_contents($probeFile, $payload, LOCK_EX);
    if ($written === false) {
        $error = error_get_last();
        @rmdir($probeDir);
        $detail = 'Testverzeichnis anlegbar, Testdatei aber nicht schreibbar';
        if (is_array($error) && !empty($error['message'])) {
            $detail .= ': ' . $error['message'];
        }
        return [false, $detail];
    }

    $readBack = @file_get_contents($probeFile);
    if ($readBack !== $payload) {
        @unlink($probeFile);
        @rmdir($probeDir);
        return [false, 'Testdatei wurde geschrieben, konnte aber nicht korrekt gelesen werden'];
    }

    if (!@unlink($probeFile)) {
        $error = error_get_last();
        @rmdir($probeDir);
        $detail = 'Testdatei wurde geschrieben, konnte aber nicht geloescht werden';
        if (is_array($error) && !empty($error['message'])) {
            $detail .= ': ' . $error['message'];
        }
        return [false, $detail];
    }

    if (!@rmdir($probeDir)) {
        $error = error_get_last();
        $detail = 'Schreibtest erfolgreich, Testverzeichnis konnte aber nicht geloescht werden';
        if (is_array($error) && !empty($error['message'])) {
            $detail .= ': ' . $error['message'];
        }
        return [false, $detail];
    }

    clearstatcache(true, $path);
    return [true, 'Schreib-/Löschtest erfolgreich'];
}

/**
 * Stellt sicher, dass ein zur Laufzeit benoetigtes Verzeichnis vorhanden ist.
 * Leere Verzeichnisse gehen beim ZIP-Transport leicht verloren; deshalb wird
 * der erwartete Manager-Runtime-Pfad bei der Systempruefung selbstheilend
 * angelegt, bevor der echte Schreib-/Loeschtest laeuft.
 *
 * @return array{0:bool,1:string}
 */
function easyit_ensure_runtime_directory(string $path): array
{
    clearstatcache(true, $path);
    if (is_dir($path)) {
        return [true, ''];
    }

    error_clear_last();
    if (@mkdir($path, 0775, true) || is_dir($path)) {
        clearstatcache(true, $path);
        return [true, 'Verzeichnis automatisch angelegt'];
    }

    $error = error_get_last();
    $detail = 'Verzeichnis fehlt und konnte nicht automatisch angelegt werden: ' . $path;
    if (is_array($error) && !empty($error['message'])) {
        $detail .= ' (' . $error['message'] . ')';
    }
    return [false, $detail];
}

$checks = [];
$checks[] = ['PHP-Version >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, true];
foreach (['pdo', 'json', 'mbstring', 'openssl', 'filter', 'session'] as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = ['PHP-Erweiterung: ' . $ext, $loaded, $loaded ? 'geladen' : 'fehlt', true];
}
$zipLoaded = extension_loaded('zip');
$checks[] = ['PHP-Erweiterung: zip', $zipLoaded, $zipLoaded ? 'geladen' : 'optional – für ZIP-Pakete erforderlich', false];

foreach (['storage', 'workspace', 'packages'] as $dir) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $dir;
    [$existsOk, $createDetail] = easyit_ensure_runtime_directory($path);
    if (!$existsOk) {
        $checks[] = ['Schreibrecht: ' . $dir, false, $createDetail, true];
        continue;
    }
    [$ok, $probeDetail] = easyit_directory_write_probe($path);
    $detail = $createDetail !== '' ? $createDetail . '; ' . $probeDetail : $probeDetail;
    $checks[] = ['Schreibrecht: ' . $dir, $ok, $detail, true];
}

$criticalOk = true;
foreach ($checks as $check) {
    if ($check[3] && !$check[1]) {
        $criticalOk = false;
        break;
    }
}

ob_start(); ?>
<section class="hero"><span class="badge">Diagnose</span><h1>Systemprüfung</h1><p>Die Schreibrechte werden mit einer kurzlebigen Testdatei und einem Testverzeichnis geprüft. Beides wird sofort wieder entfernt; Datenbanken werden nicht verändert.</p></section>
<p class="notice"><strong>Gesamtergebnis:</strong> <?= $criticalOk ? 'Grundvoraussetzungen erfüllt.' : 'Mindestens eine Grundvoraussetzung fehlt.' ?></p>
<div class="status-list"><?php foreach ($checks as [$label, $ok, $detail]): ?><div class="status"><span><?= e($label) ?><br><small><?= e((string)$detail) ?></small></span><strong class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'PRÜFEN' ?></strong></div><?php endforeach; ?></div>
<nav class="page-actions" aria-label="Seitennavigation"><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">← Zurück zur Startseite</a><a class="button" <?= easyit_button_attributes('weiter') ?> href="setup.php#step-5">Weiter zu Schritt 5 →</a></nav>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Systemprüfung',
    'active' => 'health',
    'content' => $content,
    'help' => [
        'title' => 'Systemprüfung',
        'location' => 'Setup → Systemprüfung',
        'short' => 'Hier sehen Sie, ob die lokale PHP-Umgebung für die nächsten Phasen vorbereitet ist.',
        'goal' => 'PHP-Version, Erweiterungen und tatsächliche Schreibrechte des laufenden PHP-Prozesses sicher prüfen.',
        'next' => 'Nach erfolgreicher Prüfung mit Schritt 5 des Setup-Tutorials fortfahren.',
        'steps' => ['Alle Zeilen prüfen.', 'Fehlende PHP-Erweiterungen in php.ini aktivieren.', 'Bei einem fehlgeschlagenen Schreibtest die dort ausgegebene PHP-Fehlermeldung prüfen.', 'Apache nach Änderungen neu starten.', 'Seite erneut laden.'],
        'examples' => ['extension=zip', 'extension=mbstring', 'extension=pdo_mysql'],
        'tips' => ['ZIP ist für Paketexporte notwendig.', 'Unter Windows wird bewusst ein echter Schreib-/Löschtest verwendet; die Explorer-Rechteanzeige allein ist nicht entscheidend.', 'Die Datenbankverbindung wird in einer späteren Phase separat geprüft.'],
        'duration' => 'ca. 2 Minuten',
    ],
]);
