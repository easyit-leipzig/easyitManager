<?php
declare(strict_types=1);

/**
 * Shared-hosting front controller.
 *
 * Der geschützte Core bleibt außerhalb des direkten HTTP-Zugriffs. Auf Servern,
 * auf denen ein eigener DocumentRoot möglich ist, kann weiterhin /public als
 * Webroot verwendet werden. Für Unterordner-Installationen ist ab HF79 dieser
 * Root-Front-Controller der vorgesehene Einstieg.
 */
function easyitLocalRequestPath(): string
{
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '.' || $base === '/') {
        $base = '';
    }
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base));
    }
    if ($path === '' || $path === '/index.php') {
        return '/';
    }
    return '/' . ltrim($path, '/');
}

$localPath = easyitLocalRequestPath();

// Die Wurzelseite ist bewusst nur eine informationsarme Betriebsseite und
// benötigt weder DB-Zugriff noch Schlüsselmaterial.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $localPath === '/') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    $version = is_file(__DIR__ . '/VERSION') ? trim((string) file_get_contents(__DIR__ . '/VERSION')) : 'HF79';
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    if ($base === '.' || $base === '/') $base = '';
    $health = htmlspecialchars($base . '/api/v1/health', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $v = htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>easyIT License Server</title><style>body{font-family:Arial,sans-serif;max-width:820px;margin:48px auto;padding:0 22px;color:#222}code{background:#f2f2f2;padding:.15rem .35rem;border-radius:4px}.ok{font-weight:700}</style></head><body>';
    echo '<h1>easyIT License/DataForm Server</h1><p class="ok">Web-Einstieg erreichbar.</p>';
    echo '<p>Stand: <code>' . $v . '</code></p>';
    echo '<p>API-Health: <code>' . $health . '</code></p>';
    echo '<p><a href="' . htmlspecialchars(($base ?: '') . '/admin/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Zentrale Lizenzverwaltung öffnen</a> · <a href="' . htmlspecialchars(($base ?: '') . '/setup/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Datenquelle / Einrichtung</a></p>';
    echo '<p>Die geschützten Serverbereiche sind nicht direkt per HTTP zugänglich.</p>';
    echo '</body></html>';
    exit;
}

$configFile = __DIR__ . '/config/app.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'SERVER_NOT_CONFIGURED',
            'message' => 'Der Lizenzserver ist noch nicht konfiguriert.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = require __DIR__ . '/bootstrap.php';
if(!easyit_license_database_configured($config)){http_response_code(503);header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');echo json_encode(['success'=>false,'error'=>['code'=>'SERVER_NOT_CONFIGURED','message'=>'Der Lizenzserver ist noch nicht eingerichtet. Öffnen Sie /setup/.']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
\EasyIT\LicenseServer\App\Application::run($config);
