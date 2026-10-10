<?php
declare(strict_types=1);

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantContextFactory;
use EasyIT\Assistant\Integration\AssistantIntegrationRegistry;
use EasyIT\Assistant\Integration\AssistantLauncherRenderer;
use EasyIT\Assistant\Integration\AssistantSurfaceResolver;

if (!function_exists('easyit_assistant_launcher')) {
    function easyit_assistant_launcher(?string $surface = null, ?AssistantContext $context = null): string
    {
        $root = dirname(__DIR__, 3);
        /** @var \EasyIT\Assistant\AssistantManager $manager */
        $manager = require $root . '/system/assistant/bootstrap.php';
        $context ??= AssistantContextFactory::fromGlobals();
        $surface = AssistantSurfaceResolver::resolve($context->getRoute(), $surface);
        $registry = new AssistantIntegrationRegistry($manager->getRegistry());
        return (new AssistantLauncherRenderer($registry))->render($surface, $context);
    }
}
