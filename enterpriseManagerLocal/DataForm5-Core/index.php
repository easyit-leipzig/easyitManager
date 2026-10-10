<?php
declare(strict_types=1);

http_response_code(403);
header('Content-Type: text/html; charset=UTF-8');
$root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$enterpriseRoot = preg_replace('~/DataForm5-Core$~', '', $root) ?: '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Direkter Core-Zugriff gesperrt</title>
    <style>body{font-family:system-ui,sans-serif;max-width:760px;margin:4rem auto;padding:0 1.5rem;line-height:1.6;color:#13233b}a{display:inline-block;padding:.75rem 1rem;background:#17457d;color:#fff;text-decoration:none;border-radius:.5rem}</style>
</head>
<body>
<h1>Direkter Zugriff auf den Core ist nicht vorgesehen</h1>
<p><code>DataForm5-Core</code> enthält interne Framework- und Systemdateien. Öffnen Sie Projekte ausschließlich über das easyIT-Enterprise-Dashboard.</p>
<p><a href="<?= htmlspecialchars($enterpriseRoot . '/', ENT_QUOTES, 'UTF-8') ?>">Zum Enterprise-Dashboard</a></p>
</body>
</html>
