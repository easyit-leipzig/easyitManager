<?php
declare(strict_types=1);

namespace EasyIT\Assistant;

interface AssistantInterface
{
    public function getId(): string;
    public function getTitle(): string;
    public function getDescription(): string;

    /** @return list<AssistantStep> */
    public function getSteps(AssistantContext $context): array;

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult;
}
