<?php
declare(strict_types=1);

final class ApplicationBuilder
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS applications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
            description TEXT NULL,
            start_page VARCHAR(80) NOT NULL DEFAULT 'dashboard',
            environment VARCHAR(20) NOT NULL DEFAULT 'development',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_app_project_slug(project_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_navigation (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(160) NOT NULL,
            target_type VARCHAR(40) NOT NULL DEFAULT 'dataform',
            target_value VARCHAR(190) NULL,
            icon VARCHAR(40) NULL,
            role_name VARCHAR(80) NULL,
            position INT NOT NULL DEFAULT 10,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_app_nav(application_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_dashboard (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            widget_type VARCHAR(50) NOT NULL,
            title VARCHAR(160) NOT NULL,
            configuration_json LONGTEXT NULL,
            position INT NOT NULL DEFAULT 10,
            width VARCHAR(20) NOT NULL DEFAULT '50',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_app_dashboard(application_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_builds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            version VARCHAR(40) NOT NULL,
            environment VARCHAR(20) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'success',
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_app_builds(application_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function slug(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        return trim($value, '-') ?: 'application';
    }

    public static function build(PDO $pdo, array $application, array $project, array $user, string $root): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ext-zip wird für Builds benötigt.');
        }
        $appId = (int)$application['id'];
        $nav = $pdo->prepare('SELECT * FROM application_navigation WHERE application_id=? AND is_visible=1 ORDER BY position,id');
        $nav->execute([$appId]);
        $widgets = $pdo->prepare('SELECT * FROM application_dashboard WHERE application_id=? ORDER BY position,id');
        $widgets->execute([$appId]);
        $forms = $pdo->query('SELECT id,name,slug,description,status FROM dataforms ORDER BY name')->fetchAll();

        $buildDir = rtrim($root, '/\\') . '/workspace/application-builds';
        if (!is_dir($buildDir) && !mkdir($buildDir, 0775, true) && !is_dir($buildDir)) {
            throw new RuntimeException('Build-Verzeichnis konnte nicht angelegt werden.');
        }
        $fileName = self::slug((string)$application['slug']) . '-' . preg_replace('/[^0-9A-Za-z._-]/', '-', (string)$application['version']) . '.zip';
        $path = $buildDir . '/' . $fileName;
        $manifest = [
            'format' => 'easyit-application', 'formatVersion' => '1.0',
            'application' => ['name'=>$application['name'],'slug'=>$application['slug'],'version'=>$application['version'],'description'=>$application['description'],'environment'=>$application['environment']],
            'project' => ['name'=>$project['name'],'slug'=>$project['slug']],
            'createdAt' => gmdate('c'), 'createdBy' => $user['username'] ?? $user['email'] ?? 'system',
            'counts' => ['dataforms'=>count($forms),'navigation'=>count($nav->fetchAll()),'widgets'=>count($widgets->fetchAll())],
        ];
        $nav->execute([$appId]); $navigation = $nav->fetchAll();
        $widgets->execute([$appId]); $dashboard = $widgets->fetchAll();

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Build-ZIP konnte nicht erzeugt werden.');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('config/application.json', json_encode($application, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('config/navigation.json', json_encode($navigation, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('config/dashboard.json', json_encode($dashboard, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('config/dataforms.json', json_encode($forms, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->addFromString('public/index.php', self::generatedIndex($application, $navigation, $dashboard));
        $zip->addFromString('public/assets/app.css', self::generatedCss());
        $zip->addFromString('README.md', "# {$application['name']}\n\nErzeugt mit easyIT Enterprise Application Builder.\n\nVersion: {$application['version']}\nUmgebung: {$application['environment']}\n");
        $zip->close();
        $sha = hash_file('sha256', $path);
        $size = filesize($path) ?: 0;
        $stmt = $pdo->prepare('INSERT INTO application_builds(application_id,version,environment,file_name,sha256,file_size,status,created_by) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$appId,$application['version'],$application['environment'],$fileName,$sha,$size,'success',$user['id']??null]);
        return ['path'=>$path,'filename'=>$fileName,'sha256'=>$sha,'size'=>$size];
    }

    private static function generatedIndex(array $app, array $navigation, array $dashboard): string
    {
        $title = htmlspecialchars((string)$app['name'], ENT_QUOTES, 'UTF-8');
        $navHtml=''; foreach($navigation as $item){$navHtml.='<a href="#">'.htmlspecialchars((string)$item['label'],ENT_QUOTES,'UTF-8').'</a>';}
        $widgetsHtml=''; foreach($dashboard as $widget){$widgetsHtml.='<article class="widget"><strong>'.htmlspecialchars((string)$widget['title'],ENT_QUOTES,'UTF-8').'</strong><p>'.htmlspecialchars((string)$widget['widget_type'],ENT_QUOTES,'UTF-8').'</p></article>';}
        if($widgetsHtml===''){$widgetsHtml='<article class="widget"><strong>Willkommen</strong><p>Dashboard ist bereit.</p></article>';}
        return "<?php declare(strict_types=1); ?><!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title}</title><link rel=\"stylesheet\" href=\"assets/app.css\"></head><body><header><h1>{$title}</h1></header><div class=\"shell\"><nav>{$navHtml}</nav><main><h2>Dashboard</h2><section class=\"widgets\">{$widgetsHtml}</section></main></div></body></html>";
    }

    private static function generatedCss(): string
    {
        return 'body{margin:0;font-family:system-ui;background:#f4f7fb;color:#182230}header{padding:1rem 1.5rem;background:#153e75;color:white}.shell{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 72px)}nav{padding:1rem;background:white;border-right:1px solid #d8dee8}nav a{display:block;padding:.7rem;border-radius:.5rem;color:#153e75;text-decoration:none}nav a:hover{background:#e8f0fb}main{padding:1.5rem}.widgets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}.widget{background:white;border:1px solid #d8dee8;border-radius:.75rem;padding:1rem}@media(max-width:700px){.shell{grid-template-columns:1fr}nav{border-right:0;border-bottom:1px solid #d8dee8}}';
    }
}
