<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Template\DataFormTemplateLibraryService;

final class DataFormTemplateLibraryAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore, private DataFormTemplateLibraryService $library) {}

    public function getId(): string { return 'dataform.template-library'; }
    public function getTitle(): string { return 'DataForm-Vorlagenbibliothek'; }
    public function getDescription(): string
    {
        return 'Verwaltet systemweite und projektbezogene DataForm-Vorlagen: anzeigen, versionieren, importieren/exportieren, umbenennen, kopieren, freigeben und kontrolliert löschen.';
    }

    public function getSteps(AssistantContext $context): array
    {
        $scope = $context->getProjectId() ?: 'global';
        $state = $this->stateStore->get($this->getId(), $scope);
        $operation = (string)($state['operation'] ?? $context->input('operation', 'browse'));
        $options = [''=>'Bitte Vorlage auswählen'];
        foreach ($this->library->list($context->getProjectId()) as $item) {
            $visibility = (string)($item['visibility'] ?? 'system');
            $owner = (string)($item['ownerProject'] ?? '');
            $locator = $visibility === 'project' ? 'project::' . $owner . '::' . $item['id'] : 'system::' . $item['id'];
            $scopeLabel = $visibility === 'project' ? 'Projekt ' . $owner : 'System';
            $options[$locator] = (string)$item['name'] . ' [' . $scopeLabel . ']';
        }
        $steps = [
            new AssistantStep('operation', '1. Bibliotheksvorgang', 'Verwaltungsaktion auswählen.', ['fields'=>[[
                'name'=>'operation','label'=>'Vorgang','type'=>'select','required'=>true,'options'=>[
                    'browse'=>'Bibliothek anzeigen','history'=>'Versionsverlauf anzeigen','rename'=>'Vorlage umbenennen/beschreiben',
                    'copy'=>'Vorlage kopieren','visibility'=>'Freigabe ändern','import'=>'Vorlage importieren',
                    'export'=>'Vorlage exportieren','delete'=>'Vorlage kontrolliert löschen',
                ],
            ]]]),
        ];
        if ($operation === 'browse') { $steps[] = new AssistantStep('result','2. Bibliothek','Alle im aktuellen Kontext sichtbaren Vorlagen anzeigen.'); return $steps; }
        if ($operation === 'import') {
            $steps[] = new AssistantStep('configure','2. Import','JSON-Datei oder JSON-Text importieren. Secrets werden vor dem Speichern entfernt.', ['fields'=>[
                ['name'=>'import_file','label'=>'Vorlagen-JSON-Datei','type'=>'file','accept'=>'.json,application/json'],
                ['name'=>'import_json','label'=>'Alternativ JSON-Text','type'=>'textarea','rows'=>8],
                ['name'=>'new_name','label'=>'Name überschreiben (optional)','type'=>'text'],
                ['name'=>'target_visibility','label'=>'Freigabe','type'=>'select','required'=>true,'options'=>['project'=>'Nur aktuelles Projekt','system'=>'Systemweit']],
                ['name'=>'target_project','label'=>'Projekt bei projektbezogener Freigabe','type'=>'text'],
            ]]);
            $steps[] = new AssistantStep('result','3. Importergebnis','Import prüfen und Ergebnis anzeigen.');
            return $steps;
        }
        $steps[] = new AssistantStep('select','2. Vorlage auswählen','Vorlage aus der sichtbaren Bibliothek auswählen.', ['fields'=>[[
            'name'=>'template_locator','label'=>'Vorlage','type'=>'select','required'=>true,'options'=>$options,
        ]]]);
        if ($operation === 'rename') {
            $steps[] = new AssistantStep('configure','3. Metadaten','Anzeigename und Beschreibung ändern.', ['fields'=>[
                ['name'=>'new_name','label'=>'Neuer Vorlagenname','type'=>'text','required'=>true],
                ['name'=>'new_description','label'=>'Beschreibung','type'=>'textarea','rows'=>5],
            ]]);
        } elseif ($operation === 'copy') {
            $steps[] = new AssistantStep('configure','3. Kopie','Neue Vorlage und Zielbereich festlegen.', ['fields'=>[
                ['name'=>'new_name','label'=>'Name der Kopie','type'=>'text','required'=>true],
                ['name'=>'target_visibility','label'=>'Freigabe der Kopie','type'=>'select','required'=>true,'options'=>['project'=>'Projektbezogen','system'=>'Systemweit']],
                ['name'=>'target_project','label'=>'Zielprojekt','type'=>'text'],
            ]]);
        } elseif ($operation === 'visibility') {
            $steps[] = new AssistantStep('configure','3. Freigabe','Vorlage zwischen projektbezogen und systemweit verschieben.', ['fields'=>[
                ['name'=>'target_visibility','label'=>'Neue Freigabe','type'=>'select','required'=>true,'options'=>['project'=>'Projektbezogen','system'=>'Systemweit']],
                ['name'=>'target_project','label'=>'Zielprojekt','type'=>'text'],
            ]]);
        } elseif ($operation === 'delete') {
            $steps[] = new AssistantStep('configure','3. Löschen bestätigen','Die Vorlage wird zunächst nur in den Vorlagen-Papierkorb verschoben.', ['fields'=>[
                ['name'=>'delete_confirm_name','label'=>'Vorlagenname zur Bestätigung exakt eingeben','type'=>'text','required'=>true],
                ['name'=>'confirm_delete','label'=>'Vorlage jetzt in den Papierkorb verschieben','type'=>'checkbox'],
            ]]);
        }
        $number = count($steps) + 1;
        $steps[] = new AssistantStep('result', $number . '. Ergebnis', 'Vorgang ausführen bzw. Ergebnis und Versionsverlauf anzeigen.');
        return $steps;
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $scope = $context->getProjectId() ?: 'global';
        if ($this->boolInput($context, 'reset')) { $this->stateStore->clear($this->getId(), $scope); }
        $state = $this->stateStore->get($this->getId(), $scope);
        $stepId = $stepId ?: 'operation';
        $method = strtoupper((string)$context->meta('requestMethod', 'GET'));
        $preErrors = [];
        $preData = [];
        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $state = $this->mergeInput($state, $context, $stepId);
            if (($state['operation'] ?? '') === 'import' && $stepId === 'configure' && empty($state['importedTemplate'])) {
                try {
                    $json = $this->readImportJson($context);
                    if ($json === '') { throw new \RuntimeException('Keine Importdatei oder kein JSON-Text angegeben.'); }
                    [$targetVisibility,$targetProject] = $this->targetScope($state, $context);
                    $imported = $this->library->importJson($json,$targetVisibility,$targetProject,(string)($state['newName'] ?? ''));
                    $state['importedTemplate'] = $imported;
                    $preData['templateLibraryResult'] = ['action'=>'import','template'=>$imported];
                } catch (\Throwable $e) { $preErrors[] = $e->getMessage(); }
            }
            $this->stateStore->put($this->getId(), $state, $scope);
        }
        $steps = $this->getSteps($context);
        $ids = array_map(static fn(AssistantStep $s): string => $s->getId(), $steps);
        if (!in_array($stepId, $ids, true)) { $stepId = $ids[0]; }
        $errors = $preErrors;
        $warnings = [];
        $operation = (string)($state['operation'] ?? 'browse');
        $data = $preData + [
            'phase'=>16,'operation'=>$operation,'templateLibrary'=>$this->library->list($context->getProjectId()),
            'formValues'=>$this->formValues($state, $stepId, $context),
            'nextStep'=>$this->nextStep($ids,$stepId),'previousStep'=>$this->previousStep($ids,$stepId),
        ];
        try {
            if ($operation !== 'browse' && $operation !== 'import' && in_array($stepId, ['select','configure','result'], true)) {
                if (trim((string)($state['templateLocator'] ?? '')) === '') { $errors[] = 'Vorlage fehlt.'; }
            }
            if ($stepId === 'result' && $errors === []) {
                if ($operation === 'browse') {
                    $data['configurationReady'] = true;
                } elseif ($operation === 'import') {
                    $result = is_array($state['importedTemplate'] ?? null) ? $state['importedTemplate'] : [];
                    if ($result === []) { $errors[] = 'Import wurde noch nicht erfolgreich ausgeführt.'; }
                    else { $data['templateLibraryResult'] = ['action'=>'import','template'=>$result]; $data['configurationReady'] = true; }
                } else {
                    [$visibility,$ownerProject,$id] = $this->parseLocator((string)$state['templateLocator']);
                    $selected = $this->library->get($id, $ownerProject ?: $context->getProjectId());
                    if ($selected === []) { throw new \RuntimeException('Ausgewählte Vorlage wurde nicht gefunden.'); }
                    $data['selectedTemplate'] = $selected;
                    if ($operation === 'history') {
                        $data['templateHistory'] = $this->library->history($id,$visibility,$ownerProject);
                    } elseif ($operation === 'export') {
                        $query = ['id'=>$id,'visibility'=>$visibility];
                        if ($ownerProject) { $query['project_id']=$ownerProject; }
                        $data['templateExportUrl'] = 'template-export.php?' . http_build_query($query);
                    } elseif ($operation === 'rename') {
                        $result = $this->library->rename($id,(string)($state['newName'] ?? ''),(string)($state['newDescription'] ?? ''),$visibility,$ownerProject);
                        $data['templateLibraryResult'] = ['action'=>'rename','template'=>$result];
                    } elseif ($operation === 'copy') {
                        [$targetVisibility,$targetProject] = $this->targetScope($state,$context);
                        $result = $this->library->copy($id,(string)($state['newName'] ?? ''),$visibility,$ownerProject,$targetVisibility,$targetProject);
                        $data['templateLibraryResult'] = ['action'=>'copy','template'=>$result];
                    } elseif ($operation === 'visibility') {
                        [$targetVisibility,$targetProject] = $this->targetScope($state,$context);
                        $result = $this->library->changeVisibility($id,$visibility,$ownerProject,$targetVisibility,$targetProject);
                        $data['templateLibraryResult'] = ['action'=>'visibility','template'=>$result];
                    } elseif ($operation === 'delete') {
                        if (empty($state['confirmDelete'])) { throw new \RuntimeException('Löschen wurde nicht ausdrücklich bestätigt.'); }
                        $data['templateLibraryResult'] = ['action'=>'delete'] + $this->library->softDelete($id,$visibility,$ownerProject,(string)($state['deleteConfirmName'] ?? ''));
                    }
                    $data['configurationReady'] = true;
                }
            }
        } catch (\Throwable $e) { $errors[] = $e->getMessage(); }

        $currentIndex = array_search($stepId,$ids,true);
        $stateful=[];
        foreach ($steps as $i=>$step) { $stateful[]=$step->withState($i<$currentIndex?AssistantStep::STATE_COMPLETE:($i===$currentIndex?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING)); }
        if ($errors !== []) { return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data); }
        return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function mergeInput(array $state, AssistantContext $context, string $step): array
    {
        if ($step === 'operation') { return ['operation'=>(string)$context->input('operation','browse')]; }
        if ($step === 'select') { $state['templateLocator']=trim((string)$context->input('template_locator','')); }
        if ($step === 'configure') {
            $state['newName']=trim((string)$context->input('new_name',$state['newName'] ?? ''));
            $state['newDescription']=trim((string)$context->input('new_description',$state['newDescription'] ?? ''));
            $state['targetVisibility']=(string)$context->input('target_visibility',$state['targetVisibility'] ?? 'project');
            $state['targetProject']=trim((string)$context->input('target_project',$state['targetProject'] ?? ($context->getProjectId() ?? '')));
            $state['deleteConfirmName']=trim((string)$context->input('delete_confirm_name',''));
            $state['confirmDelete']=$this->boolInput($context,'confirm_delete');
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function formValues(array $state,string $step,AssistantContext $context): array
    {
        return match($step) {
            'operation'=>['operation'=>$state['operation'] ?? 'browse'],
            'select'=>['template_locator'=>$state['templateLocator'] ?? ''],
            'configure'=>[
                'new_name'=>$state['newName'] ?? '', 'new_description'=>$state['newDescription'] ?? '',
                'target_visibility'=>$state['targetVisibility'] ?? 'project','target_project'=>$state['targetProject'] ?? ($context->getProjectId() ?? ''),
                'delete_confirm_name'=>$state['deleteConfirmName'] ?? '','confirm_delete'=>!empty($state['confirmDelete']),'import_json'=>'',
            ], default=>[],
        };
    }

    /** @param array<string,mixed> $state @return array{0:string,1:?string} */
    private function targetScope(array $state, AssistantContext $context): array
    {
        $visibility=(string)($state['targetVisibility'] ?? 'project');
        if (!in_array($visibility,['system','project'],true)) { throw new \InvalidArgumentException('Ungültige Zielfreigabe.'); }
        $project=$visibility==='project' ? trim((string)($state['targetProject'] ?? ($context->getProjectId() ?? ''))) : null;
        if ($visibility==='project' && $project==='') { throw new \InvalidArgumentException('Zielprojekt fehlt.'); }
        return [$visibility,$project];
    }

    /** @return array{0:string,1:?string,2:string} */
    private function parseLocator(string $locator): array
    {
        $parts=explode('::',$locator);
        if (($parts[0] ?? '')==='system' && isset($parts[1])) { return ['system',null,$parts[1]]; }
        if (($parts[0] ?? '')==='project' && isset($parts[1],$parts[2])) { return ['project',$parts[1],$parts[2]]; }
        throw new \InvalidArgumentException('Ungültige Vorlagenauswahl.');
    }

    private function readImportJson(AssistantContext $context): string
    {
        $text = trim((string)$context->input('import_json',''));
        $files = (array)$context->meta('files',[]);
        $file = $files['import_file'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp !== '' && is_file($tmp)) {
                $raw = @file_get_contents($tmp);
                if ($raw !== false) { return $raw; }
            }
        }
        return $text;
    }

    private function boolInput(AssistantContext $context,string $key): bool { return in_array($context->input($key,false),[true,1,'1','true','yes','on'],true); }
    private function nextStep(array $ids,string $current): ?string { $i=array_search($current,$ids,true); return $i!==false && isset($ids[$i+1])?$ids[$i+1]:null; }
    private function previousStep(array $ids,string $current): ?string { $i=array_search($current,$ids,true); return $i!==false && $i>0?$ids[$i-1]:null; }
}
