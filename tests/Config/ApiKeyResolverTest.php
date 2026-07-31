<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;
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
        $this->assertSame(ApiKeySource::AmazeeAuto, $resolved->source);
        $this->assertSame('https://gateway.example', $resolved->baseUrl);
        $this->assertSame(ApiKeyResolver::AMAZEE_GATEWAY_PROVIDER, $resolved->provider);
        $this->assertFalse($resolved->amazeeOverridden());
    }

    public function testOperatorChosenAmazeeIsDistinguishedFromAutoProvisioned(): void
    {
        $operator = ApiKeyResolver::resolve([], new AmazeeCredentials('t', '', operatorChosen: true));
        $auto = ApiKeyResolver::resolve([], new AmazeeCredentials('t', '', operatorChosen: false));

        $this->assertSame(ApiKeySource::AmazeeOperator, $operator->source);
        $this->assertSame(ApiKeySource::AmazeeAuto, $auto->source);
        $this->assertTrue($operator->isAmazee());
        $this->assertTrue($auto->isAmazee());
        $this->assertStringNotContainsString('free trial', $operator->describe());
        $this->assertStringContainsString('free trial', $auto->describe());
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
        $this->assertSame(ApiKeySource::AmazeeAuto, $resolved->source);
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
        $this->assertSame('ok', ApiKeyResolver::resolve(['env' => 'sk-env'])->severity());
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
            'amazee, no amazee' => [[], false, 'none', false, false],
            'amazee, amazee stored' => [[], true, 'amazee:auto', true, false],
            'none, no amazee' => [['env' => '', 'settings' => ''], false, 'none', false, false],
            'none, amazee stored' => [['env' => '', 'settings' => ''], true, 'amazee:auto', true, false],
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
