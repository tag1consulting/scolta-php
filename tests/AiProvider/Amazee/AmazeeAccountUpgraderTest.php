<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

class AmazeeAccountUpgraderTest extends TestCase
{
    private function makeUpgrader(array $responses, ConfigStorageInterface $storage): AmazeeAccountUpgrader
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AmazeeClient('https://api.amazee.ai', $httpClient);
        return new AmazeeAccountUpgrader($client, $storage);
    }

    public function testSignInReturnsSessionToken(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $upgrader = $this->makeUpgrader([
            new Response(200, [], json_encode(['token' => 'sess-abc'])),
        ], $storage);

        $token = $upgrader->signIn('user@example.com', '123456');
        $this->assertSame('sess-abc', $token);
    }

    public function testListRegionsForwardsResult(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $regions = [['id' => 'us-east', 'name' => 'US East', 'url' => 'https://us.amazee.ai']];
        $upgrader = $this->makeUpgrader([
            new Response(200, [], json_encode(['regions' => $regions])),
        ], $storage);

        $this->assertSame($regions, $upgrader->listRegions('sess-abc'));
    }

    public function testUpgradeStoresCredentials(): void
    {
        $stored = [];
        $storage = $this->createMock(ConfigStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->willReturnCallback(function (string $token, string $url, string $region) use (&$stored): void {
                $stored = compact('token', 'url', 'region');
            });

        $upgrader = $this->makeUpgrader([
            new Response(200, [], json_encode([
                'litellm_token' => 'paid-tok',
                'litellm_api_url' => 'https://paid.amazee.ai',
                'region' => 'eu-west-1',
            ])),
        ], $storage);

        $result = $upgrader->upgrade('sess-abc', 'eu-west-1');

        $this->assertTrue($result->success);
        $this->assertSame('paid-tok', $stored['token']);
        $this->assertSame('eu-west-1', $stored['region']);
    }

    public function testRequestVerificationCodeCallsApi(): void
    {
        $storage = $this->createMock(ConfigStorageInterface::class);
        $upgrader = $this->makeUpgrader([
            new Response(200, [], json_encode(['status' => 'sent'])),
        ], $storage);

        // Should not throw.
        $upgrader->requestVerificationCode('user@example.com');
        $this->assertTrue(true);
    }
}
