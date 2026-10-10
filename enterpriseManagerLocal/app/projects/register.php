<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require_once dirname(__DIR__, 2) . '/system/app/project_store.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.create');
$error = '';
$projectProducts = [];
$databaseStatus = [
    'configured' => false,
    'exists' => false,
    'schema' => false,
    'message' => '',
];

$env = enterprise_env(dirname(__DIR__, 2) . '/DataForm5-Core/.env');
$setupDatabase = trim((string)($env['PROJECT_DB_DATABASE'] ?? ''));
$form = [
    'name' => trim((string)($env['CONTEXT_PROJECT_NAME'] ?? '')),
    'slug' => '',
    'product_type' => 'dataform',
    'database_name' => $setupDatabase,
];

function project_database_status(array $env): array
{
    $database = trim((string)($env['PROJECT_DB_DATABASE'] ?? ''));
    $driver = enterprise_project_store_driver($env);
    if ($database === '') {
        return ['configured'=>false,'exists'=>false,'schema'=>false,'driver'=>$driver,'message'=>'In DataForm5-Core/.env ist noch kein Projektdatenspeicher eingetragen. Führen Sie zuerst Setup-Schritt 6 aus.'];
    }
    if (!in_array($driver,['mysql','csv','sqlite','pgsql'],true)) {
        return ['configured'=>false,'exists'=>false,'schema'=>false,'driver'=>$driver,'message'=>'Der konfigurierte Projekttreiber wird in dieser Phase noch nicht unterstützt: '.$driver];
    }
    try {
        if (!enterprise_project_store_exists($env,$database,$driver)) {
            return ['configured'=>true,'exists'=>false,'schema'=>false,'driver'=>$driver,'message'=>'Der in .env registrierte Projektdatenspeicher wurde nicht gefunden.'];
        }
        $pdo = enterprise_project_store_pdo($env,$database,$driver);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($driver==='csv' && $tables!==[]) {
            // EnterpriseCsvPdo liefert bei SHOW TABLES assoziative Einspaltenzeilen; FETCH_COLUMN normalisiert diese.
            $tables=array_values(array_map('strval',$tables));
        }
        $schemaReady = in_array('dataforms',$tables,true) && in_array('dataform_fields',$tables,true);
        return [
            'configured'=>true,'exists'=>true,'schema'=>$schemaReady,'driver'=>$driver,
            'message'=>$schemaReady
                ? ($driver==='csv'?'Der CSV-Projektdatenspeicher ist erreichbar und das DataForm-Basisschema wurde gefunden.':($driver==='sqlite'?'Der SQLite-Projektdatenspeicher ist erreichbar und das DataForm-Basisschema wurde gefunden.':($driver==='pgsql'?'Der PostgreSQL-Projektdatenspeicher ist erreichbar und das DataForm-Basisschema wurde gefunden.':'Der Projektdatenspeicher ist erreichbar und das DataForm-Basisschema wurde gefunden.')))
                : 'Der Projektdatenspeicher ist erreichbar, aber das erwartete DataForm-Basisschema ist unvollständig.',
        ];
    } catch (Throwable $e) {
        return ['configured'=>true,'exists'=>false,'schema'=>false,'driver'=>$driver,'message'=>'Der in .env registrierte Projektdatenspeicher ist nicht erreichbar: '.$e->getMessage()];
    }
}

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $projectProducts = enterprise_available_project_products($pdo,$user);
    $databaseStatus = project_database_status($env);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));

        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['slug'] = trim((string)($_POST['slug'] ?? ''));
        $form['product_type'] = trim((string)($_POST['product_type'] ?? 'dataform'));
        $form['database_name'] = $setupDatabase;

        if ($form['name'] === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,158}$/', $form['slug'])) {
            throw new RuntimeException('Projektname und ein gültiger technischer Slug sind erforderlich.');
        }
        if (!isset($projectProducts[$form['product_type']])) {
            throw new RuntimeException('Das gewählte Produkt ist nicht installiert, nicht verfügbar oder nicht lizenziert.');
        }
        if ($setupDatabase === '') {
            throw new RuntimeException('Es ist kein Projektdatenspeicher aus dem Setup registriert. Führen Sie zuerst Schritt 6 aus.');
        }
        if (!$databaseStatus['exists']) {
            throw new RuntimeException('Der Projektdatenspeicher aus dem Setup ist nicht erreichbar. Es wird keine neue Datenbank angelegt.');
        }
        if (!$databaseStatus['schema']) {
            throw new RuntimeException('Das Basisschema der Projektdatenspeicher ist unvollständig. Führen Sie Setup-Schritt 6 erneut bis zum Schema-Schritt aus.');
        }

        $duplicate = $pdo->prepare('SELECT id, name FROM projects WHERE database_name = ? LIMIT 1');
        $duplicate->execute([$setupDatabase]);
        $existing = $duplicate->fetch();
        if ($existing) {
            throw new RuntimeException('Der Projektdatenspeicher ist bereits dem Projekt „' . (string)$existing['name'] . '“ zugeordnet.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO projects(name, slug, product_type, database_driver, database_name, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $projectDriver=enterprise_project_store_driver($env);
        if($projectDriver==='csv'){
            $projectPdo=enterprise_project_store_pdo($env,$setupDatabase,'csv');
            enterprise_project_store_ensure_default_csv_source($projectPdo,$env,$setupDatabase);
        }
        elseif($projectDriver==='sqlite'){
            $projectPdo=enterprise_project_store_pdo($env,$setupDatabase,'sqlite');
            enterprise_project_store_ensure_default_sqlite_source($projectPdo,$env,$setupDatabase);
        }
        elseif($projectDriver==='pgsql'){
            $projectPdo=enterprise_project_store_pdo($env,$setupDatabase,'pgsql');
            enterprise_project_store_ensure_default_pgsql_source($projectPdo,$env,$setupDatabase);
        }
        $stmt->execute([$form['name'], $form['slug'], $form['product_type'], $projectDriver, $setupDatabase]);
        $id = (int)$pdo->lastInsertId();
        $storageKey=enterprise_project_storage_assign($pdo,$id,$form['slug']);
        $project=enterprise_project_storage_fetch($pdo,$id);
        $storageRoot=enterprise_project_storage_provision($project,$env);

        enterprise_audit($pdo, (int)$user['id'], 'project.register', 'project', (string)$id, [
            'name' => $form['name'],
            'slug' => $form['slug'],
            'product_type' => $form['product_type'],
            'database_name' => $setupDatabase,
            'database_driver' => enterprise_project_store_driver($env),
            'database_source' => 'setup_env',
            'database_created' => false,
            'storage_key' => $storageKey,
            'storage_root' => $storageRoot,
        ]);
        enterprise_event_dispatch('project.registered', [
            'project_id'=>$id,
            'name'=>$form['name'],
            'slug'=>$form['slug'],
            'product_type'=>$form['product_type'],
            'database_name'=>$setupDatabase,
        ], ['user_id'=>(int)$user['id']]);

        header('Location: view.php?id=' . $id);
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
?>
<?php render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Projekte','href'=>'index.php'],['label'=>'Vorhandenes Projekt registrieren','href'=>'']]); ?>
<section class="hero">
    <span class="badge">Projekt-Assistent</span>
    <h1>Vorhandenes Projekt registrieren</h1>
    <p>Ordnen Sie die bereits in Setup-Schritt 6 angelegte Projektdatenspeicher einem Enterprise-Projekt zu. Es wird kein neuer physischer Projektdatenspeicher erzeugt.</p>
</section>

<?php if ($error): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card">
    <h2>Projektdatenspeicher aus dem Setup</h2>
    <dl class="status-list">
        <div><dt>Konfiguration</dt><dd><?= $databaseStatus['configured'] ? '✔ vorhanden' : '✘ fehlt' ?></dd></div>
        <div><dt>Datenbank</dt><dd><code><?= e($setupDatabase !== '' ? $setupDatabase : 'nicht eingetragen') ?></code></dd></div>
        <div><dt>Verbindung</dt><dd><?= $databaseStatus['exists'] ? '✔ geprüft' : '✘ nicht erreichbar' ?></dd></div>
        <div><dt>Basisschema</dt><dd><?= $databaseStatus['schema'] ? '✔ vorhanden' : '✘ unvollständig' ?></dd></div>
    </dl>
    <p><?= e($databaseStatus['message']) ?></p>
</section>

<section class="card">
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">

        <label>
            Projektname
            <input name="name" value="<?= e($form['name']) ?>" required placeholder="Demo DataForm">
        </label>

        <label>
            Technischer Slug
            <input name="slug" value="<?= e($form['slug']) ?>" required pattern="[a-z0-9][a-z0-9-]{1,158}" placeholder="demo-dataform">
            <small>Kleinbuchstaben, Ziffern und Bindestriche.</small>
        </label>

        <label>
            Produkt
            <select name="product_type">
                <?php foreach($projectProducts as $product): ?><option value="<?=e($product['key'])?>" <?= $form['product_type']===$product['key'] ? 'selected' : '' ?>><?=e($product['label'])?></option><?php endforeach; ?>
                <option disabled>Dialog – geplant</option>
                <option disabled>Nachhilfe – geplant</option>
                <option disabled>CSV-Engine – geplant</option>
            </select>
        </label>

        <label>
            Projektdatenspeicher aus Schritt 6
            <input value="<?= e($setupDatabase) ?>" readonly aria-readonly="true">
            <small>Dieses Feld stammt aus <code>PROJECT_DB_DATABASE</code> und kann hier nicht geändert werden.</small>
        </label>

        <div class="form-span notice">
            <strong>Wichtig:</strong> Beim Registrieren wird ausschließlich ein Projekteinsatz in der Administrationsdatenbank angelegt. Es wird kein <code>CREATE DATABASE</code> ausgeführt und das vorhandene Projektschema wird nicht neu initialisiert.
        </div>

        <div class="form-span button-row">
            <button class="button" type="submit" <?= (!$databaseStatus['exists'] || !$databaseStatus['schema']) ? 'disabled' : '' ?>>Projekt registrieren</button>
            <a class="button secondary" href="index.php">Abbrechen</a>
        </div>
    </form>
</section>
<?php
$content = ob_get_clean();

render_page([
    'title' => 'Vorhandenes Projekt registrieren',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projekt-Assistent',
        'location' => 'Enterprise → Projekte → Registrieren',
        'short' => 'Die im Setup erzeugte Projektdatenspeicher wird einem Enterprise-Projekt zugeordnet.',
        'goal' => 'Projektname und DataForm-Produkt mit der vorhandenen Projektdatenspeicher verbinden.',
        'steps' => [
            'Status der Projektdatenspeicher kontrollieren.',
            'Aussagekräftigen Projektnamen vergeben.',
            'Technischen Slug mit Bindestrichen festlegen.',
            'Projekt registrieren.',
        ],
        'examples' => [
            'Projektname: Demo DataForm',
            'Slug: demo-dataform',
            'Datenbank: ' . ($setupDatabase !== '' ? $setupDatabase : 'aus Setup-Schritt 6'),
        ],
        'tips' => [
            'Hier wird keine neue Datenbank angelegt.',
            'Der Speichername wird aus DataForm5-Core/.env gelesen.',
            'Eine Projektdatenspeicher kann nur einem Enterprise-Projekt zugeordnet werden.',
        ],
    ],
]);
