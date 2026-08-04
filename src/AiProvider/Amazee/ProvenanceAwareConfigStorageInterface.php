<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * A credential store that can also record how the connection was established.
 *
 * Kept separate from {@see ConfigStorageInterface} rather than folded into it:
 * `store()` is `@stability stable`, and adding a parameter to an interface
 * method — even an optional one — is a fatal incompatibility for every
 * implementation that does not declare it. Adapters adopt provenance by
 * implementing this sub-interface when they have somewhere to put it.
 *
 * {@see AmazeeTrialProvisioner} and {@see AmazeeAccountUpgrader} record the
 * connection source through this interface when the store they were given
 * implements it, and skip the record when it does not. A store that does not
 * implement it keeps working exactly as before and reports no provenance,
 * which is honest: nothing then knows how the credentials were obtained.
 *
 * Implementations MUST drop the recorded source in `clear()`, so disconnecting
 * does not leave a stale provenance to be paired with the next connection.
 *
 * @since 1.2.0
 * @stability experimental
 */
interface ProvenanceAwareConfigStorageInterface extends ConfigStorageInterface
{
    /**
     * Record which operator action produced the credentials just stored.
     *
     * Called immediately after {@see ConfigStorageInterface::store()} by the
     * class that established the connection.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function storeConnectionSource(AmazeeConnectionSource $source): void;

    /**
     * The recorded connection source, or NULL when none was recorded.
     *
     * NULL is the correct answer for credentials stored before provenance was
     * recorded, and for a store that has been cleared. Callers must report it
     * as "not recorded" and must not substitute a guess.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function loadConnectionSource(): ?AmazeeConnectionSource;
}
