<?php
declare(strict_types=1);
use DataForm5\Http\Core\Request;
require dirname(__DIR__).'/system/app/bootstrap.php';
$user=enterprise_require_auth('../');
$request=Request::capture();
$routeName=trim((string)$request->query('route',''));
if($routeName==='') $response=(new \DataForm5\Api\Core\ApiResponse())->error('Keine API-Route angegeben.',404,[],'route_missing');
else $response=enterprise_module_api_dispatcher()->dispatch($routeName,$request,$user);
$response->send();
