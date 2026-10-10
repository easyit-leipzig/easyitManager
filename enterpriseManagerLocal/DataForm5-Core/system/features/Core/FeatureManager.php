<?php
declare(strict_types=1);
namespace DataForm5\Features\Core;
use DataForm5\Features\Contracts\FeatureManagerInterface;
final class FeatureManager implements FeatureManagerInterface
{
    /** @var array<string,FeatureDefinition> */ private array $features=[];
    public function __construct(array $features=[],private readonly array $defaults=[]){foreach($features as $name=>$config)$this->define((string)$name,(array)$config);}
    public function define(string $name,array|bool $config):self{$this->features[$name]=new FeatureDefinition($name,is_bool($config)?['enabled'=>$config]:$config);return $this;}
    public function enabled(string $feature,array $context=[]):bool{return ($this->features[$feature]??new FeatureDefinition($feature,['enabled'=>(bool)($this->defaults['enabled']??false)]))->isEnabled($context);}
    public function disabled(string $feature,array $context=[]):bool{return !$this->enabled($feature,$context);}
    public function value(string $feature,mixed $default=null):mixed{return isset($this->features[$feature])?$this->features[$feature]->value($default):$default;}
    public function all():array{return array_map(fn(FeatureDefinition $f)=>$f->toArray(),$this->features);}
}
