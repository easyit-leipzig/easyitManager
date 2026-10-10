<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

final class AssistantManager
{
    public function __construct(private AssistantRegistry $registry) {}

    public function getRegistry(): AssistantRegistry
    {
        return $this->registry;
    }

    public function run(string $assistantId, AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        if (!$this->registry->has($assistantId)) {
            return AssistantResult::failure($assistantId, ['Unbekannter Assistent: ' . $assistantId]);
        }

        try {
            return $this->registry->get($assistantId)->run($context, $stepId);
        } catch (\Throwable $e) {
            return AssistantResult::failure($assistantId, [$e->getMessage()]);
        }
    }
}
