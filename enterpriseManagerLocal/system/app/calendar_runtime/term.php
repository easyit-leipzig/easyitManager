<?php
declare(strict_types=1);
require_once __DIR__.'/../auth.php';$calendarUser=cal_auth_require('viewer');if($_SERVER['REQUEST_METHOD']==='POST'&&!cal_auth_can('editor')){http_response_code(403);exit('Nur editor/admin dürfen Termine ändern.');}require_once __DIR__.'/../lib/DataFormApp.php';
$c=df_config();$id=max(1,(int)($c['term_dataform_id']??0));
if($id<1){http_response_code(500);exit('Termin-DataForm fehlt in config.php.');}
if(!isset($_GET['new']) && (string)($_GET['mode']??'')==='create')$_GET['new']=1;
if(isset($_GET['record'])&&!isset($_GET['active_record']))$_GET['active_record']=(int)$_GET['record'];
df_render_dataform($id);
