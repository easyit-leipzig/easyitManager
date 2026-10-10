<?php
declare(strict_types=1);
namespace DataForm5\Rules\Core;
final readonly class Decision
{
    public function __construct(public string $group, public bool $matched, public mixed $value, public array $outcomes) {}
    public function reasons(): array { return array_values(array_filter(array_map(fn(array $o)=>$o['reason']??'', $this->outcomes))); }
}
