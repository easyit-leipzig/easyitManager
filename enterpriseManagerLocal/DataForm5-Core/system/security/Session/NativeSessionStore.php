<?php
declare(strict_types=1);
namespace DataForm5\Security\Session;
use DataForm5\Security\Contracts\SessionStoreInterface;
use DataForm5\Security\SecurityException;
final class NativeSessionStore implements SessionStoreInterface {
    public function __construct(private readonly string $name = 'DATAFORM5SESSID') {}
    public function start(): void { if (session_status() === PHP_SESSION_ACTIVE) return; if (headers_sent()) throw new SecurityException('Session kann nach gesendeten Headern nicht gestartet werden.'); session_name($this->name); if (!session_start()) throw new SecurityException('Session konnte nicht gestartet werden.'); }
    public function get(string $key, mixed $default = null): mixed { $this->start(); return $_SESSION[$key] ?? $default; }
    public function put(string $key, mixed $value): void { $this->start(); $_SESSION[$key] = $value; }
    public function forget(string $key): void { $this->start(); unset($_SESSION[$key]); }
    public function regenerate(): void { $this->start(); session_regenerate_id(true); }
    public function invalidate(): void { $this->start(); $_SESSION = []; session_regenerate_id(true); }
}
