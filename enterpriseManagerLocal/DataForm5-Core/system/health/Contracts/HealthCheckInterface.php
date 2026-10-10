<?php
declare(strict_types=1);
namespace DataForm5\Health\Contracts;
use DataForm5\Health\Core\HealthResult;
interface HealthCheckInterface
{
    public function name(): string;
    public function run(): HealthResult;
}
