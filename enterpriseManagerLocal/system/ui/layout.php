<?php
declare(strict_types=1);

require_once __DIR__ . '/ButtonRegistry.php';

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }


function render_breadcrumbs(array $items): void
{
    echo '<nav class="breadcrumbs" aria-label="Brotkrümelnavigation"><ol>';
    $last = count($items) - 1;
    foreach (array_values($items) as $index => $item) {
        $label = (string)($item['label'] ?? '');
        $href = (string)($item['href'] ?? '');
        echo '<li>';
        if ($href !== '' && $index !== $last) {
            echo '<a href="' . e($href) . '">' . e($label) . '</a>';
        } else {
            echo '<span' . ($index === $last ? ' aria-current="page"' : '') . '>' . e($label) . '</span>';
        }
        echo '</li>';
    }
    echo '</ol></nav>';
}

function render_page(array $page): void
{
    $title = (string)($page['title'] ?? 'easyIT Enterprise');
    $active = (string)($page['active'] ?? 'home');
    $content = (string)($page['content'] ?? '');
    $help = is_array($page['help'] ?? null) ? $page['help'] : [];
    $versionFile = dirname(__DIR__, 2) . '/VERSION';
    $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'RC1.2.0-dev';
    $bodyClass = trim((string)($page['body_class'] ?? ''));
    $base = (string)($page['base'] ?? '');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isDataFormPage = str_contains($scriptName, '/products/dataform/');
    $enterpriseManagerLogoFile = dirname(__DIR__, 2) . '/assets/img/easyit-epManager-logo.png';
    $hasEnterpriseManagerLogo = is_file($enterpriseManagerLogoFile); // compatibility diagnostic; release markup always uses the manager logo
    $pageUser=is_array($page['user']??null)?$page['user']:[];
    $customerMode=!empty($pageUser['customer']);
    $customerNav=[
        'dashboard' => ['Dashboard', $base . 'app/dashboard.php'],
        'account' => ['Mein Konto', $base . 'app/account.php'],
    ];
    if($customerMode && (!function_exists('enterprise_customer_has_module') || enterprise_customer_has_module($pageUser,'dataform'))){
        $customerNav=['dashboard'=>$customerNav['dashboard'],'projects'=>['Projekte',$base.'app/projects/index.php'],'account'=>$customerNav['account']];
    }
    if($customerMode && function_exists('enterprise_can') && (enterprise_can($pageUser,'customer.users.manage')||enterprise_can($pageUser,'customer.roles.manage'))){
        $before=$customerNav;$customerNav=[];foreach($before as $k=>$v){if($k==='account')$customerNav['team']=['Benutzer & Rollen',$base.'app/team.php'];$customerNav[$k]=$v;}
    }
    $nav = !empty($page['app_nav']) ? ($customerMode ? $customerNav : [
        'dashboard' => ['Dashboard', $base . 'app/dashboard.php'],
        'projects' => ['Projekte', $base . 'app/projects/index.php'],
        'products' => ['Produkte', $base . 'app/products/index.php'],
        'licensing' => ['Lizenzen', $base . 'app/licensing/index.php'],
        'security' => ['Benutzer & Rechte', $base . 'app/security/users.php'],
        'db-users' => ['DB-Benutzer', $base . 'app/security/database-users.php'],
        'audit' => ['Audit-Protokoll', $base . 'app/security/audit.php'],
        'modules' => ['Module', $base . 'app/modules/index.php'],
        'operations' => ['Betrieb', $base . 'app/operations/index.php'],
        'setup' => ['Setup', $base . 'setup.php'],
        'database-setup' => ['DB-Assistent', $base . 'installer/database.php'],
        'developer' => ['Developer', $base . 'app/developer/index.php'],
    ]) : [
        'home' => ['Start', $base . 'index.php'],
        'setup' => ['Setup', $base . 'setup.php'],
        'health' => ['Systemprüfung', $base . 'health.php'],
        'docs' => ['Dokumentation', $base . 'documentation.php'],
    ];
    if (!empty($page['app_nav']) && isset($nav['developer'])) {
        $devUser=is_array($page['user']??null)?$page['user']:[];
        if(!function_exists('enterprise_developer_enabled') || !enterprise_developer_enabled($devUser)) unset($nav['developer']);
    }
    if (!empty($page['app_nav']) && !$customerMode && function_exists('enterprise_module_ui')) {
        try {
            $user=is_array($page['user']??null)?$page['user']:[];
            foreach(enterprise_module_ui()->navigation($user) as $item){
                $key='module:'.(string)($item['module']??md5((string)$item['href']));
                $href=(string)$item['href'];
                if(!preg_match('~^(?:https?://|/)~',$href)) $href=$base.ltrim($href,'/');
                $nav[$key]=[(string)$item['label'],$href];
            }
        } catch (\Throwable) {}
    }
    if (!empty($page['app_nav'])) {
        $nav['logout'] = ['Abmelden', $base . 'logout.php'];
    }
    ?><!doctype html>
<html lang="de"<?= (!empty($page['app_nav']) && function_exists('enterprise_developer_enabled') && enterprise_developer_enabled(is_array($page['user']??null)?$page['user']:[]) && (enterprise_developer_config()['overlay']??true)) ? ' data-developer-overlay-endpoint="' . e($base . 'app/developer/overlay.php') . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – easyIT Enterprise</title>
<?php
$enterpriseCssFile = dirname(__DIR__, 2) . '/assets/css/enterprise.css';
$enterpriseCss = is_file($enterpriseCssFile) ? (string)file_get_contents($enterpriseCssFile) : '';
?>
<link rel="stylesheet" href="<?= e($base) ?>assets/css/enterprise.css">
<?php if ($enterpriseCss !== ''): ?>
<style id="easyit-enterprise-critical-css"><?= $enterpriseCss ?></style>
<?php endif; ?>
<?php if ($isDataFormPage): ?>
<link id="easyit-dataform-branding-css" rel="stylesheet" href="<?= e($base) ?>products/dataform/assets/branding/dataform-branding.css">
<?php endif; ?>
<?php foreach ((array)($page['styles'] ?? []) as $stylesheet): ?>
<?php $stylesheet = ltrim((string)$stylesheet, '/'); if ($stylesheet === '') continue; ?>
<link rel="stylesheet" href="<?= e($base . $stylesheet) ?>">
<?php endforeach; ?>
<?php
$crudCssFile = dirname(__DIR__, 2) . '/assets/css/easyit-crud-3d-buttons.css';
$crudCssVersion = is_file($crudCssFile) ? (string)filemtime($crudCssFile) : '1';
?>
<link id="easyit-global-crud-3d-css" rel="stylesheet" href="<?= e($base) ?>assets/css/easyit-crud-3d-buttons.css?v=<?= e($crudCssVersion) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip-link" href="#main">Direkt zum Inhalt</a>
<header class="topbar">
  <?php $brandTarget = !empty($page['app_nav']) && is_array($page['user'] ?? null) && function_exists('enterprise_landing_path') ? $base . enterprise_landing_path($page['user']) : $base . 'index.php'; ?>
  <a class="brand enterprise-manager-brand" href="<?= e($brandTarget) ?>" aria-label="easyIT Enterprise Manager">
    <img class="enterprise-manager-brand__logo" src="<?= e($base) ?>assets/img/easyit-epManager-logo.png" alt="easyIT Enterprise Manager">
    <small class="enterprise-manager-brand__version"><?= e($version) ?></small>
  </a>
  <nav aria-label="Hauptnavigation">
  <?php foreach ($nav as $key => [$label, $href]): ?>
    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  </nav>
</header>
<?php if ($isDataFormPage): ?>
<div class="easyit-dataform-branding" data-dataform-branding="HF68" data-dataform-branding-compat="HF64"><?php /* data-dataform-branding="HF64" compatibility */ ?>
  <img class="easyit-dataform-branding__logo" src="<?= e($base) ?>products/dataform/assets/branding/easyit-dataform-logo.png" alt="easyIT DataForm">
</div>
<?php endif; ?>
<div class="app-shell">
<main id="main" class="content">
  <?php if ($active !== 'home' && empty($page['app_nav'])): ?><a class="back-link" href="<?= e($base) ?>index.php">← Zurück zur Startseite</a><?php endif; ?>
  <?= $content ?>
</main>
<aside class="help-sidebar" aria-label="Kontexthilfe">
  <div class="help-sticky">
    <div class="help-heading"><span>?</span><div><strong><?= e((string)($help['title'] ?? 'Hilfe')) ?></strong><small>Kontexthilfe</small></div></div>
    <?php if (!empty($help['location'])): ?><p class="help-location"><strong>Sie befinden sich hier:</strong><br><?= e((string)$help['location']) ?></p><?php endif; ?>
    <?php if (!empty($help['short'])): ?><p><?= e((string)$help['short']) ?></p><?php endif; ?>
    <?php if (!empty($help['goal'])): ?><section><h3>Ziel</h3><p><?= e((string)$help['goal']) ?></p></section><?php endif; ?>
    <?php if (!empty($help['next'])): ?><section><h3>Nächster Schritt</h3><p><?= e((string)$help['next']) ?></p></section><?php endif; ?>
    <?php foreach (['steps' => 'Schritt für Schritt', 'examples' => 'Beispiele', 'tips' => 'Typische Fehler und Hinweise'] as $field => $heading): ?>
      <?php if (!empty($help[$field]) && is_array($help[$field])): ?>
      <section><h3><?= e($heading) ?></h3><ol class="help-list">
      <?php foreach ($help[$field] as $item): ?><li><?= e((string)$item) ?></li><?php endforeach; ?>
      </ol></section>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!empty($help['duration'])): ?><p class="help-duration"><strong>Dauer:</strong> <?= e((string)$help['duration']) ?></p><?php endif; ?>
    <a class="help-doc-link" href="<?= e($base) ?>documentation.php">Vollständige Dokumentation</a>
  </div>
</aside>
</div>
<footer>easyIT Enterprise Manager · <?= e($version) ?></footer>
<?= easyit_button_registry_data_tag() ?>
<?php $buttonRegistryJsFile = dirname(__DIR__, 2) . '/assets/js/easyit-button-registry.js'; $buttonRegistryJsVersion = is_file($buttonRegistryJsFile) ? (string)filemtime($buttonRegistryJsFile) : '0'; ?>
<script src="<?= e($base) ?>assets/js/easyit-button-registry.js?v=<?= e($buttonRegistryJsVersion) ?>" defer></script>
<?php if (!empty($page['script'])): ?><script src="<?= e($base . (string)$page['script']) ?>" defer></script><?php endif; ?>
<?php if (!empty($page['app_nav']) && function_exists('enterprise_developer_enabled') && enterprise_developer_enabled(is_array($page['user']??null)?$page['user']:[]) && (enterprise_developer_config()['overlay']??true)): ?>
<script src="<?= e($base) ?>assets/js/developer-overlay.js" defer></script>
<?php endif; ?>
</body></html><?php
}
