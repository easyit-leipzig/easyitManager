<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\DataFormModulePackageService;
use EasyIT\Assistant\State\AssistantStateStore;

final class DataFormModulePackageAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore, private DataFormModulePackageService $service) {}

    public function getId(): string { return 'dataform.module-transport'; }
    public function getTitle(): string { return 'DataForm-Modulpaket'; }
    public function getDescription(): string { return 'Exportiert und importiert mehrere zusammengehörige DataForms einschließlich Beziehungsgraph und Datenquellenabhängigkeiten als konsistentes Modul.'; }

    public function getSteps(AssistantContext $context): array
    {
        $scope=$context->getProjectId()?:'global';$state=$this->stateStore->get($this->getId(),$scope);$op=(string)($state['operation']??'export');
        $steps=[new AssistantStep('operation','1. Vorgang','Modul exportieren oder importieren.',['fields'=>[[
            'name'=>'operation','label'=>'Vorgang','type'=>'select','required'=>true,'options'=>['export'=>'Modul exportieren','import'=>'Modul importieren'],
        ]]])];
        if($op==='export'){
            $steps[]=new AssistantStep('source','2. Modulquelle','Start-DataForms und transitive Abhängigkeiten festlegen.',['fields'=>[
                ['name'=>'source_project','label'=>'Quellprojekt','type'=>'text','required'=>true],
                ['name'=>'module_name','label'=>'Modulname','type'=>'text','required'=>true],
                ['name'=>'root_dataforms','label'=>'Start-DataForms (eine ID pro Zeile oder Komma)','type'=>'textarea','rows'=>8,'required'=>true],
                ['name'=>'include_transitive','label'=>'Abhängige Kind-DataForms automatisch aufnehmen','type'=>'checkbox'],
                ['name'=>'include_datasource','label'=>'Bereinigten Datenquellen-Snapshot aufnehmen','type'=>'checkbox'],
            ]]);
            $steps[]=new AssistantStep('export','3. Export','Modulpaket erzeugen.',['fields'=>[['name'=>'confirm_export','label'=>'Modulpaket jetzt erzeugen','type'=>'checkbox']]]);
            $steps[]=new AssistantStep('result','4. Ergebnis','ZIP, SHA-256, Graph und Manifest anzeigen.');
        }else{
            $steps[]=new AssistantStep('upload','2. Modulpaket','ZIP-Paket hochladen und prüfen.',['fields'=>[['name'=>'module_file','label'=>'DataForm-Modulpaket ZIP','type'=>'file','accept'=>'.zip,application/zip','required'=>true]]]);
            $steps[]=new AssistantStep('target','3. Ziel und DataForm-Mapping','Zielprojekt und DataForm-Zuordnungen festlegen.',['fields'=>[
                ['name'=>'target_project','label'=>'Zielprojekt','type'=>'text','required'=>true],
                ['name'=>'dataform_mapping','label'=>'DataForm-Mapping: quelle|ziel','type'=>'textarea','rows'=>8],
                ['name'=>'reference_mapping','label'=>'Weitere Referenzen: alt|neu','type'=>'textarea','rows'=>8],
                ['name'=>'allow_overwrite','label'=>'Bestehende Zielkonfiguration historisiert überschreiben','type'=>'checkbox'],
                ['name'=>'apply_datasource','label'=>'Bereinigten Datenquellen-Snapshot ausdrücklich übernehmen','type'=>'checkbox'],
            ]]);
            $steps[]=new AssistantStep('preview','4. Vorschau','Alle DataForms, Beziehungen und Abhängigkeiten prüfen.');
            $steps[]=new AssistantStep('apply','5. Übernehmen','Import ausdrücklich bestätigen.',['fields'=>[['name'=>'confirm_apply','label'=>'Geprüftes Modul jetzt importieren','type'=>'checkbox']]]);
            $steps[]=new AssistantStep('result','6. Ergebnis','Import und Diagnosen aller DataForms anzeigen.');
        }
        return $steps;
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $scope=$context->getProjectId()?:'global';if($this->bool($context,'reset'))$this->stateStore->clear($this->getId(),$scope);$state=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'operation';$method=strtoupper((string)$context->meta('requestMethod','GET'));$errors=[];$warnings=[];$data=[];
        if($method==='POST'&&!$this->bool($context,'reset')){
            try{$state=$this->merge($state,$context,$stepId);if($stepId==='upload'){$files=(array)$context->meta('files',[]);$staged=$this->service->stageUpload((array)($files['module_file']??[]));$state['token']=$staged['token'];$state['inspection']=$staged['inspection'];$data['moduleInspection']=$staged['inspection'];}$this->stateStore->put($this->getId(),$state,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}
        }
        $steps=$this->getSteps($context);$ids=array_map(static fn(AssistantStep $s):string=>$s->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId=$ids[0];$op=(string)($state['operation']??'export');
        try{
            if($op==='export'&&$stepId==='export'&&!empty($state['confirmExport'])){$res=$this->service->export((string)($state['sourceProject']??''),(string)($state['moduleName']??''),$this->dataForms((string)($state['rootDataForms']??'')),!empty($state['includeTransitive']),!empty($state['includeDatasource']));$state['exportResult']=$res;$this->stateStore->put($this->getId(),$state,$scope);$data['moduleExportResult']=$res;$data['downloadUrl']='dataform-module-download.php?file='.rawurlencode((string)$res['fileName']);$data['shaDownloadUrl']='dataform-module-download.php?file='.rawurlencode((string)$res['shaFileName']);}
            if($op==='import'&&in_array($stepId,['preview','apply','result'],true)&&!empty($state['token'])){
                $dfMap=$this->mapping((string)($state['dataFormMapping']??''));$refMap=$this->mapping((string)($state['referenceMapping']??''));$preview=$this->service->preview((string)$state['token'],(string)($state['targetProject']??''),$dfMap,$refMap,!empty($state['allowOverwrite']),!empty($state['applyDatasource']));$data['modulePreview']=$preview;
                if($stepId==='apply'&&!empty($state['confirmApply'])){$apply=$this->service->apply((string)$state['token'],(string)$state['targetProject'],$dfMap,$refMap,!empty($state['allowOverwrite']),!empty($state['applyDatasource']));$state['applyResult']=$apply;$this->stateStore->put($this->getId(),$state,$scope);$data['moduleApplyResult']=$apply;}
                elseif($stepId==='result'&&is_array($state['applyResult']??null))$data['moduleApplyResult']=$state['applyResult'];
            }
            if($op==='export'&&$stepId==='result'&&is_array($state['exportResult']??null)){$data['moduleExportResult']=$state['exportResult'];$data['downloadUrl']='dataform-module-download.php?file='.rawurlencode((string)($state['exportResult']['fileName']??''));$data['shaDownloadUrl']='dataform-module-download.php?file='.rawurlencode((string)($state['exportResult']['shaFileName']??''));}
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$s)$stateful[]=$s->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));
        $data+=['phase'=>18,'operation'=>$op,'formValues'=>$this->formValues($state,$stepId,$context),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId),'configurationReady'=>$stepId==='result'];
        if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);
        return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='operation')return ['operation'=>(string)$c->input('operation','export')];
        if($step==='source'){$s['sourceProject']=trim((string)$c->input('source_project',$c->getProjectId()??''));$s['moduleName']=trim((string)$c->input('module_name',''));$s['rootDataForms']=(string)$c->input('root_dataforms',$c->getDataFormId()??'');$s['includeTransitive']=$this->bool($c,'include_transitive');$s['includeDatasource']=$this->bool($c,'include_datasource');}
        elseif($step==='export')$s['confirmExport']=$this->bool($c,'confirm_export');
        elseif($step==='target'){$s['targetProject']=trim((string)$c->input('target_project',$c->getProjectId()??''));$s['dataFormMapping']=(string)$c->input('dataform_mapping','');$s['referenceMapping']=(string)$c->input('reference_mapping','');$s['allowOverwrite']=$this->bool($c,'allow_overwrite');$s['applyDatasource']=$this->bool($c,'apply_datasource');}
        elseif($step==='apply')$s['confirmApply']=$this->bool($c,'confirm_apply');
        return $s;
    }
    /** @return array<string,mixed> */
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){
        'operation'=>['operation'=>$s['operation']??'export'],
        'source'=>['source_project'=>$s['sourceProject']??($c->getProjectId()??''),'module_name'=>$s['moduleName']??'','root_dataforms'=>$s['rootDataForms']??($c->getDataFormId()??''),'include_transitive'=>array_key_exists('includeTransitive',$s)?!empty($s['includeTransitive']):true,'include_datasource'=>array_key_exists('includeDatasource',$s)?!empty($s['includeDatasource']):true],
        'export'=>['confirm_export'=>!empty($s['confirmExport'])],
        'target'=>['target_project'=>$s['targetProject']??($c->getProjectId()??''),'dataform_mapping'=>$s['dataFormMapping']??'','reference_mapping'=>$s['referenceMapping']??'','allow_overwrite'=>!empty($s['allowOverwrite']),'apply_datasource'=>!empty($s['applyDatasource'])],
        'apply'=>['confirm_apply'=>!empty($s['confirmApply'])],default=>[]};}
    /** @return list<string> */
    private function dataForms(string $text):array{return array_values(array_filter(array_map('trim',preg_split('/[\r\n,;]+/',$text)?:[]),static fn($v)=>$v!==''));}
    /** @return array<string,string> */
    private function mapping(string $text):array{$m=[];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line==='')continue;$p=array_map('trim',explode('|',$line,2));if(($p[0]??'')!==''&&isset($p[1])&&$p[1]!=='')$m[$p[0]]=$p[1];}return $m;}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}
    private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}
    private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
