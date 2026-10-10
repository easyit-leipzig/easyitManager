<?php
declare(strict_types=1);

namespace DataForm5\Licensing\Core;

use DataForm5\Licensing\Contracts\LicenseProviderInterface;
use PDO;

final class PdoLicenseProvider implements LicenseProviderInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'enterprise_licenses'
    ) {}

    public function load(): ?License
    {
        $sql = "SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY is_primary DESC, id DESC LIMIT 1";
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $metadata = $this->jsonMap($row['metadata_json'] ?? null);
        $customerKey = mb_strtolower(trim((string)($row['customer_key'] ?? ($metadata['customer_key'] ?? ''))), 'UTF-8');
        if ($customerKey !== '') $metadata['customer_key'] = $customerKey;

        return License::fromArray([
            'id' => (string)$row['license_key'],
            'customer_key' => $customerKey,
            'holder' => (string)$row['holder'],
            'edition' => (string)$row['edition'],
            'capabilities' => $this->jsonList($row['capabilities_json'] ?? null),
            'products' => $this->jsonList($row['products_json'] ?? null),
            'valid_from' => $row['valid_from'] ?: null,
            'expires_at' => $row['expires_at'] ?: null,
            'grace_days' => (int)$row['grace_days'],
            'enabled' => (bool)$row['enabled'],
            'metadata' => $metadata,
        ]);
    }

    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : [];
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function jsonMap(mixed $value): array
    {
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
