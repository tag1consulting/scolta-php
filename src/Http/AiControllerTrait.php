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
            $aiService,
            $this->resolveCache($config->cacheTtl),
            $this->getCacheGeneration(),
            $config->cacheTtl,
            $config->maxFollowUps,
            $this->resolveEnricher(),
            $config->aiLanguages,
        );
    }
}
