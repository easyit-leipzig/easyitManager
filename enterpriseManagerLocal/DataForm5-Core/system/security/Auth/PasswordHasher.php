<?php
declare(strict_types=1);
namespace DataForm5\Security\Auth;
use DataForm5\Security\SecurityException;
final class PasswordHasher {
    public function __construct(private readonly string|int|null $algorithm = PASSWORD_DEFAULT, private readonly array $options = []) {}
    public function hash(string $password): string { $hash = password_hash($password, $this->algorithm, $this->options); if ($hash === false) throw new SecurityException('Passwort konnte nicht gehasht werden.'); return $hash; }
    public function verify(string $password, string $hash): bool { return password_verify($password, $hash); }
    public function needsRehash(string $hash): bool { return password_needs_rehash($hash, $this->algorithm, $this->options); }
}
