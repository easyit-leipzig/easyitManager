<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/system/ui/ButtonRegistry.php';

/**
 * DataForm runtime shell.
 *
 * The product workspace deliberately resolves its asset and navigation URLs
 * from the actually executing SCRIPT_NAME.  This avoids fragile ../../ base
 * calculations when the Enterprise package is renamed or installed below a
 * different htdocs folder.
 */
function dataform_runtime_root_url(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/products/dataform/runtime.php'));
    $suffix = '/products/dataform/runtime.php';
    if (str_ends_with($script, $suffix)) {
        $root = substr($script, 0, -strlen($suffix));
        return $root === '' ? '' : rtrim($root, '/');
    }

    // Fallback for CLI/tests or alternative front-controller routing.
    $dir = str_replace('\\', '/', dirname(dirname(dirname($script))));
    return $dir === '/' ? '' : rtrim($dir, '/');
}

function dataform_runtime_url(string $path = ''): string
{
    $root = dataform_runtime_root_url();
    $path = ltrim($path, '/');
    return $root . ($path !== '' ? '/' . $path : '');
}

function dataform_runtime_render(array $page): void
{
    $title = (string)($page['title'] ?? 'DataForm Workspace');
    $content = (string)($page['content'] ?? '');
    $user = is_array($page['user'] ?? null) ? $page['user'] : [];
    $help = is_array($page['help'] ?? null) ? $page['help'] : [];

    $projectRoot = dirname(__DIR__, 3);
    $versionFile = $projectRoot . '/VERSION';
    $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'RC1.8';
    $enterpriseCssFile = $projectRoot . '/assets/css/enterprise.css';
    $workspaceCssFile = dirname(__DIR__) . '/assets/workspace.css';
    $crudCssFile = $projectRoot . '/assets/css/easyit-crud-3d-buttons.css';
    $brandingCssFile = dirname(__DIR__) . '/assets/branding/dataform-branding.css';
    $enterpriseManagerLogoFile = $projectRoot . '/assets/img/easyit-epManager-logo.png';
    $hasEnterpriseManagerLogo = is_file($enterpriseManagerLogoFile); // compatibility diagnostic; release markup always uses the manager logo

    $enterpriseV = is_file($enterpriseCssFile) ? (string)filemtime($enterpriseCssFile) : '0';
    $workspaceV = is_file($workspaceCssFile) ? (string)filemtime($workspaceCssFile) : '0';
    $crudV = is_file($crudCssFile) ? (string)filemtime($crudCssFile) : '0';
    $brandingV = is_file($brandingCssFile) ? (string)filemtime($brandingCssFile) : '0';

    $nav = [
        ['Dashboard', dataform_runtime_url('app/dashboard.php')],
        ['Projektliste', dataform_runtime_url('app/projects/index.php')],
        ['Produkte', dataform_runtime_url('app/products/index.php')],
        ['DataForm', dataform_runtime_url('products/dataform/index.php') . (!empty($_GET['project']) ? '?project=' . (int)$_GET['project'] : '')],
        ['Benutzer & Rechte', dataform_runtime_url('app/security/users.php')],
        ['Audit', dataform_runtime_url('app/security/audit.php')],
        ['Setup', dataform_runtime_url('setup.php')],
        ['Recovery', dataform_runtime_url('recovery.php')],
        ['Abmelden', dataform_runtime_url('logout.php')],
    ];
    ?>
<!doctype html>
<html lang="de" data-easyit-runtime="dataform-hf36">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= e($title) ?> – easyIT Enterprise</title>

<!-- Absolute, runtime-resolved URLs. These remain valid if the package folder is renamed. -->
<link id="easyit-enterprise-css" rel="stylesheet" href="<?= e(dataform_runtime_url('assets/css/enterprise.css')) ?>?v=<?= e($enterpriseV) ?>">
<link id="easyit-dataform-workspace-css" rel="stylesheet" href="<?= e(dataform_runtime_url('products/dataform/assets/workspace.css')) ?>?v=<?= e($workspaceV) ?>">
<link id="easyit-global-crud-3d-css" rel="stylesheet" href="<?= e(dataform_runtime_url('assets/css/easyit-crud-3d-buttons.css')) ?>?v=<?= e($crudV) ?>">
<link id="easyit-dataform-branding-css" rel="stylesheet" href="<?= e(dataform_runtime_url('products/dataform/assets/branding/dataform-branding.css')) ?>?v=<?= e($brandingV) ?>">

<!-- Minimal shell fallback. The workspace remains usable even if an external
     stylesheet is blocked or stale in the browser/proxy cache. -->
<style id="easyit-dataform-runtime-fallback">
html,body{margin:0;min-height:100%;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#eef2f7;color:#172033}
*{box-sizing:border-box}
.df-runtime-topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;min-height:68px;padding:.7rem 1.25rem;background:#0f294d;color:#fff}
.df-runtime-brand{display:flex;align-items:center;gap:.65rem;color:#fff;text-decoration:none;font-weight:800}
.df-runtime-enterprise-logo{display:block;width:auto;height:auto;max-width:230px;max-height:52px;object-fit:contain;background:#fff;border-radius:6px;padding:.18rem .35rem}
.df-runtime-enterprise-version{display:block;color:#c8d5e7;font-size:.72rem;line-height:1.1;white-space:nowrap}
.df-runtime-nav{display:flex;gap:.3rem;flex-wrap:wrap}
.df-runtime-nav a{color:#e5edf8;text-decoration:none;padding:.5rem .65rem;border-radius:7px}
.df-runtime-nav a:hover{background:#294b78;color:#fff}
.df-runtime-shell{max-width:none;padding:.75rem}
.df-runtime-main{min-height:calc(100vh - 120px);background:#fff;border:1px solid #d7dee9;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.08)}
.df-runtime-footer{padding:.65rem 1rem;text-align:center;color:#667085;font-size:.82rem}
@media(max-width:950px){.df-runtime-topbar{align-items:flex-start;flex-direction:column}.df-runtime-nav{width:100%}}
</style>
</head>
<body class="workspace-page dataform-runtime-page">
<a class="skip-link" href="#main">Direkt zum Inhalt</a>
<header class="df-runtime-topbar" data-runtime-shell="topbar">
    <a class="df-runtime-brand" href="<?= e(dataform_runtime_url('index.php')) ?>" aria-label="easyIT Enterprise Manager">
        <img class="df-runtime-enterprise-logo" src="<?= e(dataform_runtime_url('assets/img/easyit-epManager-logo.png')) ?>" alt="easyIT Enterprise Manager">
        <small class="df-runtime-enterprise-version"><?= e($version) ?></small>
    </a>
    <nav class="df-runtime-nav" aria-label="Enterprise-Navigation">
        <?php foreach ($nav as [$label, $href]): ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<div class="easyit-dataform-branding" data-dataform-branding="HF69" data-dataform-branding-compat="HF68 HF67 HF66"><?php /* data-dataform-branding="HF68" compatibility */ ?>
    <img class="easyit-dataform-branding__logo" src="<?= e(dataform_runtime_url('products/dataform/assets/branding/easyit-dataform-logo.png')) ?>" alt="easyIT DataForm">
</div>

<div class="df-runtime-shell">
<main id="main" class="df-runtime-main" data-runtime-shell="main">
<?= $content ?>
</main>
</div>

<footer class="df-runtime-footer">
DataForm Runtime Layout · HF69 · <?= e($version) ?><?php /* Compatibility marker: DataForm Runtime Layout · HF66 */ ?>
<?php if (!empty($help['location'])): ?> · <?= e((string)$help['location']) ?><?php endif; ?>
</footer>
<?= easyit_button_registry_data_tag() ?>
<?php $buttonRegistryJsFile = $projectRoot . '/assets/js/easyit-button-registry.js'; $buttonRegistryJsVersion = is_file($buttonRegistryJsFile) ? (string)filemtime($buttonRegistryJsFile) : '0'; ?>
<script src="<?= e(dataform_runtime_url('assets/js/easyit-button-registry.js')) ?>?v=<?= e($buttonRegistryJsVersion) ?>" defer></script>
</body>
</html>
<?php
}
