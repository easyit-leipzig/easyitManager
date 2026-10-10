<?php
declare(strict_types=1);
namespace DataForm5\Modules\Routing;

use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;
use DataForm5\Validation\Exceptions\ValidationException;
use DataForm5\Security\Csrf\CsrfTokenManager;
use Throwable;
use DataForm5\Modules\Lifecycle\ModuleLifecycleManager;

final class ModuleRouteDispatcher
{
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly ModuleRouteRegistry $routes,
        private readonly ?ModuleLifecycleManager $lifecycle = null
    ) {}

    public function dispatch(string $routeName,string $method,Request|array $request,array $user): mixed
    {
        $route=$this->routes->get($routeName);
        if($route===null) throw new \RuntimeException('Modulroute nicht gefunden.',404);
        $method=strtoupper($method);
        if(!in_array($method,$route['methods'],true)) throw new \RuntimeException('HTTP-Methode für diese Modulroute nicht erlaubt.',405);
        $capability=(string)$route['capability'];
        if($capability!=='' && function_exists('enterprise_can') && !enterprise_can($user,$capability)) {
            throw new \RuntimeException('Berechtigung fehlt: '.$capability,403);
        }
        if(is_array($request)) $request=Request::fromLegacy($method,$request,'/module/'.$routeName);
        $request=$request->withUser($user)->withRoute($route);
        if(in_array($method,['POST','PUT','PATCH','DELETE'],true)) {
            $token=(string)($request->body('_token')??$request->header('x-csrf-token',''));
            if(!$this->container->get(CsrfTokenManager::class)->validate($token)) throw new \RuntimeException('Ungültiges oder abgelaufenes CSRF-Token.',419);
        }
        $bootstrap=rtrim((string)$route['module_path'],'/\\').'/bootstrap.php';
        if(is_file($bootstrap)) require_once $bootstrap;
        $this->lifecycle?->run((string)$route['module'],(string)$route['module_path'],'beforeRequest',['route'=>$routeName,'method'=>$method]);
        [$class,$action]=$this->parseController((string)$route['controller']);
        if(!class_exists($class)) throw new \RuntimeException("Controller '{$class}' wurde nicht gefunden.",500);
        $controller=$this->container->build($class);
        if(!method_exists($controller,$action) || !is_callable([$controller,$action])) throw new \RuntimeException("Controller-Aktion '{$class}@{$action}' wurde nicht gefunden.",500);
        try {
            $requestArgument=$request;
            $methodReflection=new \ReflectionMethod($controller,$action);
            foreach($methodReflection->getParameters() as $parameter){
                if($parameter->getName()!=='request') continue;
                $type=$parameter->getType();
                if($type instanceof \ReflectionNamedType && $type->isBuiltin() && $type->getName()==='array') $requestArgument=$request->toLegacyArray();
                break;
            }
            $result=$this->container->call([$controller,$action],[
                'request'=>$requestArgument,
                'user'=>$user,
                'route'=>$route,
            ]);
            $this->lifecycle?->run((string)$route['module'],(string)$route['module_path'],'afterRequest',['route'=>$routeName,'method'=>$method]);
            return $result;
        } catch(ValidationException $e) {
            if($request->expectsJson()) return Response::json(['error'=>'validation_failed','errors'=>$e->errors()],422);
            $messages=[]; foreach($e->errors() as $field=>$errors) foreach($errors as $error) $messages[]=$field.': '.$error;
            return Response::page('<div class="notice error">'.htmlspecialchars(implode(' | ',$messages),ENT_QUOTES,'UTF-8').'</div>',(string)($route['title']??'Modul'),422);
        }
    }

    /** @return array{0:string,1:string} */
    private function parseController(string $definition): array
    {
        if(!str_contains($definition,'@')) throw new \RuntimeException("Ungültige Controllerdefinition '{$definition}'.");
        [$class,$action]=array_map('trim',explode('@',$definition,2));
        if($class==='' || $action==='' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$action)) throw new \RuntimeException("Ungültige Controllerdefinition '{$definition}'.");
        return [$class,$action];
    }
}
