<?php

namespace App\Gateways;

use App\Contracts\GatewayAdapter;
use Illuminate\Contracts\Container\Container;

final class GatewayRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<GatewayAdapter>
     */
    public function enabledByPriority(): array
    {
        $gateways = collect(config('checkouthub.gateways', []))
            ->filter(fn (array $config) => ($config['enabled'] ?? true) === true)
            ->sortBy(fn (array $config) => (int) ($config['priority'] ?? 100))
            ->map(fn (array $config, string $name) => $this->make($name, $config))
            ->values()
            ->all();

        return $gateways;
    }

    public function get(string $name): GatewayAdapter
    {
        $config = config("checkouthub.gateways.{$name}");

        if (! is_array($config)) {
            throw new \InvalidArgumentException("Unknown gateway [{$name}].");
        }

        return $this->make($name, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function make(string $name, array $config): GatewayAdapter
    {
        $class = $config['adapter'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, GatewayAdapter::class)) {
            throw new \InvalidArgumentException("Gateway [{$name}] is missing a valid adapter.");
        }

        return $this->container->make($class, ['config' => $config]);
    }
}
