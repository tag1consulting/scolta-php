<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ApiKeySource;
use Tag1\Scolta\Config\ResolvedApiKey;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\SetupCheck;

/**
 * The canonical API-key precedence, and the surfaces that report it.
 *
 * The defect these cover: the effective-config path preferred an explicit key
 * while a separate source derivation preferred Amazee, so a site with a valid
 * SCOLTA_API_KEY reported "Connected to Amazee.ai" in success green while
 * sending every request with its own key.
 */
class ApiKeyResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta_key_matrix_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        @rmdir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Precedence
    // -------------------------------------------------------------------

    public function testEnvironmentBeatsSettings(): void
    {
        $resolved = ApiKeyResolver::resolve([
            'env' => 'sk-env',
            'settings' => 'sk-settings',
        ]);

        $this->assertSame('sk-env', $resolved->key);
        $this->assertSame(ApiKeySource::Env, $resolved->source);
    }

    public function testExplicitKeyBeatsStoredAmazeeCredentials(): void
    {
        $resolved = ApiKeyResolver::resolve(
            ['env' => 'sk-env'],
            new AmazeeCredentials('litellm-token', 'https://gateway.example'),
        );

        $this->assertSame('sk-env', $resolved->key);
        $this->assertSame(ApiKeySource::Env, $resolved->source);
        $this->assertFalse($resolved->isAmazee());
        $this->assertTrue($resolved->amazeeCredentialsStored);
        $this->assertTrue($resolved->amazeeOverridden());
    }

    public function testAmazeeWinsWhenNoExplicitKeyIsSet(): void
    {
        $resolved = ApiKeyResolver::resolve(
            ['env' => '', 'settings' => '   '],
            new AmazeeCredentials('litellm-token', 'https://gateway.example'),
        );

        $this->assertSame('litellm-token', $resolved->key);
        $this->assertSame(ApiKeySource::Amazee, $resolved->source);
        $this->assertSame('https://gateway.example', $resolved->baseUrl);
        $this->assertSame(ApiKeyResolver::AMAZEE_GATEWAY_PROVIDER, $resolved->provider);
        $this->assertFalse($resolved->amazeeOverridden());
    }

    /**
     * Amazee is one source, and no surface guesses how it was obtained.
     *
     * The regression: the source briefly split into `amazee:operator` and
     * `amazee:auto`, a trial-versus-licensed distinction nothing records.
     * AmazeeTrialProvisioner and AmazeeAccountUpgrader both persist the same
     * three fields through ConfigStorageInterface::store(), so each adapter
     * substituted a local fact instead and got stuck on one case — WordPress
     * on `amazee:auto`, which announced every operator-connected account as an
     * auto-provisioned free trial.
     */
    public function testAmazeeIsOneSourceAndClaimsNothingAboutItsOrigin(): void
    {
        $resolved = ApiKeyResolver::resolve([], new AmazeeCredentials('t'));

        $this->assertSame(ApiKeySource::Amazee, $resolved->source);
        $this->assertTrue($resolved->isAmazee());

        // Every Amazee description, in every state, is free of a provenance
        // claim: there is no stored fact that could support one.
        $states = [
            $resolved,
            ApiKeyResolver::resolve([], new AmazeeCredentials('t', '', modelResolved: false)),
        ];
        foreach ($states as $state) {
            $this->assertStringNotContainsString('free trial', $state->describe());
            $this->assertStringNotContainsString('trial', $state->describe());
            $this->assertStringNotContainsString('licensed', $state->describe());
        }
    }

    /**
     * Every Amazee source case rests on a fact the credential store records.
     *
     * The predecessor of this test asserted the opposite — that no case may
     * claim a provenance — because the store had nowhere to keep one, and each
     * adapter was substituting a local fact that merely correlated. That is
     * still the property being protected; what changed is that the store now
     * has somewhere to keep it, so the guard checks the support rather than
     * banning the claim.
     *
     * Asserted structurally: adding an Amazee case with no corresponding
     * {@see AmazeeConnectionSource} — a case nothing can ever record — fails
     * here rather than shipping as a confident guess.
     */
    public function testEveryAmazeeSourceCaseRestsOnARecordedFact(): void
    {
        $recordable = (new \ReflectionMethod(
            ProvenanceAwareConfigStorageInterface::class,
            'storeConnectionSource',
        ))->getParameters();
        $this->assertSame(
            'source',
            $recordable[0]->getName(),
            'The credential store must have somewhere to record how a connection was made, '
            . 'or no provenance-bearing ApiKeySource case is supportable.',
        );

        $supported = ['amazee'];
        foreach (AmazeeConnectionSource::cases() as $connection) {
            $supported[] = ApiKeySource::forAmazeeConnection($connection)->value;
        }

        foreach (ApiKeySource::cases() as $case) {
            if (!$case->isAmazee()) {
                continue;
            }
            $this->assertContains(
                $case->value,
                $supported,
                'An Amazee source case is claiming a provenance nothing can record.',
            );
        }
    }

    /**
     * Unrecorded provenance reports as Amazee, never as one of the two actions.
     *
     * Credentials stored before 1.2.0, and any store that does not implement
     * the provenance interface, have no recorded origin. Guessing one is the
     * whole defect.
     */
    public function testUnrecordedProvenanceClaimsNoOrigin(): void
    {
        $resolved = ApiKeyResolver::resolve([], new AmazeeCredentials('t'));

        $this->assertSame(ApiKeySource::Amazee, $resolved->source);
        $this->assertFalse($resolved->source->isAmazeeDemo());
        $this->assertStringNotContainsString('demo', $resolved->describe());
        $this->assertStringNotContainsString('account', $resolved->describe());
    }

    /**
     * A recorded connection source is reported, in both directions.
     */
    public function testRecordedProvenanceIsReported(): void
    {
        $demo = ApiKeyResolver::resolve(
            [],
            new AmazeeCredentials('t', connectionSource: AmazeeConnectionSource::Demo),
        );
        $this->assertSame(ApiKeySource::AmazeeDemo, $demo->source);
        $this->assertTrue($demo->source->isAmazee());
        $this->assertTrue($demo->source->isAmazeeDemo());
        $this->assertStringContainsString('free demo', $demo->describe());

        $account = ApiKeyResolver::resolve(
            [],
            new AmazeeCredentials('t', connectionSource: AmazeeConnectionSource::Account),
        );
        $this->assertSame(ApiKeySource::AmazeeAccount, $account->source);
        $this->assertTrue($account->source->isAmazee());
        $this->assertFalse($account->source->isAmazeeDemo());
        $this->assertStringContainsString('your account', $account->describe());
    }

    /**
     * No source case or label describes a connection as automatic.
     */
    public function testNoApiKeySourceLabelImpliesAutomaticProvisioning(): void
    {
        foreach (ApiKeySource::cases() as $case) {
            foreach (['auto', 'automatic', 'free trial'] as $banned) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $case->value);
                $this->assertStringNotContainsStringIgnoringCase($banned, $case->label());
            }
        }
    }

    public function testNoKeyAnywhereResolvesToNone(): void
    {
        $resolved = ApiKeyResolver::resolve(['env' => '', 'settings' => '']);

        $this->assertSame('', $resolved->key);
        $this->assertSame(ApiKeySource::None, $resolved->source);
        $this->assertFalse($resolved->isConfigured());
        $this->assertFalse($resolved->amazeeCredentialsStored);
    }

    public function testIneligibleAmazeeIsReportedStoredButNotUsed(): void
    {
        // Drupal's drupal_ai provider manages its own key: Amazee must not be
        // injected, but the stored credentials must not be hidden either.
        $resolved = ApiKeyResolver::resolve(
            [],
            new AmazeeCredentials('litellm-token'),
            'drupal_ai',
            amazeeEligible: false,
        );

        $this->assertSame('', $resolved->key);
        $this->assertSame(ApiKeySource::None, $resolved->source);
        $this->assertTrue($resolved->amazeeCredentialsStored);
        $this->assertStringContainsString('stored', $resolved->describe());
    }

    public function testHalfProvisionedAmazeeReportsSourceButWithholdsKey(): void
    {
        $resolved = ApiKeyResolver::resolve(
            [],
            new AmazeeCredentials('litellm-token', modelResolved: false),
        );

        $this->assertSame('', $resolved->key, 'A dated default model reaching the gateway is an HTTP 400, not a degrade');
        $this->assertSame(ApiKeySource::Amazee, $resolved->source);
        $this->assertTrue($resolved->awaitingAmazeeModelResolution);
        $this->assertSame('warning', $resolved->severity());
    }

    public function testExplicitProviderIsPreservedAndAmazeeForcesTheGateway(): void
    {
        $explicit = ApiKeyResolver::resolve(['env' => 'sk-env'], null, 'anthropic');
        $amazee = ApiKeyResolver::resolve([], new AmazeeCredentials('t'), 'anthropic');

        $this->assertSame('anthropic', $explicit->provider);
        $this->assertSame('openai', $amazee->provider);
    }

    // -------------------------------------------------------------------
    // Severity: an overridden credential is never success green
    // -------------------------------------------------------------------

    public function testOverriddenAmazeeIsNotRenderedAsSuccess(): void
    {
        $resolved = ApiKeyResolver::resolve(['env' => 'sk-env'], new AmazeeCredentials('t'));

        $this->assertSame('warning', $resolved->severity());
        $this->assertStringContainsString(
            'Amazee.ai credentials stored but overridden by',
            $resolved->describe(),
        );
    }

    public function testCleanExplicitKeyIsSuccess(): void
    {
        $this->assertSame(
            'ok',
            ApiKeyResolver::resolve(['env' => 'sk-env'], configuredProvider: 'anthropic')->severity(),
        );
    }

    /**
     * A key without a selected provider is not a working configuration.
     *
     * Scolta ships no default provider, so an environment variable set before
     * anybody chose one leaves AI off. Reporting that in success green would
     * reinstate by the back door the assumption that an unselected provider
     * means Anthropic.
     */
    public function testExplicitKeyWithNoProviderSelectedIsNotSuccess(): void
    {
        $resolved = ApiKeyResolver::resolve(['env' => 'sk-env']);

        $this->assertSame('', $resolved->provider);
        $this->assertFalse($resolved->providerSelected());
        $this->assertFalse($resolved->aiEnabled());
        $this->assertSame('warning', $resolved->severity());
        $this->assertStringContainsString('No AI provider is selected', $resolved->describe());
    }

    /**
     * The resolver never substitutes a provider for the empty value.
     */
    public function testResolverNeverDefaultsToAnthropic(): void
    {
        foreach ([ApiKeyResolver::resolve([]), ApiKeyResolver::resolve(['env' => 'sk-env'])] as $resolved) {
            $this->assertSame('', $resolved->provider);
            $this->assertStringNotContainsString('anthropic', $resolved->describe());
        }
    }

    public function testUnconfiguredIsWarning(): void
    {
        $this->assertSame('warning', ApiKeyResolver::resolve([])->severity());
    }

    // -------------------------------------------------------------------
    // The four-by-two matrix: every source, with and without stored Amazee
    // credentials, asserted on the resolver, the health payload and the CLI
    // setup check together.
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, string>, 1: bool, 2: string, 3: bool, 4: bool}>
     */
    public static function matrixProvider(): array
    {
        // [explicit keys, amazee stored, expected source, expected configured,
        //  expected overridden]
        return [
            'env, no amazee' => [['env' => 'sk-env'], false, 'env', true, false],
            'env, amazee stored' => [['env' => 'sk-env'], true, 'env', true, true],
            'settings, no amazee' => [['env' => '', 'settings' => 'sk-set'], false, 'settings', true, false],
            'settings, amazee stored' => [['env' => '', 'settings' => 'sk-set'], true, 'settings', true, true],
            'no candidates, no amazee' => [[], false, 'none', false, false],
            'no candidates, amazee stored' => [[], true, 'amazee', true, false],
            'empty candidates, no amazee' => [['env' => '', 'settings' => ''], false, 'none', false, false],
            'empty candidates, amazee stored' => [['env' => '', 'settings' => ''], true, 'amazee', true, false],
        ];
    }

    /**
     * @param array<string, string> $explicit
     * @dataProvider matrixProvider
     */
    public function testMatrixAgreesAcrossEverySurface(
        array $explicit,
        bool $amazeeStored,
        string $expectedSource,
        bool $expectedConfigured,
        bool $expectedOverridden,
    ): void {
        $resolved = ApiKeyResolver::resolve(
            $explicit,
            $amazeeStored ? new AmazeeCredentials('litellm-token', 'https://gateway.example') : null,
        );

        // 1. The resolver.
        $this->assertSame($expectedSource, $resolved->source->value);
        $this->assertSame($expectedConfigured, $resolved->isConfigured() || $resolved->isAmazee());
        $this->assertSame($expectedOverridden, $resolved->amazeeOverridden());

        // 2. The health payload.
        $health = (new HealthChecker(
            config: $this->configFor($resolved),
            indexOutputDir: $this->tempDir,
            pagefindBinaryPath: null,
            projectDir: null,
            cache: null,
            resolvedKey: $resolved,
        ))->check();

        $this->assertSame($expectedSource, $health['ai_key_source'], 'health disagrees about the source');
        $this->assertSame($expectedConfigured, $health['ai_configured'], 'health disagrees about configuration');
        $this->assertSame($expectedOverridden, $health['ai_amazee_overridden'], 'health hides the override');

        // 3. The CLI setup check.
        $setup = SetupCheck::run(
            configuredBinaryPath: null,
            projectDir: null,
            aiApiKey: null,
            browserWasmDir: null,
            resolvedKey: $resolved,
        );
        $keyRow = $this->rowNamed($setup, 'AI API key');

        $this->assertSame($resolved->describe(), $keyRow['message'], 'the CLI words it differently');
        $this->assertSame(
            $resolved->severity() === 'ok' ? 'pass' : 'warn',
            $keyRow['status'],
            'the CLI disagrees about severity',
        );

        // The three surfaces above all derive from $resolved, which is the
        // point: there is no second derivation left to disagree with.
        if ($expectedOverridden) {
            $this->assertStringContainsString('overridden', $keyRow['message']);
            $this->assertSame('warn', $keyRow['status']);
        }
    }

    private function configFor(ResolvedApiKey $resolved): ScoltaConfig
    {
        $config = new ScoltaConfig();
        $config->aiApiKey = $resolved->key;
        $config->aiProvider = $resolved->provider;
        $config->aiBaseUrl = $resolved->baseUrl;

        return $config;
    }

    /**
     * @param array<array{name: string, status: string, message: string, category: string}> $rows
     * @return array{name: string, status: string, message: string, category: string}
     */
    private function rowNamed(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("No setup-check row named {$name}");
    }
}
