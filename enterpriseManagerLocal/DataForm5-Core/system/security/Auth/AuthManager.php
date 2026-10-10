<?php
declare(strict_types=1);
namespace DataForm5\Security\Auth;
use DataForm5\Security\Contracts\AuthenticatableInterface;
use DataForm5\Security\Contracts\SessionStoreInterface;
use DataForm5\Security\Contracts\UserProviderInterface;
final class AuthManager {
    private const KEY = '_auth_user_id';
    private ?AuthenticatableInterface $resolved = null;
    public function __construct(private readonly UserProviderInterface $users, private readonly SessionStoreInterface $session, private readonly PasswordHasher $hasher) {}
    public function attempt(string $identifier, string $password): bool { $user = $this->users->findByIdentifier($identifier); if (!$user || !$this->hasher->verify($password, $user->passwordHash())) return false; $this->login($user); return true; }
    public function login(AuthenticatableInterface $user): void { $this->session->regenerate(); $this->session->put(self::KEY, $user->authIdentifier()); $this->resolved = $user; }
    public function logout(): void { $this->resolved = null; $this->session->invalidate(); }
    public function user(): ?AuthenticatableInterface { if ($this->resolved) return $this->resolved; $id = $this->session->get(self::KEY); return $id === null ? null : $this->resolved = $this->users->findById($id); }
    public function check(): bool { return $this->user() !== null; }
    public function guest(): bool { return !$this->check(); }
}
