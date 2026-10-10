<?php
declare(strict_types=1);
namespace DataForm5\Security\Auth;
use DataForm5\Security\Contracts\AuthenticatableInterface;
final readonly class User implements AuthenticatableInterface {
    /** @param list<string> $roles @param list<string> $permissions */
    public function __construct(private string|int $id, public string $identifier, private string $hash, private array $roles = [], private array $permissions = []) {}
    public function authIdentifier(): string|int { return $this->id; }
    public function passwordHash(): string { return $this->hash; }
    public function roles(): array { return $this->roles; }
    public function permissions(): array { return $this->permissions; }
}
