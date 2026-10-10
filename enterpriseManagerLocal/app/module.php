<?php
declare(strict_types=1);
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;

require dirname(__DIR__).'/system/app/bootstrap.php';
require dirname(__DIR__).'/system/ui/layout.php';
$user=enterprise_require_auth('../');
$request=Request::capture();
$routeName=trim((string)$request->query('route',''));
if($routeName===''){
    http_response_code(404);$routeTitle='Modulroute';$body='<div class="notice error">Keine Modulroute angegeben.</div>';
}else{
    try{
        $result=enterprise_module_dispatcher()->dispatch($routeName,$request->method(),$request,$user);
        if($result instanceof Response){
            if(!$result->isPage()){$result->send();exit;}
            http_response_code($result->status());
            foreach($result->headers() as $name=>$value) if(strtolower($name)!=='content-type' && !headers_sent()) header($name.': '.$value);
            $routeTitle=(string)$result->meta('title',$routeName);
            $body=$result->content();
        }elseif(is_array($result)){
            // Abwärtskompatibilität für Phase-N-Controller.
            $routeTitle=(string)($result['title']??$routeName);
            $body=(string)($result['content']??'');
        }else{
            $routeTitle=$routeName;
            $body=is_string($result)?$result:'';
        }
    }catch(Throwable $e){
        $code=(int)$e->getCode(); if($code<400||$code>599)$code=500; http_response_code($code);
        $routeTitle='Modulroute';
        $body='<div class="notice error">'.e($e->getMessage()).'</div>';
    }
}
ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'dashboard.php'],['label'=>'Module','href'=>'modules/index.php'],['label'=>$routeTitle,'href'=>'']]);
echo $body;
$content=ob_get_clean();
render_page(['title'=>$routeTitle,'active'=>'modules','base'=>'../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Modulroute','location'=>'Enterprise → Module → '.$routeTitle,'short'=>'Diese Seite wird über das zentrale Modul-Routing mit typisiertem Request/Response-System bereitgestellt.']]);
