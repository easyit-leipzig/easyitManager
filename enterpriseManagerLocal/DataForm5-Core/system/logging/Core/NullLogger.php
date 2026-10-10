<?php
declare(strict_types=1);
namespace DataForm5\Logging\Core;
final class NullLogger extends AbstractLogger { public function log(string $level,string $message,array $context=[]):void { LogLevel::validate($level); } }
