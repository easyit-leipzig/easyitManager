<?php
declare(strict_types=1);
namespace DataForm5\Compatibility\Core;
use DataForm5\Compatibility\Contracts\CompatibilityManagerInterface;
use DataForm5\Compatibility\Exceptions\CompatibilityException;
final class CompatibilityManager implements CompatibilityManagerInterface
{
    public function __construct(
        private string $currentVersion,
        private string $minimumVersion,
        private array $upgradeSteps = [],
        private ?DeprecationRegistry $deprecations = null
    ) { $this->deprecations ??= new DeprecationRegistry(); }
    public function currentVersion(): string { return $this->currentVersion; }
    public function supports(string $version): bool
    {
        return version_compare($version, $this->minimumVersion, '>=') && (new Version($version))->major() <= (new Version($this->currentVersion))->major();
    }
    public function inspect(string $fromVersion, ?string $toVersion = null): array
    {
        $toVersion ??= $this->currentVersion;
        $errors=[];$warnings=[];
        if (!$this->supports($fromVersion)) $errors[]="Ausgangsversion {$fromVersion} wird nicht unterstützt.";
        if (version_compare($fromVersion, $toVersion, '>')) $errors[]='Downgrades sind über den Upgrade-Layer nicht zulässig.';
        if ((new Version($toVersion))->major() > (new Version($this->currentVersion))->major()) $errors[]="Zielversion {$toVersion} liegt über der Core-Version {$this->currentVersion}.";
        if ((new Version($fromVersion))->major() !== (new Version($toVersion))->major()) $warnings[]='Major-Upgrade: vollständiges Backup und manuelle Freigabe erforderlich.';
        return ['compatible'=>$errors===[],'from'=>$fromVersion,'to'=>$toVersion,'minimum'=>$this->minimumVersion,'errors'=>$errors,'warnings'=>$warnings,'deprecations'=>$this->deprecations->activeFor($toVersion)];
    }
    public function plan(string $fromVersion, ?string $toVersion = null): array
    {
        $inspection=$this->inspect($fromVersion,$toVersion);
        if (!$inspection['compatible']) throw new CompatibilityException(implode(' ', $inspection['errors']));
        $toVersion=$inspection['to'];
        $steps=array_values(array_filter($this->upgradeSteps, fn(array $step): bool => version_compare($step['version'],$fromVersion,'>') && version_compare($step['version'],$toVersion,'<=')));
        usort($steps, fn(array $a,array $b): int => version_compare($a['version'],$b['version']));
        return ['from'=>$fromVersion,'to'=>$toVersion,'requires_backup'=>true,'steps'=>$steps,'warnings'=>$inspection['warnings'],'deprecations'=>$inspection['deprecations']];
    }
}
