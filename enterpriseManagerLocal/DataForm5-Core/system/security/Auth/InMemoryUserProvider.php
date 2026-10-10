<?php
declare(strict_types=1);
namespace DataForm5\Security\Auth;
use DataForm5\Security\Contracts\AuthenticatableInterface;
use DataForm5\Security\Contracts\UserProviderInterface;
final class InMemoryUserProvider implements UserProviderInterface {
    /** @var list<AuthenticatableInterface> */ private array $users;
    /** @param list<AuthenticatableInterface> $users */ public function __construct(array $users = []) { $this->users = $users; }
    public function add(AuthenticatableInterface $user): void { $this->users[] = $user; }
    public function findByIdentifier(string $identifier): ?AuthenticatableInterface { foreach ($this->users as $u) { if ($u instanceof User && hash_equals($u->identifier, $identifier)) return $u; } return null; }
    public function findById(string|int $id): ?AuthenticatableInterface { foreach ($this->users as $u) { if ((string)$u->authIdentifier() === (string)$id) return $u; } return null; }
}
