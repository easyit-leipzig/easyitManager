<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class ConnectionTestResult implements \JsonSerializable
{
    /** @param list<string> $warnings */
    public function __construct(
        private bool $ok,
        private string $message,
        private array $details = [],
        private array $warnings = []
    ) {}

    public static function success(string $message = 'Verbindung erfolgreich.', array $details = [], array $warnings = []): self
    {
        return new self(true, $message, $details, $warnings);
    }

    public static function failure(string $message, array $details = [], array $warnings = []): self
    {
        return new self(false, $message, $details, $warnings);
    }

    public function isOk(): bool { return $this->ok; }
    public function getMessage(): string { return $this->message; }
    public function getDetails(): array { return $this->details; }
    public function getWarnings(): array { return $this->warnings; }

    public function jsonSerialize(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'details' => $this->details,
            'warnings' => $this->warnings,
        ];
    }
}
