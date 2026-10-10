<?php
declare(strict_types=1);
namespace DataForm5\Security\Authorization;
use Closure;
use DataForm5\Security\Auth\AuthManager;
use DataForm5\Security\Contracts\AuthenticatableInterface;
final class Gate {
    /** @var array<string, Closure(AuthenticatableInterface,mixed...):bool> */ private array $abilities = [];
    public function __construct(private readonly AuthManager $auth) {}
    public function define(string $ability, Closure $callback): void { $this->abilities[$ability] = $callback; }
    public function allows(string $ability, mixed ...$arguments): bool { $user = $this->auth->user(); if (!$user) return false; if (in_array($ability, $user->permissions(), true)) return true; return isset($this->abilities[$ability]) && (bool)($this->abilities[$ability])($user, ...$arguments); }
    public function hasRole(string $role): bool { $user = $this->auth->user(); return $user !== null && in_array($role, $user->roles(), true); }
}
