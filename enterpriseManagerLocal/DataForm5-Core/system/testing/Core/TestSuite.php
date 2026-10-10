<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use DataForm5\Testing\Contracts\TestInterface;
final class TestSuite
{
    /** @var list<TestInterface> */ private array $tests=[];
    public function __construct(private string $name='default') {}
    public function add(TestInterface $test): self { $this->tests[]=$test; return $this; }
    /** @return list<TestInterface> */ public function tests(): array { return $this->tests; }
    public function name(): string { return $this->name; }
}
