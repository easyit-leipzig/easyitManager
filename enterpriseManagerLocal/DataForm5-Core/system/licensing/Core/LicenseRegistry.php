<?php
declare(strict_types=1);

namespace DataForm5\Licensing\Core;

use PDO;

final class LicenseRegistry
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM enterprise_licenses ORDER BY is_primary DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM enterprise_licenses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) $this->pdo->exec('UPDATE enterprise_licenses SET is_primary = 0');
            $customerKey = mb_strtolower(trim((string)($data['customer_key'] ?? '')), 'UTF-8');
            if ($customerKey === '' || !filter_var($customerKey, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('customer_key must be a valid email address.');
            }
            $holder = trim((string)($data['holder'] ?? ''));
            if ($holder === '') $holder = $customerKey;
            $metadata = (array)($data['metadata'] ?? []);
            $metadata['customer_key'] = $customerKey;
            $stmt = $this->pdo->prepare('INSERT INTO enterprise_licenses
                (license_key, customer_key, holder, edition, capabilities_json, products_json, valid_from, expires_at, grace_days, enabled, is_primary, metadata_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                trim((string)$data['license_key']),
                $customerKey,
                $holder,
                trim((string)($data['edition'] ?? 'community')),
                json_encode($this->normalizeList($data['capabilities'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeList($data['products'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $this->nullableDate($data['valid_from'] ?? null),
                $this->nullableDate($data['expires_at'] ?? null),
                max(0, (int)($data['grace_days'] ?? 0)),
                !empty($data['enabled']) ? 1 : 0,
                !empty($data['is_primary']) ? 1 : 0,
                json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE enterprise_licenses SET enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    public function makePrimary(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE enterprise_licenses SET is_primary = 0');
            $stmt = $this->pdo->prepare('UPDATE enterprise_licenses SET is_primary = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM enterprise_licenses WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function normalizeList(array|string $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\r\n,;]+/', $value) ?: []);
        $items = array_map(static fn($v) => trim((string)$v), $items);
        return array_values(array_unique(array_filter($items, static fn($v) => $v !== '')));
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
