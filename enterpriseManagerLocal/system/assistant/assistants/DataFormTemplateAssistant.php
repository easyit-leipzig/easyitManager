<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Template\DataFormTemplateService;

final class DataFormTemplateAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore, private DataFormTemplateService $service) {}

    public function getId(): string { return 'dataform.templates'; }
    public function getTitle(): string { return 'DataForm-Vorlagen- und Klon-Assistent'; }
    public function getDescription(): string
    {
        return 'Speichert DataForm-, Feld-, Beziehungs-, Event- und Aktionskonfigurationen als Vorlage und überträgt sie mit Mapping, Konfliktprüfung und Diagnose auf ein Ziel-DataForm.';
    }

    public function getSteps(AssistantContext $context): array
    {
        $scope = $context->getProjectId() ?: 'global';
        $state = $this->stateStore->get($this->getId(), $scope);
        $operation = (string)($state['operation'] ?? $context->input('operation', 'clone'));
        $templateOptions = ['' => 'Bitte Vorlage auswählen'];
        foreach ($this->service->templates($context->getProjectId()) as $template) {
            $templateOptions[(string)$template['id']] = (string)$template['name'] . ' [' . (string)$template['sourceDataForm'] . ']';
        }

        $operationStep = new AssistantStep('operation', '1. Vorgang', 'Neue Vorlage erzeugen oder bestehende Vorlage auf ein DataForm klonen.', [
            'fields' => [[
                'name'=>'operation','label'=>'Vorgang','type'=>'select','required'=>true,
                'options'=>['clone'=>'Vorlage auf DataForm klonen','create'=>'Aktuelles DataForm als Vorlage speichern'],
            ]],
        ]);
        if ($operation === 'create') {
            return [
                $operationStep,
                new AssistantStep('source', '2. Quelle', 'Projekt und DataForm auswählen, deren aktueller persistenter Assistentenzustand in die Vorlage aufgenommen wird.', ['fields'=>[
                    ['name'=>'source_project','label'=>'Quellprojekt','type'=>'text','required'=>true],
                    ['name'=>'source_dataform','label'=>'Quell-DataForm','type'=>'text','required'=>true],
                ]]),
                new AssistantStep('metadata', '3. Vorlage', 'Name und Beschreibung der wiederverwendbaren Vorlage festlegen.', ['fields'=>[
                    ['name'=>'template_name','label'=>'Vorlagenname','type'=>'text','required'=>true],
                    ['name'=>'template_description','label'=>'Beschreibung','type'=>'textarea','rows'=>5],
                ]]),
                new AssistantStep('review', '4. Speichern', 'Vorlage aus dem aktuellen persistierten DataForm-Zustand erzeugen und Bestandteile prüfen.'),
            ];
        }
        return [
            $operationStep,
            new AssistantStep('select', '2. Vorlage auswählen', 'Eine gespeicherte DataForm-Vorlage auswählen.', ['fields'=>[
                ['name'=>'template_id','label'=>'Vorlage','type'=>'select','required'=>true,'options'=>$templateOptions],
            ]]),
            new AssistantStep('target', '3. Ziel', 'Zielprojekt und Ziel-DataForm sowie optionale neue Datenquellenreferenzen festlegen.', ['fields'=>[
                ['name'=>'target_project','label'=>'Zielprojekt','type'=>'text','required'=>true],
                ['name'=>'target_dataform','label'=>'Neuer DataForm-Name','type'=>'text','required'=>true],
                ['name'=>'target_profile','label'=>'Neues Datenquellenprofil (optional)','type'=>'text'],
                ['name'=>'target_source','label'=>'Neue Haupttabelle / View / CSV-Tabelle (optional)','type'=>'text'],
                ['name'=>'allow_overwrite','label'=>'Vorhandene Zielkonfiguration versioniert überschreiben','type'=>'checkbox'],
            ]]),
            new AssistantStep('mapping', '4. Referenzen neu zuordnen', 'Zusätzliche exakte Ersetzungen, eine Zeile pro Zuordnung: alterWert|neuerWert.', ['fields'=>[
                ['name'=>'mapping_lines','label'=>'Zusätzliches Mapping','type'=>'textarea','rows'=>8,'placeholder'=>"ed_ev_info|ed_customer_info\nproject-main|customer-main"],
            ]]),
            new AssistantStep('preview', '5. Diagnose vor Übernahme', 'Zielkonflikte, DataForm, Felder, Beziehungen, Events und Aktionen validieren. Es wird noch nichts geschrieben.'),
            new AssistantStep('apply', '6. Übernehmen', 'Nur nach erfolgreicher Vorschau die gemappte Vorlage in das Ziel schreiben. Jede Änderung wird über die Historie versioniert.', ['fields'=>[
                ['name'=>'confirm_apply','label'=>'Diagnose geprüft – Vorlage jetzt anwenden','type'=>'checkbox'],
            ]]),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $scope = $context->getProjectId() ?: 'global';
        if ($this->boolInput($context, 'reset')) { $this->stateStore->clear($this->getId(), $scope); }
        $state = $this->stateStore->get($this->getId(), $scope);
        $stepId = $stepId ?: 'operation';
        $method = strtoupper((string)$context->meta('requestMethod', 'GET'));

        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $state = $this->applyInput($state, $context, $stepId);
            $this->stateStore->put($this->getId(), $state, $scope);
        }
        $steps = $this->getSteps($context);
        $ids = array_map(static fn(AssistantStep $s): string => $s->getId(), $steps);
        if (!in_array($stepId, $ids, true)) { $stepId = $ids[0]; }

        $errors = [];
        $warnings = [];
        $operation = (string)($state['operation'] ?? 'clone');
        $data = [
            'phase'=>15,
            'formValues'=>$this->formValues($state, $stepId, $context),
            'nextStep'=>$this->nextStep($ids, $stepId),
            'previousStep'=>$this->previousStep($ids, $stepId),
            'templates'=>$this->service->templates($context->getProjectId()),
            'operation'=>$operation,
        ];

        try {
            if ($operation === 'create') {
                if ($method === 'POST' || $stepId === 'review') { $this->validateCreate($state, $stepId, $errors); }
                if ($stepId === 'metadata' && $method === 'POST' && $errors === [] && empty($state['createdTemplateId'])) {
                    $template = $this->service->capture(
                        (string)($state['templateName'] ?? ''), (string)($state['templateDescription'] ?? ''),
                        (string)($state['sourceProject'] ?? ''), (string)($state['sourceDataForm'] ?? '')
                    );
                    $state['createdTemplateId'] = (string)$template['id'];
                    $this->stateStore->put($this->getId(), $state, $scope);
                    $data['createdTemplate'] = $template;
                }
                if ($stepId === 'review' && $errors === []) {
                    $template = !empty($state['createdTemplateId']) ? $this->service->getTemplate((string)$state['createdTemplateId'], $context->getProjectId()) : [];
                    if ($template === []) { $errors[] = 'Vorlage wurde noch nicht gespeichert. Bitte den Metadaten-Schritt mit „Speichern und weiter“ abschließen.'; }
                    else { $data['createdTemplate'] = $template; $data['configurationReady'] = true; }
                }
            } else {
                if ($method === 'POST' || in_array($stepId, ['preview','apply'], true)) { $this->validateClone($state, $stepId, $errors); }
                if (in_array($stepId, ['preview','apply'], true) && $errors === []) {
                    $mapping = $this->mapping($state, $context->getProjectId());
                    $preview = $this->service->preview(
                        (string)($state['templateId'] ?? ''), (string)($state['targetProject'] ?? ''), (string)($state['targetDataForm'] ?? ''),
                        $mapping, !empty($state['allowOverwrite']), $context->getProjectId()
                    );
                    $data['templatePreview'] = $preview;
                    $warnings = array_merge($warnings, (array)($preview['warnings'] ?? []));
                    if (($preview['verdict'] ?? '') === 'FAIL') { $errors = array_merge($errors, (array)($preview['errors'] ?? [])); }
                    if ($stepId === 'apply' && $method === 'POST' && !empty($state['confirmApply']) && $errors === []) {
                        $apply = $this->service->apply(
                            (string)$state['templateId'], (string)$state['targetProject'], (string)$state['targetDataForm'], $mapping, !empty($state['allowOverwrite']), $context->getProjectId()
                        );
                        $data['templateApplyResult'] = $apply;
                        $data['configurationReady'] = !empty($apply['applied']);
                        if (!empty($apply['applied'])) {
                            $data['handoffUrl'] = 'run.php?' . http_build_query([
                                'assistant'=>'dataform.diagnostics','project_id'=>$state['targetProject'],'dataform_id'=>$state['targetDataForm'],'step'=>'run'
                            ]);
                            $data['handoffLabel'] = 'Geklontes DataForm jetzt vollständig diagnostizieren';
                        }
                    } elseif ($stepId === 'apply' && $method === 'POST' && empty($state['confirmApply'])) {
                        $errors[] = 'Die Übernahme muss ausdrücklich bestätigt werden.';
                    }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $i => $step) {
            $stateful[] = $step->withState($i < $currentIndex ? AssistantStep::STATE_COMPLETE : ($i === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING));
        }
        if ($errors !== []) { return AssistantResult::failure($this->getId(), array_values(array_unique($errors)), $stepId, $stateful, $data); }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, array_values(array_unique($warnings)));
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function applyInput(array $state, AssistantContext $context, string $step): array
    {
        return match ($step) {
            'operation' => ['operation'=>(string)$context->input('operation','clone')],
            default => $this->mergeStep($state, $context, $step),
        };
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function mergeStep(array $state, AssistantContext $context, string $step): array
    {
        if ($step === 'source') {
            $state['sourceProject'] = trim((string)$context->input('source_project',''));
            $state['sourceDataForm'] = trim((string)$context->input('source_dataform',''));
        } elseif ($step === 'metadata') {
            $state['templateName'] = trim((string)$context->input('template_name',''));
            $state['templateDescription'] = trim((string)$context->input('template_description',''));
            unset($state['createdTemplateId']);
        } elseif ($step === 'select') {
            $state['templateId'] = trim((string)$context->input('template_id',''));
        } elseif ($step === 'target') {
            $state['targetProject'] = trim((string)$context->input('target_project',''));
            $state['targetDataForm'] = trim((string)$context->input('target_dataform',''));
            $state['targetProfile'] = trim((string)$context->input('target_profile',''));
            $state['targetSource'] = trim((string)$context->input('target_source',''));
            $state['allowOverwrite'] = $this->boolInput($context, 'allow_overwrite');
        } elseif ($step === 'mapping') {
            $state['mappingLines'] = trim((string)$context->input('mapping_lines',''));
        } elseif ($step === 'apply') {
            $state['confirmApply'] = $this->boolInput($context, 'confirm_apply');
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param list<string> $errors */
    private function validateCreate(array $state, string $step, array &$errors): void
    {
        if (in_array($step, ['source','metadata','review'], true)) {
            if (trim((string)($state['sourceProject'] ?? '')) === '') { $errors[] = 'Quellprojekt fehlt.'; }
            if (trim((string)($state['sourceDataForm'] ?? '')) === '') { $errors[] = 'Quell-DataForm fehlt.'; }
        }
        if (in_array($step, ['metadata','review'], true) && trim((string)($state['templateName'] ?? '')) === '') { $errors[] = 'Vorlagenname fehlt.'; }
    }

    /** @param array<string,mixed> $state @param list<string> $errors */
    private function validateClone(array $state, string $step, array &$errors): void
    {
        if (in_array($step, ['select','target','mapping','preview','apply'], true) && trim((string)($state['templateId'] ?? '')) === '') { $errors[] = 'Vorlage fehlt.'; }
        if (in_array($step, ['target','mapping','preview','apply'], true)) {
            if (trim((string)($state['targetProject'] ?? '')) === '') { $errors[] = 'Zielprojekt fehlt.'; }
            if (trim((string)($state['targetDataForm'] ?? '')) === '') { $errors[] = 'Ziel-DataForm fehlt.'; }
        }
    }

    /** @param array<string,mixed> $state @return array<string,string> */
    private function mapping(array $state, ?string $libraryProjectId = null): array
    {
        $mapping = [];
        $template = $this->service->getTemplate((string)($state['templateId'] ?? ''), $libraryProjectId);
        $df = is_array($template['bundle']['dataform.create'] ?? null) ? $template['bundle']['dataform.create'] : [];
        $oldProfile = trim((string)($df['source']['profile'] ?? ''));
        $oldSource = trim((string)($df['source']['name'] ?? ''));
        if ($oldProfile !== '' && trim((string)($state['targetProfile'] ?? '')) !== '') { $mapping[$oldProfile] = trim((string)$state['targetProfile']); }
        if ($oldSource !== '' && trim((string)($state['targetSource'] ?? '')) !== '') { $mapping[$oldSource] = trim((string)$state['targetSource']); }
        foreach (preg_split('/\R/', (string)($state['mappingLines'] ?? '')) ?: [] as $line) {
            $line = trim($line); if ($line === '') { continue; }
            $parts = array_map('trim', explode('|', $line, 2));
            if (($parts[0] ?? '') !== '' && array_key_exists(1, $parts)) { $mapping[$parts[0]] = $parts[1]; }
        }
        return $mapping;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function formValues(array $state, string $step, AssistantContext $context): array
    {
        return match ($step) {
            'operation' => ['operation'=>$state['operation'] ?? 'clone'],
            'source' => ['source_project'=>$state['sourceProject'] ?? ($context->getProjectId() ?? ''),'source_dataform'=>$state['sourceDataForm'] ?? ($context->getDataFormId() ?? '')],
            'metadata' => ['template_name'=>$state['templateName'] ?? '','template_description'=>$state['templateDescription'] ?? ''],
            'select' => ['template_id'=>$state['templateId'] ?? ''],
            'target' => ['target_project'=>$state['targetProject'] ?? ($context->getProjectId() ?? ''),'target_dataform'=>$state['targetDataForm'] ?? '','target_profile'=>$state['targetProfile'] ?? '','target_source'=>$state['targetSource'] ?? '','allow_overwrite'=>!empty($state['allowOverwrite'])],
            'mapping' => ['mapping_lines'=>$state['mappingLines'] ?? ''],
            'apply' => ['confirm_apply'=>!empty($state['confirmApply'])],
            default => [],
        };
    }

    private function boolInput(AssistantContext $context, string $key): bool { return in_array($context->input($key, false), [true,1,'1','true','yes','on'], true); }
    private function nextStep(array $ids, string $current): ?string { $i=array_search($current,$ids,true); return $i!==false && isset($ids[$i+1]) ? $ids[$i+1] : null; }
    private function previousStep(array $ids, string $current): ?string { $i=array_search($current,$ids,true); return $i!==false && $i>0 ? $ids[$i-1] : null; }
}
