<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

use DataForm5\Core\Container\ServiceContainer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

final class ContainerInspector
{
    public function __construct(private readonly ServiceContainer $container) {}

    public function inspect(): array
    {
        $services=$this->container->describe();
        $aliases=$this->container->aliases();
        $rows=[];

        foreach($services as $id=>$meta){
            $class=$this->classCandidate((string)$id);
            $deps=[];
            $instantiable=null;
            $abstract=null;

            if($class!==null && class_exists($class)){
                try{
                    $reflection=new ReflectionClass($class);
                    $instantiable=$reflection->isInstantiable();
                    $abstract=$reflection->isAbstract();
                    $constructor=$reflection->getConstructor();
                    if($constructor!==null){
                        foreach($constructor->getParameters() as $parameter){
                            $type=$parameter->getType();
                            $types=[];
                            if($type instanceof ReflectionNamedType){
                                $types[]=$type->getName();
                            }elseif($type instanceof ReflectionUnionType){
                                foreach($type->getTypes() as $unionType){
                                    if($unionType instanceof ReflectionNamedType)$types[]=$unionType->getName();
                                }
                            }
                            $deps[]=[
                                'parameter'=>$parameter->getName(),
                                'types'=>$types,
                                'optional'=>$parameter->isOptional()||$parameter->allowsNull(),
                                'service_candidates'=>array_values(array_filter(
                                    $types,
                                    fn(string $candidate):bool=>!in_array($candidate,['string','int','float','bool','array','callable','iterable','mixed','object','null'],true)
                                )),
                            ];
                        }
                    }
                }catch(\Throwable){}
            }

            $rows[$id]=[
                'id'=>$id,
                'shared'=>(bool)($meta['shared']??false),
                'resolved'=>(bool)($meta['resolved']??false),
                'tags'=>(array)($meta['tags']??[]),
                'class'=>$class,
                'instantiable'=>$instantiable,
                'abstract'=>$abstract,
                'dependencies'=>$deps,
                'aliases'=>array_keys(array_filter($aliases,static fn(string $target):bool=>$target===$id)),
            ];
        }

        ksort($rows);
        return [
            'summary'=>[
                'services'=>count($rows),
                'resolved'=>count(array_filter($rows,static fn(array $r):bool=>$r['resolved'])),
                'singletons'=>count(array_filter($rows,static fn(array $r):bool=>$r['shared'])),
                'aliases'=>count($aliases),
                'with_dependencies'=>count(array_filter($rows,static fn(array $r):bool=>$r['dependencies']!==[])),
            ],
            'services'=>$rows,
            'aliases'=>$aliases,
        ];
    }

    private function classCandidate(string $id): ?string
    {
        if(class_exists($id)||interface_exists($id)) return $id;
        return null;
    }
}
