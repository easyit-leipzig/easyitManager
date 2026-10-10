<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/system/app/bootstrap.php';
require_once $root.'/system/app/calendar_project.php';
require_once __DIR__.'/api/runtime.php';
require_once __DIR__.'/system/CalendarDataFormBridge.php';
$user=enterprise_require_auth('../../');$id=(int)($_GET['project']??0);$entity=(string)($_GET['entity']??'persons');
enterprise_require_project_scope($user,$id,false);$admin=enterprise_pdo();enterprise_upgrade($admin);$st=$admin->prepare('SELECT * FROM projects WHERE id=?');$st->execute([$id]);$project=$st->fetch(PDO::FETCH_ASSOC);
if(!$project||(string)$project['product_type']!=='calendar'){http_response_code(404);exit('Calendar-Projekt nicht gefunden.');}
enterprise_require_project_product($user,$id,'calendar',(string)$project['slug']);
if(!in_array($entity,['events','persons','places','resources','categories','roles','participants','attachments'],true))$entity='persons';
$_SESSION['easyit_calendar_project_id']=$id;$env=enterprise_env($root.'/DataForm5-Core/.env');$ctx=easyit_calendar_project_context();$pdo=enterprise_project_store_for_project($env,$project);$integration=easyit_calendar_dataform_ensure($pdo,$ctx);$df=(int)($integration['bindings'][$entity]['id']??0);
if($df<1){header('Location: setup.php?project='.$id);exit;}
// HF105: Die frühere eigene Calendar-Datenpflege ist vollständig durch DataForm ersetzt.
header('Location: ../dataform/records.php?project='.$id.'&dataform='.$df);exit;
