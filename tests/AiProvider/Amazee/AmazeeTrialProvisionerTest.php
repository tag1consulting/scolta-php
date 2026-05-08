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
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

class AmazeeTrialProvisionerTest extends TestCase
{
    private function makeProvisioner(array $responses, ConfigStorageInterface $storage): AmazeeTrialProvisioner
    {
        $mock = new MockHandler($responses);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new AmazeeClient('https://api.amazee.ai', $httpClient);
        return new AmazeeTrialProvisioner($client, $storage);
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
}
