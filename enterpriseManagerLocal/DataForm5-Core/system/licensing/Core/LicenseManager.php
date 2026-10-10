<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Core;
use DataForm5\Licensing\Contracts\LicenseManagerInterface;
use DataForm5\Licensing\Contracts\LicenseProviderInterface;
use DataForm5\Licensing\Exceptions\LicenseException;
final class LicenseManager implements LicenseManagerInterface
{
    private bool $loaded=false; private ?License $license=null;
    public function __construct(private readonly LicenseProviderInterface $provider,private readonly bool $allowCommunity=true){}
    public function license(): ?License{if(!$this->loaded){$this->license=$this->provider->load();$this->loaded=true;}return $this->license;}
    public function status(?\DateTimeImmutable $at=null): string{return $this->license()?->status($at) ?? ($this->allowCommunity?'community':'missing');}
    public function valid(?\DateTimeImmutable $at=null): bool{return $this->license()?->isUsable($at) ?? $this->allowCommunity;}
    public function allows(string $capability,array $context=[]): bool
    {
        $license=$this->license();
        if(!$license) return $this->allowCommunity && str_starts_with($capability,'core.');
        if(!$license->isUsable($context['at']??null)) return false;
        if(isset($context['product']) && !$license->hasProduct((string)$context['product'])) return false;
        return $license->hasCapability($capability);
    }
    public function requireCapability(string $capability,array $context=[]): void{if(!$this->allows($capability,$context))throw new LicenseException("Capability not licensed: {$capability}");}
    public function summary(?\DateTimeImmutable $at=null): array{return $this->license()?->toArray($at) ?? ['status'=>$this->status($at),'edition'=>'community','capabilities'=>['core.*']];}
}
