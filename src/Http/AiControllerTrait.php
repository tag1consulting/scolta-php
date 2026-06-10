<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Centralises AiEndpointHandler construction for platform AI controllers.
 *
 * Drupal controllers extend ControllerBase; Laravel controllers extend
 * Illuminate\Routing\Controller. Neither can extend a common PHP base class,
 * so this trait is used in both.
 *
 * Implement the three abstract methods to provide the platform-specific
 * cache driver, generation counter, and prompt enricher. Then call
 * createHandler() instead of constructing AiEndpointHandler inline.
 *
 * @since      0.3.3
 * @stability  experimental
 */
trait AiControllerTrait
{
    /**
     * Return a platform-appropriate cache driver for the given TTL.
     *
     * Implementations should return a NullCacheDriver when $cacheTtl is 0.
     *
     * @since     0.3.3
     * @stability experimental
     */
    abstract protected function resolveCache(int $cacheTtl): CacheDriverInterface;

    /**
     * Return the current cache-invalidation generation counter.
     *
     * Typically read from platform state storage. Return 0 when caching is
     * disabled or no generation counter exists (e.g. follow-up endpoints).
     *
     * @since     0.3.3
     * @stability experimental
     */
    abstract protected function getCacheGeneration(): int;

    /**
     * Return the prompt enricher for this controller.
     *
     * @since     0.3.3
     * @stability experimental
     */
    abstract protected function resolveEnricher(): PromptEnricherInterface;

    /**
     * Decode a JSON request body into the shared result shape.
     *
     * Platform controllers previously hand-rolled identical json_decode +
     * 400-error blocks; this centralizes them. Returns
     * ['ok' => true, 'data' => array] on success, or
     * ['ok' => false, 'status' => 400, 'error' => string] when the body is
     * malformed or not a JSON object/array — the same shape the
     * AiEndpointHandler handle*() methods return, so controllers can map
     * both through one error path.
     *
     * @return array{ok: bool, data?: array, status?: int, error?: string}
     *
     * @since     1.0.4
     * @stability experimental
     */
    final protected function parseJsonBody(string $rawBody): array
    {
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'status' => 400, 'error' => 'Malformed JSON: ' . $e->getMessage()];
        }

        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => 400, 'error' => 'JSON body must be an object'];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * Build a fully-configured AiEndpointHandler for a single request.
     *
     * @param object      $aiService Duck-typed AI service.
     * @param ScoltaConfig $config   Platform config object.
     *
     * @since     0.3.3
     * @stability experimental
     */
    final protected function createHandler(object $aiService, ScoltaConfig $config): AiEndpointHandler
    {
        return new AiEndpointHandler(
            aiService: $aiService,
            cache: $this->resolveCache($config->cacheTtl),
            generation: $this->getCacheGeneration(),
            cacheTtl: $config->cacheTtl,
            maxFollowUps: $config->maxFollowUps,
            promptEnricher: $this->resolveEnricher(),
            aiLanguages: $config->aiLanguages,
            aiExpandQuery: $config->aiExpandQuery,
            aiSummarize: $config->aiSummarize,
            aiSummaryMaxTokens: $config->aiSummaryMaxTokens,
            expandPrimaryWeight: $config->expandPrimaryWeight,
            sortableFields: $config->sortableFields,
            sortableFieldDescriptions: $config->sortableFieldDescriptions,
            filterFields: $config->filterFields,
            filterFieldDescriptions: $config->filterFieldDescriptions,
        );
    }
}
