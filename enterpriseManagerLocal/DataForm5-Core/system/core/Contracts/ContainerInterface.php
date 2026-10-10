<?php
declare(strict_types=1);

namespace DataForm5\Core\Contracts;

/**
 * Stable service-container contract for easyIT/DataForm modules.
 *
 * External modules should depend on this interface instead of the concrete
 * ServiceContainer wherever possible.
 */
interface ContainerInterface
{
    public function bind(string $id, callable|string|null $concrete = null, bool $shared = false): void;
    public function singleton(string $id, callable|string|null $concrete = null): void;
    public function instance(string $id, mixed $instance): void;
    public function alias(string $alias, string $id): void;
    public function has(string $id): bool;
    public function get(string $id): mixed;
    public function make(string $id, array $parameters = []): mixed;
    public function call(callable|array|string $callable, array $parameters = []): mixed;
    public function tagged(string $tag): array;
}
