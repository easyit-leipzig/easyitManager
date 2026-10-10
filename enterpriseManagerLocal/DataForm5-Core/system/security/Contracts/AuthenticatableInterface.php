<?php
declare(strict_types=1);
namespace DataForm5\Security\Contracts;
interface AuthenticatableInterface {
    public function authIdentifier(): string|int;
    public function passwordHash(): string;
    /** @return list<string> */ public function roles(): array;
    /** @return list<string> */ public function permissions(): array;
}
