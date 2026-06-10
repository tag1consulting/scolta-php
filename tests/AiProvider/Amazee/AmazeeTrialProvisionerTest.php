<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\ProvisioningResult;

class AmazeeTrialProvisionerTest extends TestCase
{
    private function makeProvisioner(
        array $responses,
        ConfigStorageInterface $storage,
        ?callable $hasExistingProvider = null,
        ?AmazeeModelResolver $modelResolver = null,
    ): AmazeeTrialProvisioner {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AmazeeClient('https://api.amazee.ai', $httpClient);
        return new AmazeeTrialProvisioner($client, $storage, $hasExistingProvider, $modelResolver);
    }

    private function makeModelResolver(array $responses): AmazeeModelResolver
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AmazeeClient('https://api.amazee.ai', $httpClient);
        return new AmazeeModelResolver($client);
    }

    public function testProvisionStoresCredentials(): void
    {
        $stored = [];
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->willReturnCallback(function (string $token, string $url, string $region) use (&$stored): void {
                $stored = compact('token', 'url', 'region');
            });

        $provisioner = $this->makeProvisioner([
            new Response(200, [], json_encode([
                'litellm_token' => 'tok-trial',
                'litellm_api_url' => 'https://trial.amazee.ai',
                'region' => 'us-east',
            ])),
            new Response(200, [], json_encode(['user' => 'ok'])),
        ], $storage);

        $result = $provisioner->provision('trial@example.com');

        $this->assertTrue($result->success);
        $this->assertSame('tok-trial', $stored['token']);
        $this->assertSame('https://trial.amazee.ai', $stored['url']);
        $this->assertSame('us-east', $stored['region']);
    }

    public function testProvisionDoesNotStoreOnApiError(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->never())->method('store');

        $provisioner = $this->makeProvisioner([
            new Response(422, [], json_encode(['detail' => 'Duplicate email.'])),
        ], $storage);

        $this->expectException(AmazeeApiException::class);
        $provisioner->provision('trial@example.com');
    }

    public function testProvisionSkipsWhenExistingProviderConfigured(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->never())->method('store');

        $provisioner = $this->makeProvisioner(
            [],
            $storage,
            fn() => true,
        );

        $result = $provisioner->provision('trial@example.com');

        $this->assertSame(ProvisioningResult::STATUS_SKIPPED_EXISTING_PROVIDER, $result->status);
        $this->assertTrue($result->success);
        $this->assertSame('', $result->litellmToken);
    }

    public function testProvisionProceedsWhenNoExistingProvider(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())->method('store');

        $provisioner = $this->makeProvisioner(
            [
                new Response(200, [], json_encode([
                    'litellm_token' => 'tok-xyz',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'eu-west',
                ])),
                new Response(200, [], json_encode(['user' => 'ok'])),
            ],
            $storage,
            fn() => false,
        );

        $result = $provisioner->provision('trial@example.com');

        $this->assertSame(ProvisioningResult::STATUS_PROVISIONED, $result->status);
        $this->assertTrue($result->success);
    }

    public function testProvisionProceedsWhenNoProviderCheckCallable(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())->method('store');

        $provisioner = $this->makeProvisioner(
            [
                new Response(200, [], json_encode([
                    'litellm_token' => 'tok-abc',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'us-east',
                ])),
                new Response(200, [], json_encode(['user' => 'ok'])),
            ],
            $storage,
            null,
        );

        $result = $provisioner->provision('trial@example.com');

        $this->assertSame(ProvisioningResult::STATUS_PROVISIONED, $result->status);
        $this->assertTrue($result->success);
    }

    public function testProvisionResolvesModelsWhenResolverProvided(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())->method('store');

        $modelResolver = $this->makeModelResolver([
            new Response(200, [], json_encode([
                'data' => [
                    ['model_name' => 'claude-sonnet-4-6'],
                    ['model_name' => 'claude-haiku-4-5'],
                ],
            ])),
        ]);

        $provisioner = $this->makeProvisioner(
            [
                new Response(200, [], json_encode([
                    'litellm_token' => 'tok-abc',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'us-east',
                ])),
                new Response(200, [], json_encode(['user' => 'ok'])),
            ],
            $storage,
            null,
            $modelResolver,
        );

        $result = $provisioner->provision('trial@example.com');

        $this->assertSame(ProvisioningResult::STATUS_PROVISIONED, $result->status);
        $this->assertSame('claude-sonnet-4-6', $result->aiModel);
        $this->assertSame('claude-haiku-4-5', $result->aiExpansionModel);
    }

    public function testProvisionNullModelsWhenNoResolver(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())->method('store');

        $provisioner = $this->makeProvisioner(
            [
                new Response(200, [], json_encode([
                    'litellm_token' => 'tok-abc',
                    'litellm_api_url' => 'https://trial.amazee.ai',
                    'region' => 'us-east',
                ])),
                new Response(200, [], json_encode(['user' => 'ok'])),
            ],
            $storage,
        );

        $result = $provisioner->provision('trial@example.com');

        $this->assertNull($result->aiModel);
        $this->assertNull($result->aiExpansionModel);
    }
}
