<?php
declare(strict_types=1);
namespace DataForm5\Security\Csrf;
use DataForm5\Security\Contracts\SessionStoreInterface;
final class CsrfTokenManager {
    private const KEY = '_csrf_token';
    public function __construct(private readonly SessionStoreInterface $session) {}
    public function token(): string { $token = $this->session->get(self::KEY); if (!is_string($token) || $token === '') { $token = bin2hex(random_bytes(32)); $this->session->put(self::KEY, $token); } return $token; }
    public function validate(?string $token): bool { return is_string($token) && hash_equals($this->token(), $token); }
    public function regenerate(): string { $this->session->forget(self::KEY); return $this->token(); }
    public function field(): string { return '<input type="hidden" name="_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">'; }
}
