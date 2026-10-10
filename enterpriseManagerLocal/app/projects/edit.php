<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.update');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
enterprise_require_project_scope($user,$id,true);
$error = '';
$project = null;
$form = [
    'name' => '',
    'slug' => '',
    'status' => 'active',
    'description' => '',
];

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);

    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Projekt nicht gefunden.');
    }

    $form = [
        'name' => (string)$project['name'],
        'slug' => (string)$project['slug'],
        'status' => (string)$project['status'],
        'description' => (string)($project['description'] ?? ''),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf_token'] ?? ''));

        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['slug'] = trim((string)($_POST['slug'] ?? ''));
        $form['status'] = trim((string)($_POST['status'] ?? 'active'));
        $form['description'] = trim((string)($_POST['description'] ?? ''));

        if ($form['name'] === '') {
            throw new RuntimeException('Projektname ist erforderlich.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,158}$/', $form['slug'])) {
            throw new RuntimeException('Der technische Slug ist ungültig.');
        }
        if (!in_array($form['status'], ['active', 'inactive'], true)) {
            throw new RuntimeException('Der Projektstatus ist ungültig.');
        }

        $duplicate = $pdo->prepare('SELECT id, name FROM projects WHERE slug = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$form['slug'], $id]);
        if ($existing = $duplicate->fetch()) {
            throw new RuntimeException('Der technische Slug wird bereits vom Projekt „' . (string)$existing['name'] . '“ verwendet.');
        }

        $before = [
            'name' => (string)$project['name'],
            'slug' => (string)$project['slug'],
            'status' => (string)$project['status'],
            'description' => (string)($project['description'] ?? ''),
        ];

        $update = $pdo->prepare('UPDATE projects SET name = ?, slug = ?, status = ?, description = ? WHERE id = ?');
        $update->execute([$form['name'], $form['slug'], $form['status'], $form['description'] !== '' ? $form['description'] : null, $id]);

        enterprise_audit($pdo, (int)$user['id'], 'project.update', 'project', (string)$id, [
            'before' => $before,
            'after' => $form,
            'database_name' => (string)$project['database_name'],
        ]);
        enterprise_event_dispatch('project.updated', [
            'project_id' => $id,
            'name' => $form['name'],
            'slug' => $form['slug'],
            'status' => $form['status'],
            'database_name' => (string)$project['database_name'],
        ], ['user_id' => (int)$user['id']]);

        $_SESSION['project_flash'] = [
            'type' => 'success',
            'message' => 'Projekt „' . $form['name'] . '“ wurde aktualisiert.',
        ];
        header('Location: index.php');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
render_breadcrumbs([
    ['label' => 'Enterprise', 'href' => '../dashboard.php'],
    ['label' => 'Projekte', 'href' => 'index.php'],
    ['label' => 'Projekt bearbeiten', 'href' => ''],
]);
?>
<section class="hero">
    <span class="badge">UPDATE</span>
    <h1>Projekt bearbeiten</h1>
    <p>Ändern Sie die Verwaltungsdaten des Projekts. Produkt und Datenbankbindung bleiben unverändert.</p>
</section>

<?php if ($error !== ''): ?>
    <div class="notice error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($project): ?>
<section class="card">
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(enterprise_csrf()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <label>
            Projektname
            <input name="name" required value="<?= e($form['name']) ?>">
        </label>

        <label>
            Technischer Slug
            <input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,158}" value="<?= e($form['slug']) ?>">
            <small>Kleinbuchstaben, Ziffern und Bindestriche.</small>
        </label>

        <label>
            Status
            <select name="status">
                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>active</option>
                <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option>
            </select>
        </label>

        <label>
            Produkt
            <input value="<?= e((string)$project['product_type']) ?>" readonly aria-readonly="true">
            <small>Die Produktzuordnung wird hier nicht geändert.</small>
        </label>

        <label>
            Datenbank
            <input value="<?= e((string)$project['database_name']) ?>" readonly aria-readonly="true">
            <small>Die Datenbankbindung bleibt unverändert.</small>
        </label>

        <label class="form-span">
            Beschreibung
            <textarea name="description" rows="5" placeholder="Optionale Beschreibung des Projekts"><?= e($form['description']) ?></textarea>
        </label>

        <div class="form-span button-row">
            <button class="button" data-crud="edit" type="submit">Änderungen speichern</button>
            <a class="button secondary" href="view.php?id=<?= $id ?>">Abbrechen</a>
        </div>
    </form>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page([
    'title' => 'Projekt bearbeiten',
    'active' => 'projects',
    'base' => '../../',
    'content' => $content,
    'app_nav' => true,
    'user' => $user,
    'help' => [
        'title' => 'Projekt bearbeiten',
        'location' => 'Enterprise → Projekte → Bearbeiten',
        'short' => 'Verwaltungsdaten eines Projekts ändern.',
        'goal' => 'Name, Slug, Status oder Beschreibung aktualisieren.',
        'tips' => [
            'Die Datenbank wird nicht umbenannt.',
            'Der technische Slug muss projektweit eindeutig sein.',
        ],
    ],
]);
