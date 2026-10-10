<?php
declare(strict_types=1);
namespace DataForm5\Installer\Core;
final class RequirementResult
{
    public function __construct(public readonly string $name, public readonly bool $passed, public readonly bool $required = true, public readonly string $message = '') {}
    public function toArray(): array { return ['name'=>$this->name,'passed'=>$this->passed,'required'=>$this->required,'message'=>$this->message]; }
}
