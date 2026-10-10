<?php
declare(strict_types=1);
namespace DataForm5\Documentation\Core;
use RecursiveDirectoryIterator;use RecursiveIteratorIterator;use FilesystemIterator;use ReflectionClass;use Throwable;
final class ComponentCatalog
{
    public function __construct(private string $basePath){}
    /** @return list<array<string,mixed>> */
    public function scan(): array
    {
        $system=$this->basePath.'/system';$items=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($system,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){
            if(!$file->isFile()||$file->getExtension()!=='php')continue;
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($system)+1));
            if(str_starts_with($relative,'database/0'))continue;
            $source=(string)file_get_contents($file->getPathname());
            if(!preg_match('/namespace\s+([^;]+);/',$source,$nm)||!preg_match('/\b(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',$source,$cm))continue;
            $class=trim($nm[1]).'\\'.$cm[2];
            try{if(!class_exists($class)&&!interface_exists($class)&&!trait_exists($class)&&!(function_exists('enum_exists')&&enum_exists($class)))require_once $file->getPathname();$r=new ReflectionClass($class);$methods=[];foreach($r->getMethods() as $method){if($method->isPublic()&&$method->getDeclaringClass()->getName()===$class)$methods[]=$method->getName();}
                $items[]=['name'=>$r->getShortName(),'class'=>$class,'kind'=>$r->isInterface()?'interface':($r->isTrait()?'trait':'class'),'layer'=>explode('/',$relative)[0],'file'=>'system/'.$relative,'methods'=>$methods,'final'=>$r->isFinal(),'abstract'=>$r->isAbstract()];
            }catch(Throwable){continue;}
        }
        usort($items,fn(array $a,array $b)=>strcmp($a['class'],$b['class']));return $items;
    }
    /** @return array<string,int> */
    public function summary(array $items): array
    { $s=['total'=>count($items),'classes'=>0,'interfaces'=>0,'traits'=>0,'layers'=>0];$layers=[];foreach($items as $i){$layers[$i['layer']]=true;$key=$i['kind']==='class'?'classes':($i['kind']==='interface'?'interfaces':'traits');$s[$key]++;}$s['layers']=count($layers);return $s; }
}
