<?php
declare(strict_types=1);
namespace DataForm5\ReleaseCandidate\Contracts;
interface ReleaseCandidateManagerInterface
{
    public function inspect(): array;
    public function assertReady(): array;
    public function writeReport(?string $path = null): array;
}
