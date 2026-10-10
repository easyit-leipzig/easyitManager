<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Action;

final class ActionConfigCompiler
{
    public function __construct(private DataFormActionRegistry $registry) {}

    public function compile(ActionDraft $draft): array
    {
        $d = $draft->toArray();
        $enabled = [];
        foreach (($d['actions'] ?? []) as $id => $active) {
            if (!$active || !$this->registry->has((string) $id)) {
                continue;
            }
            $definition = $this->registry->get((string) $id);
            $enabled[$definition->getId()] = $definition->jsonSerialize();
        }

        return [
            'schema' => 'easyit.dataform.actions.v1',
            'dataForm' => (string) ($d['dataForm']['name'] ?? ''),
            'contextSchema' => 'easyit.dataform.action-context.v1',
            'buttonRegistry' => [
                'mode' => 'central',
                'metadataSource' => 'DataFormActionRegistry',
                'localTitleOverride' => false,
                'localAriaLabelOverride' => false,
                'cssBackgroundButtons' => false,
                'assetRoot' => 'assets/img/',
            ],
            'actions' => $enabled,
            'aliases' => $this->registry->aliases(),
            'rules' => [
                'showActionId' => 'show',
                'editActionId' => 'edit',
                'openAliasResolvesTo' => 'show',
                'createAliasResolvesTo' => 'new',
                'allActionsReceiveContext' => true,
            ],
        ];
    }
}
