<?php
declare(strict_types=1);

namespace DataForm5\Core\Container;

use Closure;
use DataForm5\Core\Contracts\ContainerInterface;
use DataForm5\Core\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * Enterprise dependency-injection container.
 *
 * Phase B keeps backward compatibility with the original container API while
 * adding aliases, tags, parameter overrides, callable injection and circular
 * dependency diagnostics.
 */
final class ServiceContainer implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $bindings = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, bool> */
    private array $shared = [];
    /** @var array<string, string> */
    private array $aliases = [];
    /** @var array<string, list<string>> */
    private array $tags = [];
    /** @var list<string> */
    private array $resolutionStack = [];

    public function bind(string $id, callable|string|null $concrete = null, bool $shared = false): void
    {
        $id = $this->normalizeId($id);
        $concrete ??= $id;
        $this->bindings[$id] = is_string($concrete)
            ? static fn (self $container): object => $container->build($concrete)
            : Closure::fromCallable($concrete);
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    public function singleton(string $id, callable|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, mixed $instance): void
    {
        $id = $this->normalizeId($id);
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    public function alias(string $alias, string $id): void
    {
        if ($alias === '' || $id === '') {
            throw new ContainerException('Service-Alias und Ziel dürfen nicht leer sein.');
        }
        if ($alias === $id) {
            throw new ContainerException("Service '{$id}' kann nicht Alias auf sich selbst sein.");
        }
        $this->aliases[$alias] = $id;
        // Resolve once to catch loops immediately.
        $this->normalizeId($alias);
    }

    /** @param string|list<string> $ids */
    public function tag(string|array $ids, string $tag): void
    {
        if ($tag === '') {
            throw new ContainerException('Service-Tag darf nicht leer sein.');
        }
        foreach ((array)$ids as $id) {
            $id = $this->normalizeId((string)$id);
            if (!in_array($id, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /** @return list<mixed> */
    public function tagged(string $tag): array
    {
        $services = [];
        foreach ($this->tags[$tag] ?? [] as $id) {
            $services[] = $this->get($id);
        }
        return $services;
    }

    public function has(string $id): bool
    {
        try {
            $id = $this->normalizeId($id);
        } catch (Throwable) {
            return false;
        }
        return array_key_exists($id, $this->instances)
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        $id = $this->normalizeId($id);

        if ($parameters === [] && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolutionStack, true)) {
            $chain = implode(' -> ', [...$this->resolutionStack, $id]);
            throw new ContainerException("Zirkuläre Service-Abhängigkeit erkannt: {$chain}");
        }

        $this->resolutionStack[] = $id;
        try {
            if (isset($this->bindings[$id])) {
                if ($parameters !== []) {
                    // Explicit parameters are only meaningful for class autowiring.
                    $value = $this->build($id, $parameters);
                } else {
                    $value = ($this->bindings[$id])($this);
                }
            } else {
                if (!class_exists($id)) {
                    throw new ContainerException("Service '{$id}' ist nicht registriert.");
                }
                $value = $this->build($id, $parameters);
            }

            if (($this->shared[$id] ?? false) && $parameters === []) {
                $this->instances[$id] = $value;
            }
            return $value;
        } finally {
            array_pop($this->resolutionStack);
        }
    }

    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new ContainerException("Klasse '{$class}' wurde nicht gefunden.");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Klasse '{$class}' ist nicht instanziierbar. Für Interfaces ist ein Binding erforderlich.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = $this->resolveParameters($constructor, $parameters, $class . '::__construct');
        return $reflection->newInstanceArgs($arguments);
    }

    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        [$reflection, $target] = $this->reflectCallable($callable);
        $label = $reflection instanceof ReflectionMethod
            ? $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName()
            : $reflection->getName();
        $arguments = $this->resolveParameters($reflection, $parameters, $label);
        return $reflection instanceof ReflectionMethod
            ? $reflection->invokeArgs($target, $arguments)
            : $reflection->invokeArgs($arguments);
    }

    /** @return array<string, array{shared:bool,resolved:bool,tags:list<string>}> */
    public function describe(): array
    {
        $ids = array_unique(array_merge(array_keys($this->bindings), array_keys($this->instances)));
        sort($ids);
        $result = [];
        foreach ($ids as $id) {
            $serviceTags = [];
            foreach ($this->tags as $tag => $taggedIds) {
                if (in_array($id, $taggedIds, true)) $serviceTags[] = $tag;
            }
            $result[$id] = [
                'shared' => (bool)($this->shared[$id] ?? false),
                'resolved' => array_key_exists($id, $this->instances),
                'tags' => $serviceTags,
            ];
        }
        return $result;
    }

    /** @return array<string,string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** @return list<mixed> */
    private function resolveParameters(ReflectionFunctionAbstract $reflection, array $parameters, string $context): array
    {
        $arguments = [];
        foreach ($reflection->getParameters() as $position => $parameter) {
            if (array_key_exists($parameter->getName(), $parameters)) {
                $arguments[] = $parameters[$parameter->getName()];
                continue;
            }
            if (array_key_exists($position, $parameters)) {
                $arguments[] = $parameters[$position];
                continue;
            }
            $arguments[] = $this->resolveParameter($parameter, $context);
        }
        return $arguments;
    }

    private function resolveParameter(ReflectionParameter $parameter, string $context): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin() && $this->has($unionType->getName())) {
                    return $this->get($unionType->getName());
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerException("Parameter '{$parameter->getName()}' von '{$context}' kann nicht automatisch aufgelöst werden. Übergabe als benannter Parameter erforderlich.");
    }

    /** @return array{0:ReflectionFunctionAbstract,1:?object} */
    private function reflectCallable(callable|array|string $callable): array
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            if (is_string($target)) $target = $this->get($target);
            if (!is_object($target)) throw new ContainerException('Ungültiges Callable-Ziel.');
            return [new ReflectionMethod($target, (string)$method), $target];
        }
        if (is_string($callable) && str_contains($callable, '::')) {
            [$class, $method] = explode('::', $callable, 2);
            $reflection = new ReflectionMethod($class, $method);
            $target = $reflection->isStatic() ? null : $this->get($class);
            return [$reflection, $target];
        }
        if ($callable instanceof Closure || is_string($callable)) {
            return [new ReflectionFunction($callable), null];
        }
        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return [new ReflectionMethod($callable, '__invoke'), $callable];
        }
        throw new ContainerException('Callable konnte nicht reflektiert werden.');
    }

    private function normalizeId(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                $chain = implode(' -> ', [...array_keys($seen), $id]);
                throw new ContainerException("Zirkulärer Service-Alias erkannt: {$chain}");
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }
}
