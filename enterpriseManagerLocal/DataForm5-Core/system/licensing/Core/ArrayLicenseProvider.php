<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Core;
use DataForm5\Licensing\Contracts\LicenseProviderInterface;
final class ArrayLicenseProvider implements LicenseProviderInterface
{
    public function __construct(private readonly array $configuration){}
    public function load(): ?License
    {
        if (($this->configuration['mode'] ?? 'community') === 'none') return null;
        return License::fromArray((array)($this->configuration['license'] ?? []));
    }
}
