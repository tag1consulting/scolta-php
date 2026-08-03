<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Which operator action produced the stored Amazee.ai credentials.
 *
 * This is recorded at the moment a connection is established, not derived
 * afterwards. The distinction was previously guessed from whatever local fact
 * an adapter had to hand, which is why 1.1.1 removed it outright
 * ([#273](https://github.com/tag1consulting/scolta-php/pull/273)): both
 * {@see AmazeeTrialProvisioner::provision()} and
 * {@see AmazeeAccountUpgrader::upgrade()} persist the same three fields
 * through {@see ConfigStorageInterface::store()}, so nothing in the credential
 * store could tell them apart. Recording the fact at its source is what makes
 * the distinction reportable again.
 *
 * Neither case implies anything automatic. Both are reached only by an
 * explicit operator action in an admin UI, or by a developer who set
 * `ai_provider` to `amazee` in code and then ran the provisioning path.
 *
 * Storage backends opt in by implementing
 * {@see ProvenanceAwareConfigStorageInterface}. A store that does not — and
 * every credential persisted before this release — reports no connection
 * source at all, which surfaces as the origin-free Amazee case of
 * {@see \Tag1\Scolta\Config\ApiKeySource} rather than as a guess. Mapping a
 * connection source onto that enum is the resolver's job, not this file's.
 *
 * @since 1.2.0
 * @stability experimental
 */
enum AmazeeConnectionSource: string
{
    /**
     * The operator started the free demo, which needs no email and no account.
     *
     * One-time per site: the credit it ships with is not renewed, and the
     * demo is not re-mintable once spent. When it runs out the operator
     * continues by signing in to an amazee.ai account ({@see self::Account}).
     */
    case Demo = 'demo';

    /**
     * The operator signed in to an amazee.ai account with their email address.
     *
     * The email → verification code → region flow
     * ({@see AmazeeAccountUpgrader}) creates or attaches the account and
     * returns its credentials, which are then persisted. This is the same flow
     * whether the account is new or already existed, matching amazee.ai's own
     * `ai_provider_amazeeio` module.
     */
    case Account = 'account';

    /**
     * A short operator-facing name for this connection, in English.
     *
     * Adapters with translated surfaces should switch on the enum instead of
     * translating this; CLI surfaces can print it directly.
     *
     * @since 1.2.0
     * @stability experimental
     */
    public function label(): string
    {
        return match ($this) {
            self::Demo => 'Amazee.ai demo',
            self::Account => 'Amazee.ai account',
        };
    }
}
