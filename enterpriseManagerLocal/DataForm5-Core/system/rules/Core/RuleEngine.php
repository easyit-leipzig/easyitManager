<?php
declare(strict_types=1);
namespace DataForm5\Rules\Core;
use DataForm5\Rules\Contracts\RuleInterface;
use DataForm5\Rules\Exceptions\RuleException;
final class RuleEngine
{
    /** @var array<string,array<string,RuleInterface>> */ private array $groups=[];
    public function add(string $group, RuleInterface $rule): self
    {
        if($group===''||$rule->name()==='') throw new RuleException('Regelgruppe und Regelname dürfen nicht leer sein.');
        $this->groups[$group][$rule->name()]=$rule; return $this;
    }
    public function rule(string $group,string $name,callable $callback,int $priority=0): self{return $this->add($group,new CallbackRule($name,$callback,$priority));}
    public function evaluate(string $group,array|FactContext $facts,string $strategy='first'): Decision
    {
        if(!isset($this->groups[$group])) throw new RuleException("Unbekannte Regelgruppe: {$group}");
        $context=$facts instanceof FactContext?$facts:new FactContext($facts);
        $rules=array_values($this->groups[$group]); usort($rules,fn(RuleInterface $a,RuleInterface $b)=>$b->priority()<=>$a->priority());
        $outcomes=[];$matched=false;$value=null;
        foreach($rules as $rule){$outcome=$rule->evaluate($context);$outcomes[]=['rule'=>$rule->name(),'priority'=>$rule->priority(),'matched'=>$outcome->matched,'value'=>$outcome->value,'reason'=>$outcome->reason,'metadata'=>$outcome->metadata];if($outcome->matched){$matched=true;$value=$outcome->value;if($strategy==='first')break;}}
        if(!in_array($strategy,['first','all'],true)) throw new RuleException("Unbekannte Auswertungsstrategie: {$strategy}");
        return new Decision($group,$matched,$strategy==='all'?array_values(array_map(fn($o)=>$o['value'],array_filter($outcomes,fn($o)=>$o['matched']))):$value,$outcomes);
    }
    public function groups(): array{return array_keys($this->groups);}
}
