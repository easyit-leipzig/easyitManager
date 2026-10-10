<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Recovery;

final class RecoveryCheck implements \JsonSerializable
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const SKIP = 'skip';

    public function __construct(
        private string $id,
        private string $title,
        private string $status,
        private string $message,
        private array $details = [],
        private ?string $recommendation = null
    ) {
        if (!in_array($status, [self::PASS, self::FAIL, self::WARN, self::SKIP], true)) {
            throw new \InvalidArgumentException('Ungültiger Recovery-Status: ' . $status);
        }
    }

    public function getStatus(): string { return $this->status; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'group' => 'project-recovery',
            'title' => $this->title,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'recommendation' => $this->recommendation,
        ];
    }
}
