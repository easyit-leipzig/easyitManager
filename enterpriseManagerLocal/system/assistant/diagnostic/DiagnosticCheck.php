<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Diagnostic;

final class DiagnosticCheck implements \JsonSerializable
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const SKIP = 'skip';

    public function __construct(
        private string $id,
        private string $group,
        private string $title,
        private string $status,
        private string $message,
        private array $details = [],
        private ?string $recommendation = null
    ) {
        if (!in_array($status, [self::PASS, self::FAIL, self::WARN, self::SKIP], true)) {
            throw new \InvalidArgumentException('Ungültiger Diagnose-Status: ' . $status);
        }
    }

    public function getStatus(): string { return $this->status; }
    public function getId(): string { return $this->id; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group,
            'title' => $this->title,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'recommendation' => $this->recommendation,
        ];
    }
}
