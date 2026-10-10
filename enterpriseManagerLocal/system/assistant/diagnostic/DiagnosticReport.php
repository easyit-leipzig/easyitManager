<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Diagnostic;

final class DiagnosticReport implements \JsonSerializable
{
    /** @var list<DiagnosticCheck> */
    private array $checks = [];

    public function __construct(
        private string $projectId,
        private string $dataFormId,
        private ?string $generatedAt = null
    ) {
        $this->generatedAt ??= gmdate('c');
    }

    public function add(DiagnosticCheck $check): void { $this->checks[] = $check; }
    /** @return list<DiagnosticCheck> */
    public function getChecks(): array { return $this->checks; }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->getStatus() === DiagnosticCheck::FAIL) { return true; }
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
            'schema' => 'easyit.dataform.diagnostic-report.v1',
            'project' => $this->projectId,
            'dataForm' => $this->dataFormId,
            'generatedAt' => $this->generatedAt,
            'verdict' => $this->verdict(),
            'counts' => $this->counts(),
            'checks' => array_map(static fn (DiagnosticCheck $check): array => $check->jsonSerialize(), $this->checks),
        ];
    }
}
