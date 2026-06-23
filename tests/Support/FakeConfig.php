<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Contracts\ConfigInterface;

/**
 * Minimal ConfigInterface stub for service provider tests.
 */
final class FakeConfig implements ConfigInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
