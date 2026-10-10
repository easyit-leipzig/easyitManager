<?php
declare(strict_types=1);
namespace DataForm5\Rules\Core;
use Closure;
use DataForm5\Rules\Contracts\RuleInterface;
final class CallbackRule implements RuleInterface
{
    private Closure $callback;
    public function __construct(private string $ruleName, callable $callback, private int $rulePriority=0){$this->callback=Closure::fromCallable($callback);}
    public function name(): string{return $this->ruleName;}
    public function priority(): int{return $this->rulePriority;}
    public function evaluate(FactContext $facts): RuleOutcome
    {
        $result=($this->callback)($facts);
        if($result instanceof RuleOutcome)return $result;
        return $result ? RuleOutcome::match($result) : RuleOutcome::noMatch();
    }
}
