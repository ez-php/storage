<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use RuntimeException;

/**
 * Minimal ContainerInterface stub for service provider tests.
 *
 * Automatically resolves ConfigInterface from the injected FakeConfig.
 * Other instances can be seeded via instance(); bindings are stored and
 * executed on make().
 */
final class FakeContainer implements ContainerInterface
{
    /** @var array<string, callable(ContainerInterface): object> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(ConfigInterface $config)
    {
        $this->instances[ConfigInterface::class] = $config;
    }

    public function bind(string $abstract, string|callable|null $factory = null): static
    {
        if (is_callable($factory)) {
            $this->bindings[$abstract] = $factory;
        }

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            $instance = $this->instances[$abstract];
            assert($instance instanceof $abstract);

            return $instance;
        }

        if (isset($this->bindings[$abstract])) {
            $result = ($this->bindings[$abstract])($this);
            assert($result instanceof $abstract);

            return $result;
        }

        throw new RuntimeException("No binding for {$abstract}");
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function wasBound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }
}
