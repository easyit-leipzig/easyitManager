<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/app/project_distribution.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.backup');
enterprise_require_customer_module($user,'export');
enterprise_require_license_capability($user,'export.execute');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Nur POST ist erlaubt.'); }
try {
    enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) throw new RuntimeException('Projekt fehlt.');
    enterprise_require_project_scope($user,$id,true);
    $pdo = enterprise_pdo(); enterprise_upgrade($pdo);
    $q = $pdo->prepare('SELECT * FROM projects WHERE id=?'); $q->execute([$id]); $project = $q->fetch();
    if (!$project) throw new RuntimeException('Projekt nicht gefunden.');
    $env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
    if ((string)$project['product_type'] === 'calendar') {
        enterprise_require_customer_module($user,'calendar');
        require_once dirname(__DIR__, 2) . '/system/app/calendar_project.php';
        $package = enterprise_calendar_distribution_create($env, $project);
    } else {
        enterprise_require_customer_module($user,'dataform');
        $package = enterprise_project_distribution_create($env, $project, (int)$user['id']);
    }
    enterprise_audit($pdo,(int)$user['id'],'project.application_export','project',(string)$id,[
        'filename'=>$package['filename'],'sha256'=>$package['sha256'],'size'=>$package['size'],'files'=>$package['files']
    ]);
    enterprise_event_dispatch('project.application.exported',['project_id'=>$id,'filename'=>$package['filename'],'sha256'=>$package['sha256']]);
    $path=(string)$package['path']; $filename=(string)$package['filename'];
    if (!is_file($path)) throw new RuntimeException('Anwenderpaket nicht gefunden.');
    session_write_close();
    header('Content-Type: application/zip');
    header('Content-Length: '.(string)filesize($path));
    header('Content-Disposition: attachment; filename="project-package.zip"; filename*=UTF-8\'\''.rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('X-Checksum-SHA256: '.(string)$package['sha256']);
    header('Cache-Control: no-store, private');
    $fh=fopen($path,'rb'); if($fh===false) throw new RuntimeException('Anwenderpaket kann nicht geöffnet werden.');
    while(!feof($fh)){ $chunk=fread($fh,1024*1024); if($chunk===false) break; echo $chunk; flush(); }
    fclose($fh); exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Anwenderpaket konnte nicht erzeugt werden: '.$e->getMessage();
}
