<?php
declare(strict_types=1);
namespace DataForm5\Modules\Routing;
use DataForm5\Api\Core\ApiResponse;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;
use DataForm5\Security\Csrf\CsrfTokenManager;
use DataForm5\Validation\Exceptions\ValidationException;
use DataForm5\Modules\Lifecycle\ModuleLifecycleManager;
final class ModuleApiDispatcher
{
    public function __construct(private readonly ServiceContainer $container,private readonly ModuleApiRegistry $routes,private readonly ApiResponse $api,private readonly ?ModuleLifecycleManager $lifecycle=null){}
    public function dispatch(string $routeName,Request $request,array $user): Response
    {
        $route=$this->routes->get($routeName);
        if($route===null) return $this->api->error('API-Route nicht gefunden.',404,[],'route_not_found');
        $method=strtoupper($request->method());
        if(!in_array($method,$route['methods'],true)) return $this->api->error('HTTP-Methode nicht erlaubt.',405,['allowed'=>$route['methods']],'method_not_allowed');
        $cap=(string)$route['capability'];
        if($cap!==''&&function_exists('enterprise_can')&&!enterprise_can($user,$cap)) return $this->api->error('Berechtigung fehlt.',403,['capability'=>$cap],'forbidden');
        $request=$request->withUser($user)->withRoute($route);
        if(in_array($method,['POST','PUT','PATCH','DELETE'],true)){
            $token=(string)($request->body('_token')??$request->header('x-csrf-token',''));
            if(!$this->container->get(CsrfTokenManager::class)->validate($token)) return $this->api->error('Ungültiges oder abgelaufenes CSRF-Token.',419,[],'csrf_failed');
        }
        $bootstrap=rtrim((string)$route['module_path'],'/\\').'/bootstrap.php'; if(is_file($bootstrap)) require_once $bootstrap; $this->lifecycle?->run((string)$route['module'],(string)$route['module_path'],'beforeApi',['route'=>$routeName,'method'=>$method]);
        try{
            [$class,$action]=$this->parse((string)$route['controller']);
            if(!class_exists($class)) return $this->api->error('API-Controller wurde nicht gefunden.',500,[],'controller_missing');
            $controller=$this->container->build($class);
            if(!method_exists($controller,$action)||!is_callable([$controller,$action])) return $this->api->error('API-Controller-Aktion wurde nicht gefunden.',500,[],'controller_action_missing');
            $result=$this->container->call([$controller,$action],['request'=>$request,'user'=>$user,'route'=>$route]);
            $this->lifecycle?->run((string)$route['module'],(string)$route['module_path'],'afterApi',['route'=>$routeName,'method'=>$method]);
            return $result instanceof Response ? $result : $this->api->success($result);
        }catch(ValidationException $e){
            return $this->api->error('Validierung fehlgeschlagen.',422,$e->errors(),'validation_failed');
        }catch(\Throwable $e){
            return $this->api->error('Interner Modul-API-Fehler.',500,['type'=>$e::class],'internal_error');
        }
    }
    private function parse(string $definition): array
    {
        if(!str_contains($definition,'@')) throw new \RuntimeException('Ungültige API-Controllerdefinition.');
        [$class,$action]=array_map('trim',explode('@',$definition,2));
        if($class===''||$action===''||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$action)) throw new \RuntimeException('Ungültige API-Controllerdefinition.');
        return [$class,$action];
    }
}
