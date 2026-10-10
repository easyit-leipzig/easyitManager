<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Transport\DataFormPackageService;

final class DataFormPackageAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store, private DataFormPackageService $service) {}
    public function getId(): string { return 'dataform.transport'; }
    public function getTitle(): string { return 'DataForm-Import-/Export-Assistent'; }
    public function getDescription(): string { return 'Exportiert vollständige DataForm-Konfigurationen als geprüftes ZIP-Paket und importiert sie mit Referenz-Mapping, Konfliktprüfung und Diagnose in ein anderes Projekt.'; }

    public function getSteps(AssistantContext $context): array
    {
        $scope=$context->getProjectId()?:'global'; $state=$this->store->get($this->getId(),$scope); $op=(string)($state['operation']??$context->input('operation','export'));
        $operation=new AssistantStep('operation','1. Vorgang','DataForm-Paket exportieren oder importieren.',['fields'=>[[
            'name'=>'operation','label'=>'Vorgang','type'=>'select','required'=>true,'options'=>['export'=>'DataForm-Paket exportieren','import'=>'DataForm-Paket importieren']
        ]]]);
        if($op==='import') return [
            $operation,
            new AssistantStep('upload','2. Paket hochladen','Transportpaket wird in einem isolierten Prüfbereich geöffnet, Manifest und SHA-256 jedes Eintrags werden geprüft.',['fields'=>[[
                'name'=>'package_file','label'=>'DataForm-Paket (.zip)','type'=>'file','required'=>empty($state['importToken']),'accept'=>'.zip,application/zip'
            ]]]),
            new AssistantStep('target','3. Ziel und Abhängigkeiten','Zielprojekt/DataForm festlegen und Datenquellenreferenzen auf das Ziel abbilden.',['fields'=>[
                ['name'=>'target_project','label'=>'Zielprojekt','type'=>'text','required'=>true],
                ['name'=>'target_dataform','label'=>'Ziel-DataForm','type'=>'text','required'=>true],
                ['name'=>'target_profile','label'=>'Ziel-Datenquellenprofil (optional)','type'=>'text'],
                ['name'=>'target_source','label'=>'Ziel-Hauptquelle/Tabelle/View (optional)','type'=>'text'],
                ['name'=>'allow_overwrite','label'=>'Vorhandene Zielkonfiguration historisiert überschreiben','type'=>'checkbox'],
                ['name'=>'apply_datasource','label'=>'Bereinigten Datenquellen-Snapshot ausdrücklich mit übernehmen','type'=>'checkbox'],
            ]]),
            new AssistantStep('mapping','4. Referenzen neu zuordnen','Zusätzliche exakte Referenzabbildungen, je Zeile alterWert|neuerWert.',['fields'=>[[
                'name'=>'mapping_lines','label'=>'Zusätzliches Mapping','type'=>'textarea','rows'=>8,'placeholder'=>"ed_ev_info|ed_customer_info\nproject-main|customer-main"
            ]]]),
            new AssistantStep('preview','5. Diagnose vor Import','Manifest, Checksummen, Zielkonflikte, DataForm, Felder, Beziehungen, Events, Aktionen und optional Datenquelle validieren. Noch keine Übernahme.'),
            new AssistantStep('apply','6. Import übernehmen','Erst nach erfolgreicher Vorschau schreiben. Alle Zieländerungen laufen über die persistente Historie.',['fields'=>[[
                'name'=>'confirm_apply','label'=>'Vorschau geprüft – Paket jetzt importieren','type'=>'checkbox'
            ]]]),
        ];
        return [
            $operation,
            new AssistantStep('source','2. Quelle','Quellprojekt und Quell-DataForm festlegen.',['fields'=>[
                ['name'=>'source_project','label'=>'Quellprojekt','type'=>'text','required'=>true],
                ['name'=>'source_dataform','label'=>'Quell-DataForm','type'=>'text','required'=>true],
                ['name'=>'include_datasource','label'=>'Bereinigten Datenquellen-Snapshot in das Paket aufnehmen','type'=>'checkbox'],
            ]]),
            new AssistantStep('export','3. Prüfen und exportieren','Persistente Konfiguration sammeln, Abhängigkeiten beschreiben, Manifest und SHA-256 erzeugen.',['fields'=>[[
                'name'=>'confirm_export','label'=>'DataForm-Paket jetzt erzeugen','type'=>'checkbox'
            ]]]),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId=null): AssistantResult
    {
        $scope=$context->getProjectId()?:'global'; if($this->bool($context,'reset'))$this->store->clear($this->getId(),$scope);
        $state=$this->store->get($this->getId(),$scope); $stepId=$stepId?:'operation'; $method=strtoupper((string)$context->meta('requestMethod','GET'));
        if($method==='POST'&&!$this->bool($context,'reset')){$state=$this->applyInput($state,$context,$stepId);$this->store->put($this->getId(),$state,$scope);}
        $steps=$this->getSteps($context);$ids=array_map(static fn(AssistantStep $s)=>$s->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId=$ids[0];
        $errors=[];$warnings=[];$data=['phase'=>17,'formValues'=>$this->formValues($state,$stepId,$context),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId),'operation'=>$state['operation']??'export'];
        try{
            if(($state['operation']??'export')==='import'){
                if($stepId==='upload'&&$method==='POST'){
                    $files=(array)$context->meta('files',[]);$upload=is_array($files['package_file']??null)?$files['package_file']:[];
                    if($upload!==[]){$staged=$this->service->stageUpload($upload);$state['importToken']=$staged['token'];$state['inspection']=$staged['inspection'];$this->store->put($this->getId(),$state,$scope);$data['packageInspection']=$staged['inspection'];}
                    elseif(empty($state['importToken']))$errors[]='DataForm-Paket fehlt.';
                }
                if(in_array($stepId,['target','mapping','preview','apply'],true)&&empty($state['importToken']))$errors[]='Zuerst muss ein gültiges DataForm-Paket hochgeladen werden.';
                if(in_array($stepId,['target','mapping','preview','apply'],true)){
                    if(trim((string)($state['targetProject']??''))==='')$errors[]='Zielprojekt fehlt.';
                    if(trim((string)($state['targetDataForm']??''))==='')$errors[]='Ziel-DataForm fehlt.';
                }
                if(in_array($stepId,['preview','apply'],true)&&$errors===[]){
                    $mapping=$this->mapping($state);
                    $preview=$this->service->preview((string)$state['importToken'],(string)$state['targetProject'],(string)$state['targetDataForm'],$mapping,!empty($state['allowOverwrite']),!empty($state['applyDatasource']));
                    $data['packagePreview']=$preview;$warnings=array_merge($warnings,(array)($preview['warnings']??[]));if(($preview['verdict']??'')==='FAIL')$errors=array_merge($errors,(array)($preview['errors']??[]));
                    if($stepId==='apply'&&$method==='POST'){
                        if(empty($state['confirmApply']))$errors[]='Der Import muss ausdrücklich bestätigt werden.';
                        elseif($errors===[]){$apply=$this->service->apply((string)$state['importToken'],(string)$state['targetProject'],(string)$state['targetDataForm'],$mapping,!empty($state['allowOverwrite']),!empty($state['applyDatasource']));$data['packageApplyResult']=$apply;$data['configurationReady']=!empty($apply['applied']);if(!empty($apply['applied'])){$data['handoffUrl']='run.php?'.http_build_query(['assistant'=>'dataform.diagnostics','project_id'=>$state['targetProject'],'dataform_id'=>$state['targetDataForm'],'step'=>'run']);$data['handoffLabel']='Importiertes DataForm vollständig diagnostizieren';}}
                    }
                }
                if(!empty($state['inspection']))$data['packageInspection']=$state['inspection'];
            }else{
                if(in_array($stepId,['source','export'],true)){
                    if(trim((string)($state['sourceProject']??''))==='')$errors[]='Quellprojekt fehlt.';
                    if(trim((string)($state['sourceDataForm']??''))==='')$errors[]='Quell-DataForm fehlt.';
                }
                if($stepId==='export'&&$method==='POST'){
                    if(empty($state['confirmExport']))$errors[]='Der Export muss ausdrücklich bestätigt werden.';
                    elseif($errors===[]){$result=$this->service->export((string)$state['sourceProject'],(string)$state['sourceDataForm'],!empty($state['includeDatasource']));$storedExport=$result;unset($storedExport['path']);$state['lastExport']=$storedExport;$this->store->put($this->getId(),$state,$scope);$data['packageExportResult']=$result;$data['downloadUrl']='dataform-package-download.php?file='.rawurlencode((string)$result['fileName']);$data['shaDownloadUrl']='dataform-package-download.php?file='.rawurlencode((string)$result['shaFileName']);$data['configurationReady']=true;}
                }elseif(!empty($state['lastExport'])){$data['packageExportResult']=$state['lastExport'];$data['downloadUrl']='dataform-package-download.php?file='.rawurlencode((string)($state['lastExport']['fileName']??''));$data['shaDownloadUrl']='dataform-package-download.php?file='.rawurlencode((string)($state['lastExport']['shaFileName']??''));}
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $ci=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$s)$stateful[]=$s->withState($i<$ci?AssistantStep::STATE_COMPLETE:($i===$ci?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));
        if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);
        return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function applyInput(array $s,AssistantContext $c,string $step):array{
        if($step==='operation')return ['operation'=>(string)$c->input('operation','export')];
        if($step==='source'){$s['sourceProject']=trim((string)$c->input('source_project',''));$s['sourceDataForm']=trim((string)$c->input('source_dataform',''));$s['includeDatasource']=$this->bool($c,'include_datasource');$s['confirmExport']=false;unset($s['lastExport']);}
        elseif($step==='export')$s['confirmExport']=$this->bool($c,'confirm_export');
        elseif($step==='target'){$s['targetProject']=trim((string)$c->input('target_project',''));$s['targetDataForm']=trim((string)$c->input('target_dataform',''));$s['targetProfile']=trim((string)$c->input('target_profile',''));$s['targetSource']=trim((string)$c->input('target_source',''));$s['allowOverwrite']=$this->bool($c,'allow_overwrite');$s['applyDatasource']=$this->bool($c,'apply_datasource');}
        elseif($step==='mapping')$s['mappingLines']=trim((string)$c->input('mapping_lines',''));
        elseif($step==='apply')$s['confirmApply']=$this->bool($c,'confirm_apply');
        return $s;
    }
    /** @return array<string,mixed> */
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){
        'operation'=>['operation'=>$s['operation']??'export'],
        'source'=>['source_project'=>$s['sourceProject']??($c->getProjectId()??''),'source_dataform'=>$s['sourceDataForm']??($c->getDataFormId()??''),'include_datasource'=>array_key_exists('includeDatasource',$s)?!empty($s['includeDatasource']):true],
        'export'=>['confirm_export'=>!empty($s['confirmExport'])],
        'target'=>['target_project'=>$s['targetProject']??($c->getProjectId()??''),'target_dataform'=>$s['targetDataForm']??'','target_profile'=>$s['targetProfile']??'','target_source'=>$s['targetSource']??'','allow_overwrite'=>!empty($s['allowOverwrite']),'apply_datasource'=>!empty($s['applyDatasource'])],
        'mapping'=>['mapping_lines'=>$s['mappingLines']??''], 'apply'=>['confirm_apply'=>!empty($s['confirmApply'])], default=>[]};}
    /** @return array<string,string> */
    private function mapping(array $s):array{$m=[];$d=(array)($s['inspection']['descriptor']??[]);$oldP=trim((string)($d['profile']??''));$oldS=trim((string)($d['sourceName']??''));if($oldP!==''&&trim((string)($s['targetProfile']??''))!=='')$m[$oldP]=trim((string)$s['targetProfile']);if($oldS!==''&&trim((string)($s['targetSource']??''))!=='')$m[$oldS]=trim((string)$s['targetSource']);foreach(preg_split('/\R/',(string)($s['mappingLines']??''))?:[] as $line){$line=trim($line);if($line==='')continue;$p=array_map('trim',explode('|',$line,2));if(($p[0]??'')!==''&&array_key_exists(1,$p))$m[$p[0]]=$p[1];}return $m;}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}
    private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}
    private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
