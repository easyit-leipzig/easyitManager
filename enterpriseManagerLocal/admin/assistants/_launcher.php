<?php
declare(strict_types=1);

/**
 * Drop-in-Partial für bestehende Admin-/DataForm-Seiten.
 * Optional vor require setzen:
 *   $assistantSurface = 'dataform.fields';
 *   $assistantContext = new \EasyIT\Assistant\AssistantContext(...);
 */
$root = dirname(__DIR__, 2);
/** @var \EasyIT\Assistant\AssistantManager $assistantManager */
$assistantManager = require $root . '/system/assistant/bootstrap.php';
$assistantContext = isset($assistantContext) && $assistantContext instanceof \EasyIT\Assistant\AssistantContext
    ? $assistantContext
    : \EasyIT\Assistant\AssistantContextFactory::fromGlobals();
$assistantSurface = \EasyIT\Assistant\Integration\AssistantSurfaceResolver::resolve(
    $assistantContext->getRoute(),
    isset($assistantSurface) ? (string) $assistantSurface : null
);
$assistantIntegrationRegistry = new \EasyIT\Assistant\Integration\AssistantIntegrationRegistry($assistantManager->getRegistry());
if ($assistantIntegrationRegistry->hasSurface($assistantSurface)) {
    echo (new \EasyIT\Assistant\Integration\AssistantLauncherRenderer($assistantIntegrationRegistry))->render($assistantSurface, $assistantContext);
}
