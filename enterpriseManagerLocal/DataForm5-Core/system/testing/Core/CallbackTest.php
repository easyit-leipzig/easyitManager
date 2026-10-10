<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
use Closure;
use DataForm5\Testing\Contracts\TestInterface;
final class CallbackTest implements TestInterface
{
    private Closure $callback;
    public function __construct(private string $testName, callable $callback) { $this->callback=Closure::fromCallable($callback); }
    public function name(): string { return $this->testName; }
    public function run(): void { ($this->callback)(); }
}
