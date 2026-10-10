<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$context = \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$surface = \EasyIT\Assistant\Integration\AssistantSurfaceResolver::resolve($context->getRoute(), isset($_GET['surface']) ? (string) $_GET['surface'] : null);
$integration = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
$catalog = $integration->getCatalog();
$groups = $catalog->grouped();
$navigation = new \EasyIT\Assistant\Integration\AssistantPageNavigationRenderer($catalog, new \EasyIT\Assistant\Integration\AssistantUiActionRegistry());
$baseQuery = [];
if ($context->getProjectId()) { $baseQuery['project_id'] = $context->getProjectId(); }
if ($context->getDataFormId()) { $baseQuery['dataform_id'] = $context->getDataFormId(); }
if ($context->getRecordId()) { $baseQuery['record_id'] = $context->getRecordId(); }

function e31i(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>easyIT Enterprise – Assistentenzentrale</title>
    <link rel="stylesheet" href="../../assets/css/assistant.css">
</head>
<body data-eit-assistant-surface="assistant.center"<?= $context->getProjectId() ? ' data-project-id="' . e31i($context->getProjectId()) . '"' : '' ?><?= $context->getDataFormId() ? ' data-dataform-id="' . e31i($context->getDataFormId()) . '"' : '' ?>>
<main class="eit-assistant-page eit-assistant-center">
    <?= $navigation->render($context) ?>
    <header class="eit-assistant-header">
        <h1>Assistentenzentrale</h1>
        <p>Ein zentraler Einstieg für alle easyIT-Enterprise-/DataForm5-Assistenten. Die Kategorien, Surface-Zuordnungen und Kontextanforderungen stammen aus demselben zentralen Katalog.</p>
    </header>

    <section class="eit-assistant-context" aria-labelledby="assistant-context-title">
        <h2 id="assistant-context-title">Aktueller Kontext</h2>
        <dl>
            <div><dt>Projekt</dt><dd><?= e31i($context->getProjectId() ?? '–') ?></dd></div>
            <div><dt>DataForm</dt><dd><?= e31i($context->getDataFormId() ?? '–') ?></dd></div>
            <div><dt>Datensatz</dt><dd><?= e31i($context->getRecordId() ?? '–') ?></dd></div>
        </dl>
    </section>

    <section class="eit-assistant-work" aria-labelledby="assistant-contextual-title">
        <h2 id="assistant-contextual-title">Kontextbezogener Einstieg</h2>
        <?php $ctxAction = (new \EasyIT\Assistant\Integration\AssistantUiActionRegistry())->get('contextual'); ?>
        <a class="eit-assistant-link" data-button-key="<?= e31i($ctxAction['buttonKey']) ?>" title="<?= e31i($ctxAction['title']) ?>" aria-label="<?= e31i($ctxAction['ariaLabel']) ?>" href="contextual.php?<?= e31i(http_build_query(array_merge(['surface' => $surface], $baseQuery))) ?>">Passende Assistenten für <?= e31i($integration->getSurfaceTitle($surface)) ?> anzeigen</a>
    </section>

    <section class="eit-assistant-work" aria-labelledby="assistant-filter-title">
        <h2 id="assistant-filter-title">Assistent suchen</h2>
        <label class="eit-assistant-filter-label">Name oder Beschreibung
            <input id="eit-assistant-filter" type="search" autocomplete="off" placeholder="z. B. DataForm, Migration, Trust">
        </label>
    </section>

    <div class="eit-assistant-category-list">
    <?php foreach ($groups as $categoryId => $group): ?>
        <section class="eit-assistant-category" data-category="<?= e31i($categoryId) ?>" aria-labelledby="category-<?= e31i($categoryId) ?>">
            <header><h2 id="category-<?= e31i($categoryId) ?>"><?= e31i($group['title']) ?></h2><p><?= e31i($group['description']) ?></p></header>
            <div class="eit-assistant-grid">
            <?php foreach ($group['assistants'] as $entry): ?>
                <?php $query = array_merge(['assistant' => $entry['id']], $baseQuery); ?>
                <article class="eit-assistant-card" data-assistant-card data-search="<?= e31i(strtolower($entry['id'] . ' ' . $entry['title'] . ' ' . $entry['description'])) ?>">
                    <h3><?= e31i($entry['title']) ?></h3>
                    <p><?= e31i($entry['description']) ?></p>
                    <a class="eit-assistant-link" data-button-key="show" title="Assistent öffnen: <?= e31i($entry['title']) ?>" aria-label="Assistent öffnen: <?= e31i($entry['title']) ?>" href="run.php?<?= e31i(http_build_query($query)) ?>">Assistent öffnen</a>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
</main>
<script src="../../assets/js/assistant.js" defer></script>
<script src="../../assets/js/assistant-launcher.js" defer></script>
</body>
</html>
