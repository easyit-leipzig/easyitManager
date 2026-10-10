<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$surface = \EasyIT\Assistant\Integration\AssistantSurfaceResolver::resolve($context->getRoute(), isset($_GET['surface']) ? (string) $_GET['surface'] : null);
$integration = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
if (!$integration->hasSurface($surface)) { http_response_code(400); $surface = 'assistant.center'; }
$entries = $integration->entries($surface, $context, 'run.php');
$catalog = $integration->getCatalog();
$navigation = new \EasyIT\Assistant\Integration\AssistantPageNavigationRenderer($catalog, new \EasyIT\Assistant\Integration\AssistantUiActionRegistry());
function e31c(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>easyIT Enterprise – Kontext-Assistenten</title>
<link rel="stylesheet" href="../../assets/css/assistant.css">
</head>
<body data-eit-assistant-surface="<?= e31c($surface) ?>">
<main class="eit-assistant-page">
<?= $navigation->render($context) ?>
<header class="eit-assistant-header">
<h1>Assistenten – <?= e31c($integration->getSurfaceTitle($surface)) ?></h1>
<p>Es werden ausschließlich die für diese Oberfläche zentral registrierten Assistenten angeboten. Projekt-, DataForm- und Datensatzkontext werden automatisch weitergegeben.</p>
</header>
<section class="eit-assistant-context" aria-label="Aktueller Kontext"><dl>
<div><dt>Oberfläche</dt><dd><?= e31c($surface) ?></dd></div>
<div><dt>Projekt</dt><dd><?= e31c($context->getProjectId() ?? '–') ?></dd></div>
<div><dt>DataForm</dt><dd><?= e31c($context->getDataFormId() ?? '–') ?></dd></div>
<div><dt>Datensatz</dt><dd><?= e31c($context->getRecordId() ?? '–') ?></dd></div>
</dl></section>
<section class="eit-assistant-grid" aria-label="Passende Assistenten">
<?php foreach ($entries as $entry): ?>
<article class="eit-assistant-card" data-available="<?= $entry['available'] ? '1' : '0' ?>">
<h2><?= e31c($entry['title']) ?></h2><p><?= e31c($entry['description']) ?></p>
<?php if ($entry['available']): ?>
<a class="eit-assistant-link" data-button-key="<?= e31c($entry['buttonKey']) ?>" title="<?= e31c($entry['linkTitle']) ?>" aria-label="<?= e31c($entry['ariaLabel']) ?>" href="<?= e31c($entry['url']) ?>">Assistent öffnen</a>
<?php else: ?>
<p class="eit-assistant-unavailable">Nicht direkt startbar. Fehlender Kontext: <?= e31c(implode(', ', $entry['missing'])) ?></p>
<?php endif; ?>
</article>
<?php endforeach; ?>
</section>
</main>
<script src="../../assets/js/assistant.js" defer></script>
<script src="../../assets/js/assistant-launcher.js" defer></script>
</body></html>
