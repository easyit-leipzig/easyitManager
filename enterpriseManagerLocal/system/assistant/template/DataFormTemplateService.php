<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Template;

use EasyIT\Assistant\Action\ActionDraft;
use EasyIT\Assistant\Action\ActionDraftValidator;
use EasyIT\Assistant\Action\DataFormActionRegistry;
use EasyIT\Assistant\DataForm\DataFormDraft;
use EasyIT\Assistant\DataForm\DataFormDraftValidator;
use EasyIT\Assistant\Event\EventDraft;
use EasyIT\Assistant\Event\EventDraftValidator;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\Relation\RelationDraft;
use EasyIT\Assistant\Relation\RelationDraftValidator;
use EasyIT\Assistant\State\AssistantStateStore;

final class DataFormTemplateService
{
    /** @var list<string> */
    private const PARTS = ['dataform.create','dataform.fields','dataform.relations','dataform.events','dataform.actions'];

    public function __construct(
        private AssistantStateStore $store,
        private DataFormTemplateStore $templates,
        private DataFormTemplateMapper $mapper,
        private FieldTypeRegistry $fieldTypes,
        private DataFormActionRegistry $actions,
        private string $projectRoot
    ) { $this->projectRoot = rtrim($projectRoot, '/\\'); }

    /** @return array<string,mixed> */
    public function capture(string $name, string $description, string $sourceProject, string $sourceDataForm): array
    {
        $this->assertProject($sourceProject);
        if ($sourceDataForm === '') { throw new \InvalidArgumentException('Quell-DataForm fehlt.'); }
        $bundle = [];
        foreach (self::PARTS as $assistantId) {
            $scope = $assistantId === 'dataform.create' ? $sourceProject : $sourceProject . '|' . $sourceDataForm;
            $state = $this->store->getForProject($assistantId, $sourceProject, $scope);
            if ($state !== []) { $bundle[$assistantId] = $state; }
        }
        if (empty($bundle['dataform.create'])) { throw new \RuntimeException('Für das Quellprojekt ist keine DataForm-Konfiguration gespeichert.'); }
        $actualName = trim((string)($bundle['dataform.create']['identity']['dataFormName'] ?? ''));
        if ($actualName !== '' && $actualName !== $sourceDataForm) {
            throw new \RuntimeException('Die gespeicherte DataForm-Konfiguration gehört zu "' . $actualName . '" und nicht zu "' . $sourceDataForm . '".');
        }
        $id = $this->templates->makeId($name);
        return $this->templates->save([
            'id' => $id,
            'name' => trim($name) !== '' ? trim($name) : $sourceDataForm,
            'description' => trim($description),
            'source' => ['projectId' => $sourceProject, 'dataFormId' => $sourceDataForm],
            'bundle' => $bundle,
        ]);
    }

    /** @param array<string,string> $mapping @return array<string,mixed> */
    public function preview(string $templateId, string $targetProject, string $targetDataForm, array $mapping = [], bool $allowOverwrite = false, ?string $libraryProjectId = null): array
    {
        $this->assertProject($targetProject);
        if ($targetDataForm === '') { return $this->report(['Ziel-DataForm fehlt.']); }
        $template = $this->templates->get($templateId, $libraryProjectId);
        if ($template === []) { return $this->report(['Vorlage wurde nicht gefunden.']); }
        $mapped = $this->mapper->map($template, $targetDataForm, $mapping);
        $errors = [];
        $warnings = [];
        $checks = [];

        $existing = [];
        foreach (self::PARTS as $assistantId) {
            $scope = $assistantId === 'dataform.create' ? $targetProject : $targetProject . '|' . $targetDataForm;
            $state = $this->store->getForProject($assistantId, $targetProject, $scope);
            if ($state !== []) { $existing[] = $assistantId; }
        }
        if ($existing !== [] && !$allowOverwrite) {
            $errors[] = 'Am Ziel existieren bereits Konfigurationen: ' . implode(', ', $existing) . '. Überschreiben muss ausdrücklich erlaubt werden.';
        } elseif ($existing !== []) {
            $warnings[] = 'Vorhandene Zielkonfigurationen werden versioniert überschrieben: ' . implode(', ', $existing) . '.';
        }
        $checks[] = ['id'=>'target.conflicts','status'=>$existing === [] ? 'PASS' : ($allowOverwrite ? 'WARN' : 'FAIL'),'details'=>['existing'=>$existing]];

        if (isset($mapped['dataform.create'])) {
            $v = (new DataFormDraftValidator())->validate(new DataFormDraft($mapped['dataform.create']), null);
            $this->mergeValidation('dataform', $v, $errors, $warnings, $checks);
        } else { $errors[] = 'Vorlage enthält keine DataForm-Konfiguration.'; }
        if (isset($mapped['dataform.fields'])) {
            $v = (new FieldDraftValidator($this->fieldTypes))->validate(new FieldDraft($mapped['dataform.fields']), null);
            $this->mergeValidation('fields', $v, $errors, $warnings, $checks);
        }
        if (isset($mapped['dataform.relations'])) {
            $v = (new RelationDraftValidator())->validate(new RelationDraft($mapped['dataform.relations']), null);
            $this->mergeValidation('relations', $v, $errors, $warnings, $checks);
        }
        if (isset($mapped['dataform.events'])) {
            $v = (new EventDraftValidator())->validate(new EventDraft($mapped['dataform.events']), null);
            $this->mergeValidation('events', $v, $errors, $warnings, $checks);
        }
        if (isset($mapped['dataform.actions'])) {
            $v = (new ActionDraftValidator($this->actions))->validate(new ActionDraft($mapped['dataform.actions']), null);
            $this->mergeValidation('actions', $v, $errors, $warnings, $checks);
        }

        return $this->report($errors, $warnings, $checks, $mapped, [
            'templateId'=>$templateId,'targetProject'=>$targetProject,'targetDataForm'=>$targetDataForm,'mapping'=>$mapping,'allowOverwrite'=>$allowOverwrite
        ]);
    }

    /** @param array<string,string> $mapping @return array<string,mixed> */
    public function apply(string $templateId, string $targetProject, string $targetDataForm, array $mapping = [], bool $allowOverwrite = false, ?string $libraryProjectId = null): array
    {
        $preview = $this->preview($templateId, $targetProject, $targetDataForm, $mapping, $allowOverwrite, $libraryProjectId);
        if (($preview['verdict'] ?? '') === 'FAIL') { return $preview + ['applied'=>false]; }
        foreach (($preview['mappedBundle'] ?? []) as $assistantId => $state) {
            if (!is_array($state)) { continue; }
            $scope = $assistantId === 'dataform.create' ? $targetProject : $targetProject . '|' . $targetDataForm;
            $this->store->putForProject($assistantId, $targetProject, $state, $scope);
        }
        return $preview + ['applied'=>true, 'appliedAt'=>gmdate('c')];
    }

    /** @return list<array<string,mixed>> */
    public function templates(?string $projectId = null): array { return $this->templates->all($projectId); }
    public function getTemplate(string $id, ?string $projectId = null): array { return $this->templates->get($id, $projectId); }

    /** @param array{errors:list<string>,warnings:list<string>} $validation @param list<string> $errors @param list<string> $warnings @param list<array<string,mixed>> $checks */
    private function mergeValidation(string $id, array $validation, array &$errors, array &$warnings, array &$checks): void
    {
        foreach ($validation['errors'] as $error) { $errors[] = $id . ': ' . $error; }
        foreach ($validation['warnings'] as $warning) { $warnings[] = $id . ': ' . $warning; }
        $checks[] = ['id'=>$id . '.validation','status'=>$validation['errors'] === [] ? ($validation['warnings'] === [] ? 'PASS' : 'WARN') : 'FAIL','errors'=>$validation['errors'],'warnings'=>$validation['warnings']];
    }

    /** @param list<string> $errors @param list<string> $warnings @param list<array<string,mixed>> $checks @param array<string,mixed> $mappedBundle @param array<string,mixed> $target */
    private function report(array $errors = [], array $warnings = [], array $checks = [], array $mappedBundle = [], array $target = []): array
    {
        return [
            'schema'=>'easyit.assistant.template-preview.v1',
            'verdict'=>$errors !== [] ? 'FAIL' : ($warnings !== [] ? 'PASS_WITH_WARNINGS' : 'PASS'),
            'errors'=>$errors,'warnings'=>$warnings,'checks'=>$checks,'mappedBundle'=>$mappedBundle,'target'=>$target,
        ];
    }

    private function assertProject(string $projectId): void
    {
        if ($projectId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId) || !is_dir($this->projectRoot . '/projects/' . $projectId)) {
            throw new \InvalidArgumentException('Projekt existiert nicht oder Projekt-ID ist ungültig: ' . $projectId);
        }
    }
}
