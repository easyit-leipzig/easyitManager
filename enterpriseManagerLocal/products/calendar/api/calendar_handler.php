<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require_once dirname(__DIR__).'/system/CalendarDataFormBridge.php';

try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false,'error'=>'Der Kalender-Handler akzeptiert ausschließlich POST-Anfragen.'],405);
    validate_csrf_token();$in=request_json();$action=strtolower(trim((string)($in['action']??'')));$ctx=easyit_calendar_project_context();$pdo=db();
    // HF105: auch die Calendar-API arbeitet ausschließlich über DataFormRecordStore.
    $integration=easyit_calendar_dataform_ensure($pdo,$ctx);
    match($action){'list'=>list_events($pdo,$ctx,$integration,$in),'create'=>save_event($pdo,$ctx,$integration,$in,false),'update'=>save_event($pdo,$ctx,$integration,$in,true),'delete'=>delete_event($pdo,$ctx,$integration,$in),'block_create'=>save_block($pdo,$ctx,$integration,$in,false),'block_update'=>save_block($pdo,$ctx,$integration,$in,true),'block_delete'=>delete_block($pdo,$integration,$in),default=>throw new InvalidArgumentException('Unbekannte Kalenderaktion.')};
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],400);}catch(RuntimeException $e){$c=(int)$e->getCode();json_response(['ok'=>false,'error'=>$e->getMessage()],in_array($c,[403,404,409],true)?$c:409);}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'Calendar/DataForm-Datenzugriff fehlgeschlagen. Details stehen im PHP-Fehlerprotokoll.'],500);}

function validate_csrf_token():void{$a=(string)($_SESSION['easyit_calendar_csrf']??'');$b=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');if($a===''||$b===''||!hash_equals($a,$b))throw new RuntimeException('Die Sitzung ist abgelaufen oder das Sicherheitstoken ist ungültig.',403);}
function dfid(array $integration,string $logical):int{$id=(int)($integration['bindings'][$logical]['id']??0);if($id<1)throw new RuntimeException('Calendar-DataForm fehlt: '.$logical,409);return $id;}
function dfdata(array $row):array{return is_array($row['data']??null)?$row['data']:[];}
function calendar_iso(string $value):string{$v=trim($value);if($v==='')return '';$v=str_replace(' ','T',$v);return strlen($v)===16?$v.':00':$v;}
function cfield(array $ctx,string $logical,string $fallback):string{return easyit_calendar_field_name($ctx,$logical,$fallback);}

function list_events(PDO $pdo,array $ctx,array $integration,array $in):never{
    $start=(string)($in['start']??'');$end=(string)($in['end']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$end<=$start)throw new InvalidArgumentException('Ungültiger Ladebereich.');
    $events=DataFormRecordStore::all($pdo,dfid($integration,'events'));
    $participants=DataFormRecordStore::all($pdo,dfid($integration,'participants'));
    $attachments=DataFormRecordStore::all($pdo,dfid($integration,'attachments'));
    $persons=DataFormRecordStore::all($pdo,dfid($integration,'persons'));
    $studentColorById=[];$personNameById=[];$blockPersons=[];foreach($persons as $row){$pd=dfdata($row);$pid=(int)($row['id']??0);$name=(string)($pd['display_name']??('Person #'.$pid));if($pid>0)$personNameById[$pid]=$name;if($pid>0&&(int)($pd['activ']??1)===1)$blockPersons[]=['id'=>$pid,'name'=>$name];$color=trim((string)($pd['calendar_color']??''));if(preg_match('/^#[0-9a-f]{6}$/i',$color))$studentColorById[$pid]=strtolower($color);}
    $places=DataFormRecordStore::all($pdo,dfid($integration,'places'));
    $placeById=[];foreach($places as $row){$pd=dfdata($row);$placeById[(int)($row['id']??0)]=(string)($pd['name']??'');}
    $categoryRows=DataFormRecordStore::all($pdo,dfid($integration,'categories'));$categories=[];$categoryById=[];$categoryColorById=[];
    foreach($categoryRows as $cr){$cd=dfdata($cr);if((int)($cd['activ']??1)!==1)continue;$cid=(int)($cr['id']??0);if($cid<1)continue;$color=trim((string)($cd['category_color']??''));if(!preg_match('/^#[0-9a-f]{6}$/i',$color))$color='';$item=['id'=>$cid,'name'=>(string)($cd['name']??('Kategorie #'.$cid)),'color'=>$color!==''?strtolower($color):''];$categories[]=$item;$categoryById[$cid]=$item['name'];$categoryColorById[$cid]=$item['color'];}
    $partsByEvent=[];foreach($participants as $row){$d=dfdata($row);$partsByEvent[(int)($d['event_id']??0)][]=$d;}
    $attsByEvent=[];foreach($attachments as $row){$d=dfdata($row);if((int)($d['activ']??1)!==1)continue;$attsByEvent[(int)($d['event_id']??0)][]=(string)($d['original_name']??'');}
    $fStart=cfield($ctx,'start','start');$fEnd=cfield($ctx,'end','end');$fActive=cfield($ctx,'active','activ');$fTitle=cfield($ctx,'title','thema');$fDescription=cfield($ctx,'description','description');$fLocation=cfield($ctx,'location','location');$fCategory=cfield($ctx,'category','category_id');
    $rows=[];
    foreach($events as $row){$d=dfdata($row);$s=calendar_iso((string)($d[$fStart]??''));$e=calendar_iso((string)($d[$fEnd]??''));if((int)($d[$fActive]??1)!==1||$s===''||$e===''||substr($s,0,10)>=$end||substr($e,0,10)<$start)continue;
        $id=(int)$row['id'];$locationId=(int)($d[$fLocation]??0);$categoryId=(int)($d[$fCategory]??0);$out=['id'=>$id,'start'=>$s,'end'=>$e,'activ'=>true,'thema'=>(string)($d[$fTitle]??''),'description'=>(string)($d[$fDescription]??''),'version_no'=>(int)($d['version_no']??1),'to_role'=>3,'to_tutor'=>0,'to_student'=>0,'location'=>$locationId,'location_name'=>(string)($placeById[$locationId]??''),'category_id'=>$categoryId,'category_name'=>(string)($categoryById[$categoryId]??''),'category_color'=>(string)($categoryColorById[$categoryId]??''),'appendizies'=>array_values(array_filter($attsByEvent[$id]??[])),'filter_data'=>$d];
        foreach($partsByEvent[$id]??[] as $part){$role=(int)($part['role_id']??0);$person=(int)($part['person_id']??0);if($role===2)$out['to_tutor']=$person;else{$out['to_student']=$person;$out['to_role']=$role?:3;}}
        $out['student_color']=(string)($studentColorById[(int)$out['to_student']]??'');
        // HF155: Filterdaten enthalten sowohl die ursprünglichen Event-/DataForm-Felder
        // als auch alle für die Kalenderansicht abgeleiteten Lookup- und Teilnehmerwerte.
        $out['filter_data']=array_merge(is_array($out['filter_data']??null)?$out['filter_data']:[],[
            'id'=>$id,'start'=>$s,'end'=>$e,'activ'=>true,'thema'=>$out['thema'],'description'=>$out['description'],
            'version_no'=>$out['version_no'],'to_role'=>$out['to_role'],'to_tutor'=>$out['to_tutor'],'to_student'=>$out['to_student'],
            'location'=>$locationId,'location_name'=>$out['location_name'],'category_id'=>$categoryId,
            'category_name'=>$out['category_name'],'category_color'=>$out['category_color'],'student_color'=>$out['student_color'],
            'appendizies'=>$out['appendizies']
        ]);
        $rows[]=$out;
    }
    usort($rows,fn($a,$b)=>[$a['start'],$a['id']]<=>[$b['start'],$b['id']]);
    $blockRows=DataFormRecordStore::all($pdo,dfid($integration,'blocks'));$blocks=[];
    foreach($blockRows as $br){$bd=dfdata($br);if((int)($bd['activ']??1)!==1)continue;$bs=calendar_iso((string)($bd['start']??''));$be=calendar_iso((string)($bd['end']??''));if($bs===''||$be===''||substr($bs,0,10)>=$end||substr($be,0,10)<$start)continue;$cid=(int)($bd['category_id']??0);$pid=max(0,(int)($bd['person_id']??0));$blocks[]=['id'=>(int)$br['id'],'start'=>$bs,'end'=>$be,'category_id'=>$cid,'category_name'=>(string)($categoryById[$cid]??('Kategorie #'.$cid)),'category_color'=>(string)($categoryColorById[$cid]??''),'person_id'=>$pid,'person_name'=>$pid>0?(string)($personNameById[$pid]??('Person #'.$pid)):'Alle Personen','reason'=>(string)($bd['reason']??'')];}
    usort($blocks,fn($a,$b)=>[$a['start'],$a['id']]<=>[$b['start'],$b['id']]);
    json_response(['ok'=>true,'events'=>$rows,'blocks'=>$blocks,'block_categories'=>$categories,'block_persons'=>$blockPersons,'storage_driver'=>easyit_calendar_driver($ctx),'data_layer'=>'DataFormRecordStore']);
}

function validate_block(array $in):array{
    foreach(['start','end'] as $f)if(!isset($in[$f])||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',(string)$in[$f]))throw new InvalidArgumentException("$f ist ungültig.");
    $s=new DateTimeImmutable((string)$in['start']);$e=new DateTimeImmutable((string)$in['end']);if($e<=$s)throw new InvalidArgumentException('Das Ende der Blockierung muss nach dem Beginn liegen.');
    $category=max(0,(int)($in['category_id']??0));if($category<1)throw new InvalidArgumentException('Für die Blockierung muss eine Kategorie gewählt werden.');
    return ['start'=>$s->format('Y-m-d H:i:s'),'end'=>$e->format('Y-m-d H:i:s'),'category_id'=>$category,'person_id'=>max(0,(int)($in['person_id']??0)),'reason'=>mb_substr(trim((string)($in['reason']??'')),0,255)];
}
function save_block(PDO $pdo,array $ctx,array $integration,array $in,bool $update):never{
    $v=validate_block($in);$blocks=dfid($integration,'blocks');$cats=dfid($integration,'categories');$persons=dfid($integration,'persons');
    $cat=DataFormRecordStore::find($pdo,$cats,$v['category_id']);if(!$cat||(int)(dfdata($cat)['activ']??1)!==1)throw new InvalidArgumentException('Die gewählte Blockierungskategorie ist nicht verfügbar.');
    if($v['person_id']>0){$person=DataFormRecordStore::find($pdo,$persons,$v['person_id']);if(!$person||(int)(dfdata($person)['activ']??1)!==1)throw new InvalidArgumentException('Die gewählte Person ist nicht verfügbar.');}
    $id=$update?(int)($in['id']??0):0;
    foreach(DataFormRecordStore::all($pdo,$blocks) as $br){$bd=dfdata($br);if((int)($bd['activ']??1)!==1||(int)($br['id']??0)===$id)continue;$bs=(string)($bd['start']??'');$be=(string)($bd['end']??'');$otherPerson=max(0,(int)($bd['person_id']??0));$sameScope=$v['person_id']===0||$otherPerson===0||$otherPerson===$v['person_id'];if($sameScope&&$bs!==''&&$be!==''&&$v['start']<$be&&$v['end']>$bs)throw new RuntimeException('Der gewählte Zeitraum überschneidet bereits eine Blockierung für dieselbe Person bzw. für alle Personen.',409);}
    $events=dfid($integration,'events');$participants=dfid($integration,'participants');$participantPersons=[];foreach(DataFormRecordStore::all($pdo,$participants) as $pr){$pd=dfdata($pr);$participantPersons[(int)($pd['event_id']??0)][]=(int)($pd['person_id']??0);}
    $fStart=cfield($ctx,'start','start');$fEnd=cfield($ctx,'end','end');$fActive=cfield($ctx,'active','activ');foreach(DataFormRecordStore::all($pdo,$events) as $er){$ed=dfdata($er);if((int)($ed[$fActive]??1)!==1)continue;$es=(string)($ed[$fStart]??'');$ee=(string)($ed[$fEnd]??'');if($es===''||$ee===''||!($v['start']<$ee&&$v['end']>$es))continue;if($v['person_id']===0||in_array($v['person_id'],$participantPersons[(int)($er['id']??0)]??[],true))throw new RuntimeException($v['person_id']===0?'Der gewählte Zeitraum enthält bereits einen Termin.':'Die gewählte Person hat in diesem Zeitraum bereits einen Termin.',409);}
    if($update&&($id<1||!DataFormRecordStore::find($pdo,$blocks,$id)))throw new RuntimeException('Blockierung nicht gefunden.',404);
    $data=['start'=>$v['start'],'end'=>$v['end'],'category_id'=>$v['category_id'],'person_id'=>$v['person_id']>0?$v['person_id']:null,'reason'=>$v['reason'],'activ'=>1,'updated_at'=>date('Y-m-d H:i:s')];
    if($update)DataFormRecordStore::update($pdo,$blocks,$id,$data);else{$data['created_by']=(int)($_SESSION['enterprise_user_id']??0);$data['created_at']=date('Y-m-d H:i:s');$id=DataFormRecordStore::create($pdo,$blocks,$data);}
    json_response(['ok'=>true,'id'=>$id],$update?200:201);
}
function delete_block(PDO $pdo,array $integration,array $in):never{
    $id=(int)($in['id']??0);if($id<1)throw new InvalidArgumentException('Ungültige Blockierungs-ID.');$df=dfid($integration,'blocks');$row=DataFormRecordStore::find($pdo,$df,$id);if(!$row)throw new RuntimeException('Blockierung nicht gefunden.',404);DataFormRecordStore::update($pdo,$df,$id,['activ'=>0,'updated_at'=>date('Y-m-d H:i:s')]);json_response(['ok'=>true,'id'=>$id]);
}
function event_overlaps_block(PDO $pdo,array $integration,string $start,string $end,array $personIds=[]): bool {
    $personIds=array_values(array_unique(array_filter(array_map('intval',$personIds),static fn(int $v):bool=>$v>0)));
    foreach(DataFormRecordStore::all($pdo,dfid($integration,'blocks')) as $row){$d=dfdata($row);if((int)($d['activ']??1)!==1)continue;$bs=(string)($d['start']??'');$be=(string)($d['end']??'');$blockPerson=max(0,(int)($d['person_id']??0));if($bs!==''&&$be!==''&&$start<$be&&$end>$bs&&($blockPerson===0||in_array($blockPerson,$personIds,true)))return true;}
    return false;
}
function validate_event(array $in):array{
    foreach(['start','end'] as $f)if(!isset($in[$f])||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',(string)$in[$f]))throw new InvalidArgumentException("$f ist ungültig.");
    $s=new DateTimeImmutable((string)$in['start']);$e=new DateTimeImmutable((string)$in['end']);if($e<=$s)throw new InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
    return ['start'=>$s->format('Y-m-d H:i:s'),'end'=>$e->format('Y-m-d H:i:s'),'activ'=>!empty($in['activ'])?1:0,'thema'=>mb_substr(trim((string)($in['thema']??'')),0,255),'description'=>trim((string)($in['description']??'')),'to_tutor'=>max(0,(int)($in['to_tutor']??0)),'to_student'=>max(0,(int)($in['to_student']??0)),'to_role'=>max(1,(int)($in['to_role']??3)),'appendizies'=>array_values(array_unique(array_filter(array_map('trim',is_array($in['appendizies']??null)?$in['appendizies']:[]))))];
}
function replace_children(PDO $pdo,int $df,int $eventId,array $rows):void{
    foreach(DataFormRecordStore::all($pdo,$df) as $row){$d=dfdata($row);if((int)($d['event_id']??0)===$eventId)DataFormRecordStore::delete($pdo,$df,(int)$row['id']);}
    foreach($rows as $data)DataFormRecordStore::create($pdo,$df,$data);
}
function save_event(PDO $pdo,array $ctx,array $integration,array $in,bool $update):never{
    $v=validate_event($in);if(event_overlaps_block($pdo,$integration,$v['start'],$v['end'],[$v['to_tutor'],$v['to_student']]))throw new RuntimeException('Der gewählte Zeitraum ist blockiert.',409);$events=dfid($integration,'events');$uid=(int)($_SESSION['enterprise_user_id']??1);$id=$update?(int)($in['id']??0):0;
    $old=$id>0?DataFormRecordStore::find($pdo,$events,$id):null;if($update&&!$old)throw new RuntimeException('Termin nicht gefunden.',404);$oldData=$old?dfdata($old):[];
    $fStart=cfield($ctx,'start','start');$fEnd=cfield($ctx,'end','end');$fActive=cfield($ctx,'active','activ');$fTitle=cfield($ctx,'title','thema');$fDescription=cfield($ctx,'description','description');$fStatus=cfield($ctx,'status','event_status');
    $event=[$fStart=>$v['start'],$fEnd=>$v['end'],$fActive=>$v['activ'],$fTitle=>$v['thema'],$fDescription=>$v['description'],$fStatus=>(string)($oldData[$fStatus]??'planned'),'version_no'=>(int)($oldData['version_no']??0)+1,'updated_by'=>$uid,'updated_at'=>date('Y-m-d H:i:s')];
    if(!$update){$event['created_by']=$uid;$event['created_at']=date('Y-m-d H:i:s');$id=DataFormRecordStore::create($pdo,$events,$event);}else DataFormRecordStore::update($pdo,$events,$id,$event);
    $pr=[];if($v['to_tutor']>0)$pr[]=['event_id'=>$id,'person_id'=>$v['to_tutor'],'role_id'=>2,'participation_status'=>'accepted','is_primary'=>1];if($v['to_student']>0)$pr[]=['event_id'=>$id,'person_id'=>$v['to_student'],'role_id'=>$v['to_role'],'participation_status'=>'accepted','is_primary'=>1];replace_children($pdo,dfid($integration,'participants'),$id,$pr);
    $ar=[];foreach($v['appendizies'] as $i=>$name){$safe=preg_replace('/[^A-Za-z0-9._-]+/u','_',basename((string)$name))?:'anlage';$stored=sprintf('event_%d_%d_%s',$id,$i+1,$safe);$ar[]=['event_id'=>$id,'original_name'=>$name,'stored_name'=>$stored,'storage_path'=>'documents/attachments/'.$stored,'activ'=>1,'created_at'=>date('Y-m-d H:i:s')];}replace_children($pdo,dfid($integration,'attachments'),$id,$ar);
    json_response(['ok'=>true,'id'=>$id,'storage_driver'=>easyit_calendar_driver($ctx),'data_layer'=>'DataFormRecordStore'],$update?200:201);
}
function delete_event(PDO $pdo,array $ctx,array $integration,array $in):never{
    $id=(int)($in['id']??0);if($id<1)throw new InvalidArgumentException('Ungültige Termin-ID.');$events=dfid($integration,'events');$row=DataFormRecordStore::find($pdo,$events,$id);if(!$row)throw new RuntimeException('Termin nicht gefunden.',404);$d=dfdata($row);$fActive=cfield($ctx,'active','activ');DataFormRecordStore::update($pdo,$events,$id,[$fActive=>0,'version_no'=>(int)($d['version_no']??0)+1,'updated_by'=>(int)($_SESSION['enterprise_user_id']??1),'updated_at'=>date('Y-m-d H:i:s')]);json_response(['ok'=>true,'id'=>$id,'data_layer'=>'DataFormRecordStore']);
}
