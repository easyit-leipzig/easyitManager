<?php
declare(strict_types=1);
namespace DataForm5\Testing\Contracts;
interface TestInterface
{
    public function name(): string;
    public function run(): void;
}
