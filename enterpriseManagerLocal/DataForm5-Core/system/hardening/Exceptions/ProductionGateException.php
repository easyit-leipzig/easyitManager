<?php
declare(strict_types=1);
namespace DataForm5\Hardening\Exceptions;
use RuntimeException;
final class ProductionGateException extends RuntimeException { public function __construct(public readonly array $report){parent::__construct('Die Produktionsfreigabe ist blockiert.');} }
