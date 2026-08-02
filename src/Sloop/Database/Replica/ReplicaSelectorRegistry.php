<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

use Sloop\Database\Exception\InvalidConfigException;

/**
 * Maps `replica_selector` config identifiers to selector implementations.
 *
 * Pool configs name their strategy by string (e.g. `'random'`). This registry
 * is what turns that string into an instance, so the config value actually
 * determines the behavior instead of being validated and then ignored.
 *
 * To add a strategy, implement ReplicaSelector and bind a registry containing
 * it in the container:
 *
 * ```php
 * $container->singleton(
 *     ReplicaSelectorRegistry::class,
 *     fn (): ReplicaSelectorRegistry => new ReplicaSelectorRegistry([
 *         'random'      => new RandomReplicaSelector(),
 *         'round_robin' => new RoundRobinReplicaSelector(),
 *     ]),
 * );
 * ```
 *
 * The identifier must also be accepted by ConnectionConfigResolver, which
 * rejects unknown values before they reach this registry.
 *
 * @api
 */
final readonly class ReplicaSelectorRegistry
{
    /**
     * Construct a registry from identifier to selector.
     *
     * @param array<string, ReplicaSelector> $selectors Selectors indexed by their `replica_selector` identifier
     */
    public function __construct(
        private array $selectors,
    ) {
    }

    /**
     * Get the selector registered under the given identifier.
     *
     * @param  string                  $identifier Value of the pool's `replica_selector` config key
     * @return ReplicaSelector
     * @throws InvalidConfigException  When no selector is registered under the identifier
     */
    public function get(string $identifier): ReplicaSelector
    {
        return $this->selectors[$identifier]
            ?? throw new InvalidConfigException(
                'No replica selector is registered for "' . $identifier . '".'
                . ' Registered: ' . (
                    $this->selectors === [] ? '(none)' : implode(', ', array_keys($this->selectors))
                ) . '.'
            );
    }
}
