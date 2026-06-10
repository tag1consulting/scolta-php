<?php

declare(strict_types=1);

namespace Tag1\Scolta\AiProvider\Amazee;

/**
 * Picks the best available Claude model for each inference role.
 *
 * Given the raw model list from the LiteLLM proxy /model/info endpoint,
 * selects the highest-versioned Sonnet (for ai_model) and the
 * highest-versioned Haiku (for ai_expansion_model).
 *
 * @since 0.4.0
 * @stability experimental
 */
final class AmazeeModelResolver
{
    private readonly AmazeeClient $client;

    public function __construct(AmazeeClient $client)
    {
        $this->client = $client;
    }

    /**
     * Resolve the best models from the provisioned endpoint.
     *
     * @return array{ai_model: string|null, ai_expansion_model: string|null}
     * @since 1.0.0
     * @stability stable
     */
    public function resolve(string $litellmApiUrl, string $litellmToken): array
    {
        $models = $this->client->getAvailableModels($litellmApiUrl, $litellmToken);

        $names = array_filter(
            array_map(
                fn(mixed $m) => is_array($m) && isset($m['model_name']) && is_string($m['model_name'])
                    ? $m['model_name']
                    : null,
                $models,
            ),
        );

        return [
            'ai_model' => $this->pickHighestVersion($names, 'sonnet'),
            'ai_expansion_model' => $this->pickHighestVersion($names, 'haiku'),
        ];
    }

    /**
     * From a list of model names, pick the one whose version tuple is highest
     * among those containing $family (case-insensitive).
     *
     * Version tuples are extracted by splitting on `-` and keeping only
     * purely-numeric segments: `claude-sonnet-4-6` → [4, 6];
     * `claude-3-5-sonnet-20241022` → [3, 5, 20241022].
     *
     * @since 1.0.0
     * @stability stable
     */
    public function pickHighestVersion(array $names, string $family): ?string
    {
        $best = null;
        $bestVersion = [];

        foreach ($names as $name) {
            if (!str_contains(strtolower($name), strtolower($family))) {
                continue;
            }

            $version = $this->extractVersion($name);
            if ($this->compareVersions($version, $bestVersion) > 0) {
                $best = $name;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    /**
     * @return int[]
     */
    private function extractVersion(string $name): array
    {
        $segments = explode('-', $name);
        return array_values(
            array_map(
                'intval',
                array_filter($segments, 'ctype_digit'),
            ),
        );
    }

    /**
     * @param int[] $a
     * @param int[] $b
     */
    private function compareVersions(array $a, array $b): int
    {
        $len = max(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $diff = ($a[$i] ?? 0) - ($b[$i] ?? 0);
            if ($diff !== 0) {
                return $diff;
            }
        }
        return 0;
    }
}
