<?php
declare(strict_types=1);
namespace DataForm5\Hardening\Contracts;
interface ProductionGateInterface { public function inspect(): array; public function assertReady(): void; }
