<?php
declare(strict_types=1);
namespace DataForm5\FinalRelease\Contracts;
interface FinalReleaseManagerInterface
{
    public function inspect(): array;
    public function assertReady(): array;
    public function writeReport(?string $path = null): array;
    public function writeManifest(?string $path = null): array;
}
