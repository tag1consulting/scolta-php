<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Prompt\NullEnricher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

class AiControllerTraitTest extends TestCase
{
    public function testCreateHandlerReturnsAiEndpointHandler(): void
    {
        $controller = new ConcreteAiController();
        $config     = new ScoltaConfig();

        $handler = $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertInstanceOf(AiEndpointHandler::class, $handler);
    }

    public function testCreateHandlerPassesCacheTtlToResolveCache(): void
    {
        $controller     = new ConcreteAiController();
        $config         = new ScoltaConfig();
        $config->cacheTtl = 300;

        $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertSame(300, $controller->lastCacheTtl);
    }

    public function testCreateHandlerPassesZeroCacheTtl(): void
    {
        $controller       = new ConcreteAiController();
        $config           = new ScoltaConfig();
        $config->cacheTtl = 0;

        $controller->exposeCreateHandler(new \stdClass(), $config);

        $this->assertSame(0, $controller->lastCacheTtl);
    }
}

/** @internal test double */
class ConcreteAiController
{
    use AiControllerTrait;

    public int $lastCacheTtl = -1;

    public function exposeCreateHandler(object $aiService, ScoltaConfig $config): AiEndpointHandler
    {
        return $this->createHandler($aiService, $config);
    }

    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        $this->lastCacheTtl = $cacheTtl;
        return new NullCacheDriver();
    }

    protected function getCacheGeneration(): int
    {
        return 0;
    }

    protected function resolveEnricher(): PromptEnricherInterface
    {
        return new NullEnricher();
    }
}
