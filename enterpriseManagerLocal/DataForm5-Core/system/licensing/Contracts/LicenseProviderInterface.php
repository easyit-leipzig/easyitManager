<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Contracts;
use DataForm5\Licensing\Core\License;
interface LicenseProviderInterface
{
    public function load(): ?License;
}
