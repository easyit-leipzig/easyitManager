<?php
declare(strict_types=1);
namespace DataForm5\Rules\Contracts;
use DataForm5\Rules\Core\FactContext;
use DataForm5\Rules\Core\RuleOutcome;
interface RuleInterface
{
    public function name(): string;
    public function priority(): int;
    public function evaluate(FactContext $facts): RuleOutcome;
}
