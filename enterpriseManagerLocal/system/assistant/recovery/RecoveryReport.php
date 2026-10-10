<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Recovery;

final class RecoveryReport implements \JsonSerializable
{
    /** @var list<RecoveryCheck> */
    private array $checks = [];

    public function __construct(private string $projectId, private ?string $generatedAt = null)
    {
        $this->generatedAt ??= gmdate('c');
    }

    public function add(RecoveryCheck $check): void { $this->checks[] = $check; }
    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->getStatus() === RecoveryCheck::FAIL) { return true; }
        }
        return false;
    }
    public function counts(): array
    {
        $counts = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0];
        foreach ($this->checks as $check) { $counts[$check->getStatus()]++; }
        return $counts;
    }
    public function verdict(): string
    {
        $counts = $this->counts();
        if ($counts['fail'] > 0) { return 'FAIL'; }
        if ($counts['warn'] > 0) { return 'PASS_WITH_WARNINGS'; }
        return 'PASS';
    }
    public function jsonSerialize(): array
    {
        return [
            'schema' => 'easyit.project.recovery-report.v1',
            'project' => $this->projectId,
            'generatedAt' => $this->generatedAt,
            'verdict' => $this->verdict(),
            'counts' => $this->counts(),
            'checks' => array_map(static fn (RecoveryCheck $c): array => $c->jsonSerialize(), $this->checks),
        ];
    }
}
