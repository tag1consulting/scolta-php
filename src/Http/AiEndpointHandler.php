<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Prompt\NullEnricher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Shared business logic for all AI endpoint handlers.
 *
 * Extracts validation, caching, response parsing, and error handling
 * that was previously duplicated across Drupal, Laravel, and WordPress
 * controllers. Platform controllers become thin adapters that:
 *   1. Parse the request (platform-specific)
 *   2. Call the appropriate handle*() method
 *   3. Convert the result array to a platform response
 *
 * The AI service is duck-typed — any object with the required methods
 * (getExpandPrompt, getSummarizePrompt, getFollowUpPrompt, message,
 * conversation) will work.
 *
 * @since 0.2.0
 * @stability experimental
 */
class AiEndpointHandler
{
    /**
     * @param object                    $aiService            AI service (duck-typed).
     * @param CacheDriverInterface      $cache                Cache driver.
     * @param int                       $generation           Generation counter for cache invalidation.
     * @param int                       $cacheTtl             Cache TTL in seconds (0 = disabled).
     * @param int                       $maxFollowUps         Maximum follow-up exchanges allowed.
     * @param PromptEnricherInterface   $promptEnricher       Prompt enricher for site-specific context injection.
     * @param array                     $aiLanguages          Supported languages for multilingual responses.
     * @param LoggerInterface           $logger               PSR-3 logger (defaults to NullLogger).
     * @param bool                      $aiExpandQuery        Whether the expand-query feature is enabled.
     * @param bool                      $aiSummarize          Whether the summarize feature is enabled.
     * @param int                       $aiSummaryMaxTokens   Max tokens for AI summary responses.
     * @param float                     $expandPrimaryWeight  Weight of original results vs expansion results (0–1).
     * @param array                     $sortableFields       Metadata field names available for sort-intent detection.
     */
    public function __construct(
        private readonly object $aiService,
        private readonly CacheDriverInterface $cache,
        private readonly int $generation,
        private readonly int $cacheTtl,
        private readonly int $maxFollowUps,
        private readonly PromptEnricherInterface $promptEnricher = new NullEnricher(),
        private readonly array $aiLanguages = ['en'],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $aiExpandQuery = true,
        private readonly bool $aiSummarize = true,
        private readonly int $aiSummaryMaxTokens = 1024,
        private readonly float $expandPrimaryWeight = 0.5,
        private readonly array $sortableFields = [],
    ) {
    }

    /**
     * Handle an expand-query request.
     *
     * @param string $query The search query to expand.
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleExpandQuery(string $query): array
    {
        if (!$this->aiExpandQuery) {
            return ['ok' => false, 'status' => 404, 'error' => 'Feature disabled'];
        }

        $query = trim($query);

        if ($query === '' || strlen($query) > 500) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid query'];
        }

        // Cache lookup.
        $cacheKey = $this->cacheKey('expand', $query);
        if ($this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return ['ok' => true, 'data' => $cached];
            }
        }

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getExpandPrompt(),
                'expand_query',
                ['query' => $query],
            );
            $systemPrompt = $this->appendLanguageInstruction($systemPrompt, 'expand_query');
            $systemPrompt = $this->appendSortableFieldsInstruction($systemPrompt);

            $response = $this->aiService->messageForOperation(
                'expand_query',
                $systemPrompt,
                'Expand this search query: ' . $query,
                512,
            );

            $parsed = $this->parseExpansionResult($response, $query);

            $payload = [
                'terms'                => $parsed['terms'],
                'expand_primary_weight' => $this->expandPrimaryWeight,
            ];

            if ($parsed['sort_hint'] !== null) {
                $payload['sort_hint'] = $parsed['sort_hint'];
            }

            if ($this->cacheTtl > 0) {
                $this->cache->set($cacheKey, $payload, $this->cacheTtl);
            }

            return ['ok' => true, 'data' => $payload];
        } catch (ApiKeyMissingException $e) {
            return ['ok' => true, 'data' => ['terms' => [$query], 'expand_primary_weight' => $this->expandPrimaryWeight]];
        } catch (\Exception $e) {
            $this->logger->error('Scolta query expansion failed', ['exception' => $e]);

            return ['ok' => false, 'status' => 503, 'error' => 'Query expansion unavailable'];
        }
    }

    /**
     * Handle a summarize request.
     *
     * @param string $query   The search query.
     * @param string $context Search result excerpts.
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleSummarize(string $query, string $context): array
    {
        if (!$this->aiSummarize) {
            return ['ok' => false, 'status' => 404, 'error' => 'Feature disabled'];
        }

        $query = trim($query);
        $context = trim($context);

        if ($query === '' || strlen($query) > 500) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid query'];
        }
        // Client truncates to 49,000; this is a safety net.
        if ($context === '' || strlen($context) > 100000) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid context'];
        }

        // Cache lookup.
        $cacheKey = $this->cacheKey('summarize', $query, $context);
        if ($this->cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return ['ok' => true, 'data' => $cached];
            }
        }

        $userMessage = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getSummarizePrompt(),
                'summarize',
                ['query' => $query, 'context' => $context],
            );
            $systemPrompt = $this->appendLanguageInstruction($systemPrompt, 'summarize');

            $summary = $this->aiService->message(
                $systemPrompt,
                $userMessage,
                $this->aiSummaryMaxTokens,
            );

            $result = ['summary' => $summary];

            if ($this->cacheTtl > 0) {
                $this->cache->set($cacheKey, $result, $this->cacheTtl);
            }

            return ['ok' => true, 'data' => $result];
        } catch (ApiKeyMissingException $e) {
            return ['ok' => true, 'data' => []];
        } catch (\Exception $e) {
            $this->logger->error('Scolta summarization failed', ['exception' => $e]);

            return ['ok' => false, 'status' => 503, 'error' => 'Summarization unavailable'];
        }
    }

    /**
     * Handle a follow-up conversation request.
     *
     * @param array $messages Conversation messages (role + content pairs).
     * @return array{ok: bool, data?: mixed, status?: int, error?: string}
     */
    public function handleFollowUp(array $messages): array
    {
        // Validate messages array.
        if (empty($messages) || !is_array($messages)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Messages required'];
        }

        // Validate each message has role and content.
        foreach ($messages as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid message format'];
            }
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid role'];
            }
        }

        // Last message must be from the user.
        if (end($messages)['role'] !== 'user') {
            return ['ok' => false, 'status' => 400, 'error' => 'Last message must be from user'];
        }

        // Enforce follow-up limit server-side.
        $followUpsSoFar = intdiv(count($messages) - 2, 2);
        if ($followUpsSoFar >= $this->maxFollowUps) {
            return [
                'ok' => false,
                'status' => 429,
                'error' => 'Follow-up limit reached',
                'limit' => $this->maxFollowUps,
            ];
        }

        try {
            $systemPrompt = $this->promptEnricher->enrich(
                $this->aiService->getFollowUpPrompt(),
                'follow_up',
                ['messages' => $messages],
            );
            $systemPrompt = $this->appendLanguageInstruction($systemPrompt, 'follow_up');

            $response = $this->aiService->conversation(
                $systemPrompt,
                $messages,
                512,
            );

            $remaining = $this->maxFollowUps - $followUpsSoFar - 1;

            return ['ok' => true, 'data' => [
                'response' => $response,
                'remaining' => max(0, $remaining),
            ]];
        } catch (ApiKeyMissingException $e) {
            return ['ok' => true, 'data' => ['response' => '', 'remaining' => 0]];
        } catch (\Exception $e) {
            $this->logger->error('Scolta follow-up failed', ['exception' => $e]);

            return ['ok' => false, 'status' => 503, 'error' => 'Follow-up unavailable'];
        }
    }

    /**
     * Append a multilingual language instruction to a prompt.
     *
     * When multiple languages are configured, instructs the LLM to respond
     * in the same language as the user's query if it matches a supported
     * language, otherwise fall back to the primary language.
     *
     * For expand_query prompts, instructs the LLM to return expansion terms
     * in the same language as the original query.
     *
     * Single-language configurations (the default) do not add any instruction,
     * preserving backward-compatible behavior.
     *
     * @param string $prompt     The resolved prompt text.
     * @param string $promptType The prompt type: 'expand_query', 'summarize', or 'follow_up'.
     * @return string The prompt with language instruction appended (if applicable).
     *
     * @since 0.2.0
     * @stability experimental
     */
    private function appendLanguageInstruction(string $prompt, string $promptType): string
    {
        if (count($this->aiLanguages) <= 1) {
            return $prompt;
        }

        $languages = implode(', ', $this->aiLanguages);
        $primary = $this->aiLanguages[0];

        if ($promptType === 'expand_query') {
            $prompt .= "\n\nReturn expansion terms in the same language as the original query if it matches one of these supported languages: {$languages}. Otherwise return terms in {$primary}.";
        } else {
            $prompt .= "\n\nRespond in the same language as the user's query if it matches one of these supported languages: {$languages}. Otherwise respond in {$primary}.";
        }

        return $prompt;
    }

    /**
     * Parse an AI expansion response, stripping markdown fences.
     *
     * Handles both the current object format {"terms": [...]} and the legacy
     * array format [...] so cached responses and custom prompts continue to work.
     *
     * @param string $response      Raw AI response text.
     * @param string $originalQuery The original query (used as fallback).
     * @return array The parsed list of search terms.
     */
    public function parseExpansionResponse(string $response, string $originalQuery): array
    {
        return $this->parseExpansionResult($response, $originalQuery)['terms'];
    }

    /**
     * Parse an AI expansion response and extract both terms and an optional sort hint.
     *
     * Returns ['terms' => string[], 'sort_hint' => array{field: string, direction: string}|null].
     * Parses defensively: malformed or absent sort_hint is silently ignored.
     *
     * @param string $response      Raw AI response text.
     * @param string $originalQuery The original query (used as fallback for terms).
     * @return array{terms: array, sort_hint: array{field: string, direction: string}|null}
     */
    protected function parseExpansionResult(string $response, string $originalQuery): array
    {
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        // New object format: {"terms": [...], "sort": {...}}
        if (is_array($decoded) && isset($decoded['terms']) && is_array($decoded['terms'])) {
            $terms = count($decoded['terms']) >= 2 ? $decoded['terms'] : [$originalQuery];
            $sortHint = $this->extractSortHint($decoded['sort'] ?? null);

            return ['terms' => $terms, 'sort_hint' => $sortHint];
        }

        // Legacy array format: ["term1", "term2", ...]
        if (is_array($decoded) && count($decoded) >= 2) {
            return ['terms' => $decoded, 'sort_hint' => null];
        }

        return ['terms' => [$originalQuery], 'sort_hint' => null];
    }

    /**
     * Validate and normalise a raw sort hint from the LLM response.
     *
     * Returns null when the hint is absent, malformed, or references an
     * invalid direction — so a bad LLM response never breaks expansion.
     *
     * @param mixed $raw The raw "sort" value from the decoded JSON.
     * @return array{field: string, direction: string}|null
     */
    private function extractSortHint(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $field = $raw['field'] ?? null;
        $direction = $raw['direction'] ?? null;

        if (!is_string($field) || $field === '') {
            return null;
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            return null;
        }

        // Only honour a sort hint when sortable fields are configured and the
        // suggested field is in that list. No configured fields → no sort hints.
        if (empty($this->sortableFields) || !in_array($field, $this->sortableFields, true)) {
            return null;
        }

        return ['field' => $field, 'direction' => $direction];
    }

    /**
     * Append sort-intent instructions to the expansion prompt when sortable fields are configured.
     *
     * When no sortable fields are configured the prompt is returned unchanged,
     * so sites that have not opted into sortable metadata see zero behaviour change.
     *
     * @param string $prompt The resolved, enriched system prompt.
     * @return string The prompt with sort-intent instructions appended (when applicable).
     */
    private function appendSortableFieldsInstruction(string $prompt): string
    {
        if (empty($this->sortableFields)) {
            return $prompt;
        }

        $fieldList = implode(', ', $this->sortableFields);

        $prompt .= "\n\nSORT INTENT (optional):\n"
            . "Available sortable fields: {$fieldList}\n\n"
            . "Only add a \"sort\" key when sorting results is the query's PRIMARY purpose — the user explicitly wants results ranked by a specific field.\n"
            . "Format: {\"terms\": [...], \"sort\": {\"field\": \"price\", \"direction\": \"desc\"}}\n\n"
            . "Rules:\n"
            . "- field MUST be one of the available sortable fields listed above — no other values are permitted\n"
            . "- direction must be \"asc\" or \"desc\"\n"
            . "- The sort signal must map DIRECTLY to a specific field's semantics: 'most expensive' → price (desc), 'newest' → date (desc), 'cheapest' → price (asc). If there is no clear, direct semantic match to an available field, do NOT add sort.\n"
            . "- SUPERLATIVES AS QUALIFIERS: superlatives like 'most popular', 'best known', 'most common', 'top rated', 'well known' describe what TYPE of item the user wants to discover — they are NOT sort intent. 'Most popular crystals' means 'find well-known crystals' (a discovery query), NOT 'sort crystals by a popularity field'. Do not classify these as sort intent even if a vaguely related field exists.\n"
            . "- Only classify sort intent for short (2–4 word) queries where the sort word IS the primary goal, not a qualifier.\n"
            . "- Do NOT classify sort intent for research questions, conversational queries, or any query where the sort-like word is a modifier rather than the main goal.\n"
            . "  Counter-examples (must NOT trigger sort): 'most popular crystals', 'the latest research on...', 'most common git commands', 'best practices for...', 'cheapest way to comply with...', 'first aid for...'\n"
            . "- Prefer false negatives over false positives: a missed sort hint is far less harmful than an incorrect result reorder. When uncertain, ALWAYS omit the \"sort\" key.\n"
            . "- When sort is detected, exclude the sort signal words (most, cheapest, newest, highest, lowest, etc.) from the expanded terms";

        return $prompt;
    }

    /**
     * Generate a deterministic cache key.
     *
     * @param string $action The action name (expand, summarize).
     * @param string ...$parts Variable parts to hash.
     * @return string The cache key.
     */
    public function cacheKey(string $action, string ...$parts): string
    {
        $hashInput = strtolower(implode('|', $parts));
        return 'scolta_' . $action . '_' . $this->generation . '_' . hash('sha256', $hashInput);
    }
}
