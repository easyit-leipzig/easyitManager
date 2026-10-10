<?php
declare(strict_types=1);
require __DIR__.'/system/app/bootstrap.php';

if ($current=enterprise_user()) {
    header('Location: '.enterprise_landing_path($current));
    exit;
}

// Die Enterprise-Startseite ist verbindlich die Administrator-Anmeldung.
// login.php übernimmt dort zusätzlich den First-Superadmin-Bootstrap und
// verweist bei unvollständiger Konfiguration direkt auf Setup / Installer.
header('Location: login.php');
exit;
