<?php
declare(strict_types=1);

namespace DataForm5\Core;

use DataForm5\Core\Configuration\Environment;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Contracts\ContainerInterface;
use DataForm5\Core\Support\Path;

final class Kernel
{
    private bool $booted = false;
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly string $basePath,
        private readonly ServiceContainer $container = new ServiceContainer()
    ) {
        $path = new Path($basePath);
        $environment = (new Environment($basePath))->load('.env');
        $cacheFile = $path->storage('framework/cache/config.php');
        $useCache = filter_var(getenv('CONFIG_CACHE') ?: '0', FILTER_VALIDATE_BOOL);
        $config = $useCache && is_file($cacheFile)
            ? Config::fromCache($cacheFile)
            : Config::loadDirectory($path->config());

        $this->container->instance(self::class, $this);
        $this->container->instance(ServiceContainer::class, $this->container);
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Path::class, $path);
        $this->container->instance(Environment::class, $environment);
        $this->container->instance(Config::class, $config);

        $timezone = (string)$config->get('app.timezone', 'UTC');
        date_default_timezone_set($timezone);
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $provider->register($this->container);
        $this->providers[] = $provider;
        return $this;
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }
        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }
        $this->booted = true;
        return $this;
    }

    public function container(): ServiceContainer { return $this->container; }
    public function basePath(): string { return $this->basePath; }
    public function isBooted(): bool { return $this->booted; }
}
