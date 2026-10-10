<?php
declare(strict_types=1);
namespace DataForm5\Rules\Core;
final readonly class RuleOutcome
{
    public function __construct(public bool $matched, public mixed $value=null, public string $reason='', public array $metadata=[]) {}
    public static function match(mixed $value=true,string $reason='',array $metadata=[]): self { return new self(true,$value,$reason,$metadata); }
    public static function noMatch(string $reason='',array $metadata=[]): self { return new self(false,null,$reason,$metadata); }
}
