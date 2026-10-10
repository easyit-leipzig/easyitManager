<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';

$user = enterprise_require_auth('../../');
if (!enterprise_is_admin($user)) {
    http_response_code(403);
    $content = '<div class="notice error">Nur Administratoren dürfen die Lizenzverwaltung öffnen.</div>';
    render_page(['title'=>'Lizenzverwaltung','active'=>'licensing','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user]);
    exit;
}

$error = '';
$message = '';
$licenses = [];
$summary = [];

try {
    $pdo = enterprise_pdo();
    enterprise_upgrade($pdo);
    $kernel = enterprise_kernel();
    /** @var \DataForm5\Licensing\Core\LicenseRegistry $registry */
    $registry = $kernel->container()->get(\DataForm5\Licensing\Core\LicenseRegistry::class);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        enterprise_check_csrf((string)($_POST['csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'create') {
            $licenseKey = trim((string)($_POST['license_key'] ?? ''));
            $customerKey = mb_strtolower(trim((string)($_POST['customer_key'] ?? '')), 'UTF-8');
            $holder = trim((string)($_POST['holder'] ?? ''));
            if ($licenseKey === '' || $customerKey === '') throw new RuntimeException('Lizenzschlüssel und Kunden-E-Mail / Kundenschlüssel sind Pflichtfelder.');
            if (!filter_var($customerKey, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Der Kundenschlüssel muss eine gültige E-Mail-Adresse sein.');
            $id = $registry->create([
                'license_key'=>$licenseKey,
                'customer_key'=>$customerKey,
                'holder'=>$holder,
                'edition'=>trim((string)($_POST['edition'] ?? 'community')),
                'capabilities'=>(string)($_POST['capabilities'] ?? ''),
                'products'=>(string)($_POST['products'] ?? ''),
                'valid_from'=>$_POST['valid_from'] ?? null,
                'expires_at'=>$_POST['expires_at'] ?? null,
                'grace_days'=>(int)($_POST['grace_days'] ?? 0),
                'enabled'=>isset($_POST['enabled']),
                'is_primary'=>isset($_POST['is_primary']),
            ]);
            enterprise_audit($pdo, (int)($user['id'] ?? 0), 'license.created', 'license', (string)$id, ['customer_key'=>$customerKey,'holder'=>$holder,'edition'=>$_POST['edition'] ?? 'community']);
            $message = 'Lizenz wurde angelegt.';
        } elseif ($action === 'enable' && $id > 0) {
            $registry->setEnabled($id, true); $message = 'Lizenz wurde aktiviert.';
            enterprise_audit($pdo, (int)($user['id'] ?? 0), 'license.enabled', 'license', (string)$id);
        } elseif ($action === 'disable' && $id > 0) {
            $registry->setEnabled($id, false); $message = 'Lizenz wurde deaktiviert.';
            enterprise_audit($pdo, (int)($user['id'] ?? 0), 'license.disabled', 'license', (string)$id);
        } elseif ($action === 'primary' && $id > 0) {
            $registry->makePrimary($id); $message = 'Primärlizenz wurde geändert.';
            enterprise_audit($pdo, (int)($user['id'] ?? 0), 'license.primary', 'license', (string)$id);
        } elseif ($action === 'delete' && $id > 0) {
            $registry->delete($id); $message = 'Lizenz wurde gelöscht.';
            enterprise_audit($pdo, (int)($user['id'] ?? 0), 'license.deleted', 'license', (string)$id);
        }
    }

    $licenses = $registry->all();
    $summary = $kernel->container()->get(\DataForm5\Licensing\Contracts\LicenseManagerInterface::class)->summary();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Lizenzverwaltung','href'=>'']
]);
?>
<section class="hero">
  <span class="badge">Enterprise Core</span>
  <h1>Lizenzverwaltung</h1>
  <p>Zentrale Lizenzen für easyIT-Produkte, Editionen und Core-Fähigkeiten verwalten.</p>
  <p><a class="button" href="remote.php">DataForm-Lizenzserver / Aktivierung</a></p>
</section>
<?php if ($message): ?><div class="notice success"><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>

<div class="metric-grid">
  <div class="metric"><strong><?=count($licenses)?></strong><span>Lizenzen</span></div>
  <div class="metric"><strong><?=e((string)($summary['status'] ?? '—'))?></strong><span>Core-Status</span></div>
  <div class="metric"><strong><?=e((string)($summary['edition'] ?? '—'))?></strong><span>aktive Edition</span></div>
  <div class="metric"><strong><?=e((string)($summary['customer_key'] ?? ($summary['metadata']['customer_key'] ?? '—')))?></strong><span>Kundenschlüssel</span></div>
</div>

<section>
<h2>Neue Lizenz</h2>
<form method="post" class="card">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="create">
<div class="form-grid">
<label>Lizenzschlüssel<input name="license_key" required placeholder="ENT-XXXX-XXXX-XXXX"></label>
<label>Kunden-E-Mail / Kundenschlüssel<input type="email" name="customer_key" required placeholder="kunde@example.de" autocomplete="email"><small>Die normalisierte E-Mail-Adresse ist die eindeutige fachliche Kundenkennung.</small></label>
<label>Firma / Organisation (Anzeige)<input name="holder" placeholder="optional; nicht zur Lizenzzuordnung verwendet"></label>
<label>Edition<input name="edition" value="enterprise" placeholder="community / professional / enterprise"></label>
<label>Gültig ab<input type="date" name="valid_from"></label>
<label>Gültig bis<input type="date" name="expires_at"></label>
<label>Kulanz (Tage)<input type="number" min="0" name="grace_days" value="0"></label>
<label style="grid-column:1/-1">Produkte<textarea name="products" rows="2" placeholder="dataform, dialog, nachhilfe, calendar oder *"></textarea></label>
<label style="grid-column:1/-1">Capabilities<textarea name="capabilities" rows="3" placeholder="core.*, product.dataform.*, module.export"></textarea></label>
<label><input type="checkbox" name="enabled" value="1" checked> Aktiv</label>
<label><input type="checkbox" name="is_primary" value="1"> Als Primärlizenz setzen</label>
</div>
<p><button class="button" type="submit">Lizenz anlegen</button></p>
</form>
</section>

<section>
<div class="section-head"><h2>Vorhandene Lizenzen</h2></div>
<?php if (!$licenses): ?>
<div class="empty-state"><h3>Noch keine persistente Lizenz</h3><p>Bis eine Lizenz angelegt wurde, greift die bestehende Community-Konfiguration.</p></div>
<?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Lizenz</th><th>Kundenschlüssel</th><th>Firma / Organisation</th><th>Edition</th><th>Produkte</th><th>Gültigkeit</th><th>Status</th><th>Aktionen</th></tr></thead>
<tbody>
<?php foreach ($licenses as $license):
$products = json_decode((string)($license['products_json'] ?? '[]'), true); if (!is_array($products)) $products=[];
?>
<tr>
<td><strong><?=e((string)$license['license_key'])?></strong><?=((int)$license['is_primary']===1)?'<br><span class="badge">Primär</span>':''?></td>
<td><?=e((string)($license['customer_key'] ?? ''))?><?=empty($license['customer_key'])?'<br><span class="badge">Altbestand ohne E-Mail-Zuordnung</span>':''?></td>
<td><?=e((string)($license['holder'] ?? ''))?></td>
<td><?=e((string)$license['edition'])?></td>
<td><?=e($products ? implode(', ', array_map('strval',$products)) : 'alle')?></td>
<td><?=e((string)($license['valid_from'] ?: 'sofort'))?> – <?=e((string)($license['expires_at'] ?: 'unbegrenzt'))?></td>
<td><?=((int)$license['enabled']===1)?'aktiv':'deaktiviert'?></td>
<td>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="id" value="<?=(int)$license['id']?>"><input type="hidden" name="action" value="<?=((int)$license['enabled']===1)?'disable':'enable'?>"><button class="button" <?= easyit_button_attributes(((int)$license['enabled']===1)?'sperren':'entsperren') ?> type="submit"><?=((int)$license['enabled']===1)?'Deaktivieren':'Aktivieren'?></button></form>
<?php if ((int)$license['is_primary']!==1): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="id" value="<?=(int)$license['id']?>"><input type="hidden" name="action" value="primary"><button class="button" <?= easyit_button_attributes('favorit') ?> type="submit">Primär</button></form><?php endif; ?>
<form method="post" style="display:inline" onsubmit="return confirm('Lizenz wirklich löschen?')"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="id" value="<?=(int)$license['id']?>"><input type="hidden" name="action" value="delete"><button class="button" <?= easyit_button_attributes('loeschen') ?> type="submit">Löschen</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
<?php
$content = ob_get_clean();
render_page([
    'title'=>'Lizenzverwaltung','active'=>'licensing','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,
    'help'=>[
        'title'=>'Lizenzverwaltung','location'=>'Enterprise → Lizenzverwaltung',
        'short'=>'Zentrale Core-Lizenzen verwalten und eine Primärlizenz bestimmen.',
        'goal'=>'Produkt- und Modulfreigaben an einer Stelle pflegen.',
        'steps'=>['Lizenzschlüssel und Kunden-E-Mail/Kundenschlüssel eintragen.','Firma/Organisation nur optional als Anzeige ergänzen.','Produkte und Capabilities definieren.','Bei Bedarf eine Primärlizenz setzen.'],
        'examples'=>['Produkte: dataform, dialog','Capabilities: core.*, product.dataform.*'],
        'tips'=>['Die Lizenzzuordnung erfolgt ausschließlich über die normalisierte Kunden-E-Mail (customer_key), niemals über den Firmennamen.','Ohne persistente Lizenz bleibt die Community-Konfiguration als Fallback aktiv.','Nur Administratoren können Lizenzen ändern.']
    ]
]);
