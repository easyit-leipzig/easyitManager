<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;

final class CoreStatusAssistant implements AssistantInterface
{
    public function getId(): string { return 'core.status'; }
    public function getTitle(): string { return 'Assistant-Systemstatus'; }
    public function getDescription(): string { return 'Prüft den Assistant-Core und zeigt den aktuellen Projekt-/DataForm-Kontext.'; }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('context', 'Kontext erfassen', 'Projekt, DataForm, Datensatz und Route werden aus dem aktuellen Aufruf übernommen.'),
            new AssistantStep('core', 'Assistant-Core prüfen', 'Registry, Manager, Zustandsablage und Ergebnisobjekte werden geprüft.'),
            new AssistantStep('ready', 'Fachassistenten bereit', 'DataForm-, Beziehungs- und Event-Assistent sind registriert.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $steps = $this->getSteps($context);
        $validIds = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        $stepId = $stepId ?: 'context';

        if (!in_array($stepId, $validIds, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $currentIndex = array_search($stepId, $validIds, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $state = $index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING);
            $stateful[] = $step->withState($state);
        }

        return AssistantResult::success($this->getId(), $stepId, $stateful, [
            'phase' => 4,
            'coreReady' => true,
            'context' => $context,
            'registeredFeatureAssistants' => ['dataform.create', 'dataform.relations', 'dataform.events'],
            'nextPhase' => 'Aktions- und Button-Assistent',
        ]);
    }
}
