<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;

class AmazeeClientTest extends TestCase
{
    private function makeClient(array $responses, array &$history = []): AmazeeClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        if (!empty($history) || func_num_args() >= 2) {
            $handlerStack->push(Middleware::history($history));
        }
        $httpClient = new Client(['handler' => $handlerStack]);
        return new AmazeeClient('https://api.amazee.ai', $httpClient);
    }

    // --- provisionTrial ---

    public function testProvisionTrialSuccess(): void
    {
        $client = $this->makeClient([
            // POST /auth/generate-trial-access — nested key format (current API).
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'tok-abc123',
                    'litellm_api_url' => 'https://llm.amazee.ai',
                    'region' => 'us-east',
                ],
            ])),
        ]);

        $result = $client->provisionTrial('test@example.com');

        $this->assertTrue($result->success);
        $this->assertSame('tok-abc123', $result->litellmToken);
        $this->assertSame('https://llm.amazee.ai', $result->litellmApiUrl);
        $this->assertSame('us-east', $result->region);
        $this->assertNull($result->error);
    }

    public function testProvisionTrialSuccessLegacyFlatFormat(): void
    {
        $client = $this->makeClient([
            // Flat top-level format (legacy API, kept for backwards compatibility).
            new Response(200, [], json_encode([
                'litellm_token' => 'tok-legacy',
                'litellm_api_url' => 'https://llm.amazee.ai',
                'region' => 'eu-west',
            ])),
        ]);

        $result = $client->provisionTrial();

        $this->assertTrue($result->success);
        $this->assertSame('tok-legacy', $result->litellmToken);
        $this->assertSame('eu-west', $result->region);
    }

    public function testProvisionTrialThrowsOnMissingToken(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['region' => 'us-east'])),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('litellm_token');
        $client->provisionTrial('test@example.com');
    }

    public function testProvisionTrialThrowsOnApiError(): void
    {
        $client = $this->makeClient([
            new Response(422, [], json_encode(['detail' => 'Email already registered.'])),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('Email already registered.');
        $client->provisionTrial('test@example.com');
    }

    public function testProvisionTrialThrowsOnInvalidJson(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'not-json'),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('malformed JSON');
        $client->provisionTrial('test@example.com');
    }

    public function testProvisionTrialSendsRefererHeader(): void
    {
        $history = [];
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'key' => [
                    'litellm_token' => 'tok-ref',
                    'litellm_api_url' => 'https://llm.amazee.ai',
                    'region' => 'us-east',
                ],
            ])),
        ], $history);

        $client->provisionTrial();

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('scolta-php', $request->getHeaderLine('Referer'));
    }

    // --- signIn ---

    public function testSignInReturnsToken(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['token' => 'session-tok-xyz'])),
        ]);

        $token = $client->signIn('test@example.com', '123456');
        $this->assertSame('session-tok-xyz', $token);
    }

    public function testSignInAcceptsAccessToken(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['access_token' => 'session-tok-abc'])),
        ]);

        $token = $client->signIn('test@example.com', '999999');
        $this->assertSame('session-tok-abc', $token);
    }

    public function testSignInParsesNestedTokenObject(): void
    {
        $client = $this->makeClient([
            // New API format: token is nested as {token: {access_token: '...'}}.
            new Response(200, [], json_encode(['token' => ['access_token' => 'session-tok-nested']])),
        ]);

        $token = $client->signIn('test@example.com', '123456');
        $this->assertSame('session-tok-nested', $token);
    }

    public function testSignInThrowsOnMissingToken(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['user' => 'ok'])),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('session token');
        $client->signIn('test@example.com', '000000');
    }

    // --- listRegions ---

    public function testListRegionsReturnsArray(): void
    {
        $regions = [
            ['id' => 'us-east-1', 'name' => 'US East', 'url' => 'https://us.amazee.ai'],
            ['id' => 'eu-west-1', 'name' => 'EU West', 'url' => 'https://eu.amazee.ai'],
        ];
        $client = $this->makeClient([
            new Response(200, [], json_encode(['regions' => $regions])),
        ]);

        $this->assertSame($regions, $client->listRegions('session-tok'));
    }

    public function testListRegionsSendsRefererHeader(): void
    {
        $history = [];
        $client = $this->makeClient([
            new Response(200, [], json_encode(['regions' => []])),
        ], $history);

        $client->listRegions('session-tok');

        $this->assertCount(1, $history);
        $this->assertSame('scolta-php', $history[0]['request']->getHeaderLine('Referer'));
    }

    // --- createPrivateKey ---

    public function testCreatePrivateKeySuccess(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'litellm_token' => 'paid-tok-999',
                'litellm_api_url' => 'https://paid.amazee.ai',
                'region' => 'eu-west-1',
            ])),
        ]);

        $result = $client->createPrivateKey('session-tok', 'eu-west-1');

        $this->assertTrue($result->success);
        $this->assertSame('paid-tok-999', $result->litellmToken);
        $this->assertSame('eu-west-1', $result->region);
    }

    public function testCreatePrivateKeyThrowsOnMissingToken(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['region' => 'eu-west-1'])),
        ]);

        $this->expectException(AmazeeApiException::class);
        $client->createPrivateKey('session-tok', 'eu-west-1');
    }

    // --- validateToken ---

    public function testValidateTokenSucceeds(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode(['user' => 'ok'])),
        ]);

        // Should not throw.
        $client->validateToken('tok-abc', 'https://api.amazee.ai');
        $this->assertTrue(true);
    }

    public function testValidateTokenThrowsOnNon2xx(): void
    {
        $client = $this->makeClient([
            new Response(401, [], '{"detail": "Unauthorized"}'),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('401');
        $client->validateToken('bad-tok', 'https://api.amazee.ai');
    }

    // --- HTTP status codes ---

    public function testPostThrowsOnHttpError(): void
    {
        $client = $this->makeClient([
            new Response(500, [], json_encode(['message' => 'Internal server error'])),
        ]);

        $this->expectException(AmazeeApiException::class);
        $this->expectExceptionMessage('Internal server error');
        $client->requestVerificationCode('test@example.com');
    }
}
