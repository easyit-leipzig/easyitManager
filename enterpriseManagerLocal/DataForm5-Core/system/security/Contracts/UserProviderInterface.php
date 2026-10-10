<?php
declare(strict_types=1);
namespace DataForm5\Security\Contracts;
interface UserProviderInterface {
    public function findByIdentifier(string $identifier): ?AuthenticatableInterface;
    public function findById(string|int $id): ?AuthenticatableInterface;
}
