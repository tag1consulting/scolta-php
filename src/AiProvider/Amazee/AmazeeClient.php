<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for the Amazee.ai auth and provisioning API.
 *
 * Handles trial provisioning and account upgrade flows against the
 * Amazee.ai control-plane endpoints. For LLM inference, platform adapters
 * configure the existing AiClient with the returned LiteLLM credentials.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeClient
{
    private const DEFAULT_BASE_URL = 'https://api.amazee.ai';
    private const TIMEOUT = 15;

    private readonly ClientInterface $httpClient;
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?ClientInterface $httpClient = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Provision a free trial account.
     *
     * Calls POST /auth/generate-trial-access and validates the token via
     * GET /auth/me. Returns the LiteLLM credentials on success.
     *
     * @param string $email Optional email for the trial account. Pass an empty
     *   string for anonymous provisioning (the API accepts it).
     *
     * @throws AmazeeApiException On API error or unexpected response shape.
     */
    public function provisionTrial(string $email = ''): ProvisioningResult
    {
        $body = $this->post('/auth/generate-trial-access', ['email' => $email]);

        // Credentials may be nested under a 'key' object (new API format) or
        // flat at the top level (legacy format).
        $creds = is_array($body['key'] ?? null) ? $body['key'] : $body;
        $token = $creds['litellm_token'] ?? null;
        $apiUrl = $creds['litellm_api_url'] ?? null;
        $region = $creds['region'] ?? 'default';

        if (!is_string($token) || $token === '' || !is_string($apiUrl) || $apiUrl === '') {
            throw new AmazeeApiException(
                'Amazee.ai trial provisioning response missing litellm_token or litellm_api_url.'
            );
        }

        // Validate the returned token is usable.
        $this->validateToken($token, $apiUrl);

        return ProvisioningResult::success($token, $apiUrl, $region);
    }

    /**
     * Request an email verification code to begin the upgrade flow.
     *
     * @throws AmazeeApiException On API error.
     */
    public function requestVerificationCode(string $email): void
    {
        $this->post('/auth/validate-email', ['email' => $email]);
    }

    /**
     * Exchange a verification code for a session token.
     *
     * Returns the session token string used in subsequent upgrade calls.
     *
     * @throws AmazeeApiException On API error or missing token in response.
     */
    public function signIn(string $email, string $code): string
    {
        $body = $this->post('/auth/sign-in', ['email' => $email, 'code' => $code]);

        // Token may be nested under 'token.access_token' (new API format) or
        // flat as 'token' or 'access_token' (legacy formats).
        $tokenField = $body['token'] ?? null;
        $sessionToken = is_array($tokenField)
            ? ($tokenField['access_token'] ?? null)
            : ($tokenField ?? ($body['access_token'] ?? null));
        if (!is_string($sessionToken) || $sessionToken === '') {
            throw new AmazeeApiException('Amazee.ai sign-in response missing session token.');
        }

        return $sessionToken;
    }

    /**
     * List available regions for the account.
     *
     * @return array<int, array{id: string, name: string, url: string}>
     *
     * @throws AmazeeApiException On API error.
     */
    public function listRegions(string $sessionToken): array
    {
        $body = $this->get('/regions', $sessionToken);
        return $body['regions'] ?? $body;
    }

    /**
     * Provision a private AI key in the selected region.
     *
     * Returns the LiteLLM credentials for the upgraded account.
     *
     * @throws AmazeeApiException On API error or missing credentials.
     */
    public function createPrivateKey(string $sessionToken, string $regionId): UpgradeResult
    {
        $body = $this->post('/private-ai-keys', ['region_id' => $regionId], $sessionToken);

        $token = $body['litellm_token'] ?? null;
        $apiUrl = $body['litellm_api_url'] ?? null;
        $region = $body['region'] ?? $regionId;

        if (!is_string($token) || $token === '' || !is_string($apiUrl) || $apiUrl === '') {
            throw new AmazeeApiException(
                'Amazee.ai private key creation response missing litellm_token or litellm_api_url.'
            );
        }

        return UpgradeResult::success($token, $apiUrl, $region);
    }

    /**
     * List available models from the provisioned LiteLLM proxy endpoint.
     *
     * Returns the raw model objects from the `data` array of GET /model/info.
     * Returns an empty array on error so callers degrade gracefully.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableModels(string $litellmApiUrl, string $litellmToken): array
    {
        $url = rtrim($litellmApiUrl, '/') . '/model/info';
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $litellmToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return [];
            }

            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return is_array($body['data'] ?? null) ? $body['data'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Validate a LiteLLM token by calling the /auth/me endpoint on the API URL.
     *
     * @throws AmazeeApiException If the token is invalid or the request fails.
     */
    public function validateToken(string $litellmToken, string $litellmApiUrl): void
    {
        $url = rtrim($litellmApiUrl, '/') . '/auth/me';
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $litellmToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new AmazeeApiException(
                    "Amazee.ai token validation failed with HTTP {$statusCode}.",
                    $statusCode
                );
            }
        } catch (GuzzleException $e) {
            throw new AmazeeApiException(
                'Amazee.ai token validation request failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * POST to a control-plane endpoint and return decoded JSON body.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws AmazeeApiException On HTTP or JSON error.
     */
    private function post(string $path, array $payload, ?string $bearerToken = null): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . $path, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => self::TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            throw new AmazeeApiException(
                "Amazee.ai API request to {$path} failed: " . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->decodeResponse($path, $response);
    }

    /**
     * GET a control-plane endpoint and return decoded JSON body.
     *
     * @return array<string, mixed>
     *
     * @throws AmazeeApiException On HTTP or JSON error.
     */
    private function get(string $path, ?string $bearerToken = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . $path, [
                'headers' => $headers,
                'timeout' => self::TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            throw new AmazeeApiException(
                "Amazee.ai API request to {$path} failed: " . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->decodeResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AmazeeApiException On non-2xx status or JSON decode error.
     */
    private function decodeResponse(string $path, \Psr\Http\Message\ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = "Amazee.ai API returned HTTP {$statusCode} for {$path}.";
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (isset($data['detail'])) {
                    $message .= ' ' . $data['detail'];
                } elseif (isset($data['message'])) {
                    $message .= ' ' . $data['message'];
                }
            } catch (\JsonException) {
            }
            throw new AmazeeApiException($message, $statusCode);
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException $e) {
            throw new AmazeeApiException(
                "Amazee.ai API returned malformed JSON from {$path}: " . $e->getMessage(),
                $statusCode,
                $e
            );
        }
    }
}
