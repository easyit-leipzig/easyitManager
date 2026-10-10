<?php
declare(strict_types=1);
namespace DataForm5\Compatibility\Core;
final class DeprecationRegistry
{
    private array $items = [];
    public function add(string $identifier, string $since, string $replacement = '', ?string $removeIn = null): self
    {
        $this->items[$identifier] = ['identifier'=>$identifier,'since'=>$since,'replacement'=>$replacement,'remove_in'=>$removeIn];
        return $this;
    }
    public function all(): array { return array_values($this->items); }
    public function get(string $identifier): ?array { return $this->items[$identifier] ?? null; }
    public function activeFor(string $version): array
    {
        return array_values(array_filter($this->items, fn(array $item): bool => version_compare($version, $item['since'], '>=')));
    }
}
