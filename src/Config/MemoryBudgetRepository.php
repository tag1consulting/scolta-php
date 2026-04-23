<?php

declare(strict_types=1);

namespace Tag1\Scolta\Config;

use Tag1\Scolta\Index\MemoryBudget;

/**
 * Platform-agnostic contract for storing and loading the memory-budget config.
 *
 * Each framework adapter implements this to bridge its own config storage
 * (WP options, Drupal CMI, Laravel config) to the shared MemoryBudgetConfig.
 */
interface MemoryBudgetRepository
{
    /**
     * Load the persisted config, returning defaults if nothing is stored.
     */
    public function load(): MemoryBudgetConfig;

    /**
     * Persist a new config value.
     */
    public function save(MemoryBudgetConfig $config): void;

    /**
     * Return the runtime MemoryBudget for the current persisted config.
     *
     * Convenience wrapper around load()->toMemoryBudget().
     */
    public function resolve(): MemoryBudget;
}
