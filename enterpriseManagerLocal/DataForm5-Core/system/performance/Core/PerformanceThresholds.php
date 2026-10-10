<?php
declare(strict_types=1);
namespace DataForm5\Performance\Core;
final class PerformanceThresholds
{
    public function __construct(private array $thresholds = []) {}
    public function millisecondsFor(string $name): ?float
    {
        if (array_key_exists($name, $this->thresholds)) return (float)$this->thresholds[$name];
        if (array_key_exists('*', $this->thresholds)) return (float)$this->thresholds['*'];
        return null;
    }
    public function classify(string $name, float $durationMs): string
    {
        $limit=$this->millisecondsFor($name);
        if ($limit===null) return 'unclassified';
        if ($durationMs <= $limit) return 'ok';
        if ($durationMs <= $limit * 2) return 'warning';
        return 'critical';
    }
}
