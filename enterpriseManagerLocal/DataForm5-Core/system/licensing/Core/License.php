<?php
declare(strict_types=1);
namespace DataForm5\Licensing\Core;
use InvalidArgumentException;
final class License
{
    /** @param list<string> $capabilities @param list<string> $products */
    public function __construct(
        private readonly string $id,
        private readonly string $holder,
        private readonly string $edition = 'community',
        private readonly array $capabilities = [],
        private readonly array $products = [],
        private readonly ?\DateTimeImmutable $validFrom = null,
        private readonly ?\DateTimeImmutable $expiresAt = null,
        private readonly int $graceDays = 0,
        private readonly bool $enabled = true,
        private readonly array $metadata = []
    ) {
        if ($id === '') throw new InvalidArgumentException('License id must not be empty.');
        if ($graceDays < 0) throw new InvalidArgumentException('Grace days must not be negative.');
    }
    public static function fromArray(array $data): self
    {
        $date = static fn(mixed $value): ?\DateTimeImmutable => ($value === null || $value === '') ? null : new \DateTimeImmutable((string)$value);
        $metadata=(array)($data['metadata'] ?? []);
        if(isset($data['customer_key']) && trim((string)$data['customer_key'])!=='') $metadata['customer_key']=mb_strtolower(trim((string)$data['customer_key']),'UTF-8');
        return new self((string)($data['id'] ?? 'local'),(string)($data['holder'] ?? 'Local installation'),(string)($data['edition'] ?? 'community'),array_values(array_unique(array_map('strval',(array)($data['capabilities'] ?? [])))),array_values(array_unique(array_map('strval',(array)($data['products'] ?? [])))),$date($data['valid_from'] ?? null),$date($data['expires_at'] ?? null),(int)($data['grace_days'] ?? 0),(bool)($data['enabled'] ?? true),$metadata);
    }
    public function id(): string{return $this->id;} public function holder(): string{return $this->holder;} public function edition(): string{return $this->edition;}
    public function capabilities(): array{return $this->capabilities;} public function products(): array{return $this->products;} public function metadata(): array{return $this->metadata;}
    public function validFrom(): ?\DateTimeImmutable{return $this->validFrom;} public function expiresAt(): ?\DateTimeImmutable{return $this->expiresAt;} public function graceDays(): int{return $this->graceDays;} public function enabled(): bool{return $this->enabled;}
    public function status(?\DateTimeImmutable $at=null): string
    {
        $at ??= new \DateTimeImmutable('now');
        if (!$this->enabled) return 'disabled';
        if ($this->validFrom && $at < $this->validFrom) return 'not_yet_valid';
        if (!$this->expiresAt || $at <= $this->expiresAt) return 'active';
        $graceEnd=$this->expiresAt->modify('+'.$this->graceDays.' days');
        return $this->graceDays>0 && $at <= $graceEnd ? 'grace' : 'expired';
    }
    public function isUsable(?\DateTimeImmutable $at=null): bool{return in_array($this->status($at),['active','grace'],true);}
    public function hasCapability(string $capability): bool
    {
        foreach ($this->capabilities as $allowed) {
            if ($allowed === '*' || $allowed === $capability) return true;
            if (str_ends_with($allowed, '*') && str_starts_with($capability, substr($allowed, 0, -1))) return true;
        }
        return false;
    }
    public function hasProduct(string $product): bool{return $this->products===[]||in_array('*',$this->products,true)||in_array($product,$this->products,true);}
    public function toArray(?\DateTimeImmutable $at=null): array{return ['id'=>$this->id,'customer_key'=>(string)($this->metadata['customer_key']??''),'holder'=>$this->holder,'edition'=>$this->edition,'status'=>$this->status($at),'capabilities'=>$this->capabilities,'products'=>$this->products,'valid_from'=>$this->validFrom?->format(DATE_ATOM),'expires_at'=>$this->expiresAt?->format(DATE_ATOM),'grace_days'=>$this->graceDays,'enabled'=>$this->enabled,'metadata'=>$this->metadata];}
}
