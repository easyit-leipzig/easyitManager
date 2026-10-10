<?php
declare(strict_types=1);
namespace DataForm5\Compatibility\Core;
use InvalidArgumentException;
final class Version
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $value)) {
            throw new InvalidArgumentException("Ungültige Version: {$value}");
        }
    }
    public function compare(self $other): int { return version_compare($this->value, $other->value); }
    public function major(): int { return (int) explode('.', $this->value)[0]; }
}
