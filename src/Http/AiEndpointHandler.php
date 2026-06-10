<?php

declare(strict_types=1);

namespace Tag1\Scolta\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Exception\ApiKeyInvalidException;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Exception\RateLimitException;
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
     * Per-message content cap for follow-up conversations, in bytes.
     *
     * The first user turn legitimately embeds search-result context (the
     * client trims each result to aiSummaryMaxChars, ~4000 default), so this
     * mirrors handleSummarize()'s 100k context safety net.
     */
    private const FOLLOW_UP_MAX_MESSAGE_BYTES = 100000;

    /**
     * Total content cap across all follow-up messages, in bytes.
     *
     * A full default conversation (initial context turn + 3 follow-ups, each
     * with fresh search context) stays well under this; it only stops abuse.
     */
    private const FOLLOW_UP_MAX_TOTAL_BYTES = 400000;

    /**
     * @param object                    $aiService                  AI service (duck-typed).
     * @param CacheDriverInterface      $cache                      Cache driver.
     * @param int                       $generation                 Generation counter for cache invalidation.
     * @param int                       $cacheTtl                   Cache TTL in seconds (0 = disabled).
     * @param int                       $maxFollowUps               Maximum follow-up exchanges allowed.
     * @param PromptEnricherInterface   $promptEnricher             Prompt enricher for site-specific context injection.
     * @param array                     $aiLanguages                Supported languages for multilingual responses.
     * @param LoggerInterface           $logger                     PSR-3 logger (defaults to NullLogger).
     * @param bool                      $aiExpandQuery              Whether the expand-query feature is enabled.
     * @param bool                      $aiSummarize                Whether the summarize feature is enabled.
     * @param int                       $aiSummaryMaxTokens         Max tokens for AI summary responses.
     * @param float                     $expandPrimaryWeight        Weight of original results vs expansion results (0–1).
     * @param array                     $sortableFields             Metadata field names available for sort-intent detection.
     * @param array<string, string>     $sortableFieldDescriptions  Human-readable descriptions for sortable fields.
     * @param array                     $filterFields               Filter dimension names for filter-intent detection.
     * @param array<string, string>     $filterFieldDescriptions    Human-readable descriptions for filter fields.
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
        private readonly array $sortableFieldDescriptions = [],
        private readonly array $filterFields = [],
        private readonly array $filterFieldDescriptions = [],
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
            $systemPrompt = $this->appendFilterFieldsInstruction($systemPrompt);

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

            if ($parsed['subject_terms'] !== null) {
                $payload['subject_terms'] = $parsed['subject_terms'];
            }

            if ($parsed['filter_hint'] !== null) {
                $payload['filter_hint'] = $parsed['filter_hint'];
            }

            if ($this->cacheTtl > 0) {
                $this->cache->set($cacheKey, $payload, $this->cacheTtl);
            }

            return ['ok' => true, 'data' => $payload];
        } catch (ApiKeyMissingException $e) {
            // AI is unconfigured — an expected state, degrade silently (no log).
            return ['ok' => true, 'data' => ['terms' => [$query], 'expand_primary_weight' => $this->expandPrimaryWeight]];
        } catch (\Exception $e) {
            // Query expansion is a non-essential search enhancement. Any provider
            // failure (invalid key, rate limit, transport error, malformed
            // response, budget exceeded) degrades to unexpanded search (HTTP 200)
            // rather than returning a 503 that blocks the search path and spams
            // the client console. The distinct underlying error is preserved in
            // the server log so genuine provider/config outages stay diagnosable.
            $this->logger->error('Scolta query expansion failed, serving unexpanded results', ['exception' => $e]);

            return ['ok' => true, 'data' => ['terms' => [$query], 'expand_primary_weight' => $this->expandPrimaryWeight]];
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
            // AI is unconfigured — an expected state, degrade silently (no log).
            return ['ok' => true, 'data' => []];
        } catch (\Exception $e) {
            // Summarization is a non-essential enhancement layered above the
            // search results. Any provider failure degrades to "no summary"
            // (HTTP 200) instead of a 503 that surfaces an error banner — the
            // results themselves are unaffected. The distinct underlying error is
            // preserved in the server log so genuine outages stay diagnosable.
            $this->logger->error('Scolta summarization failed, serving results without a summary', ['exception' => $e]);

            return ['ok' => true, 'data' => []];
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

        // Validate each message has a string role and non-empty string content,
        // within byte caps. Strict === '' comparison (not empty()) so the
        // literal message "0" is not rejected. The sibling endpoints cap their
        // inputs (query 500, summarize context 100k); without caps here a
        // client could relay arbitrarily large payloads to the AI provider.
        $totalBytes = 0;
        foreach ($messages as $msg) {
            $role = is_array($msg) ? ($msg['role'] ?? null) : null;
            $content = is_array($msg) ? ($msg['content'] ?? null) : null;
            if (!is_string($role) || !is_string($content) || $content === '') {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid message format'];
            }
            if (!in_array($role, ['user', 'assistant'], true)) {
                return ['ok' => false, 'status' => 400, 'error' => 'Invalid role'];
            }
            if (strlen($content) > self::FOLLOW_UP_MAX_MESSAGE_BYTES) {
                return ['ok' => false, 'status' => 400, 'error' => 'Message too long'];
            }
            $totalBytes += strlen($content);
        }
        if ($totalBytes > self::FOLLOW_UP_MAX_TOTAL_BYTES) {
            return ['ok' => false, 'status' => 400, 'error' => 'Conversation too long'];
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
        } catch (ApiKeyInvalidException $e) {
            $this->logger->error('Scolta follow-up failed: invalid API key', ['exception' => $e]);

            return ['ok' => false, 'status' => 401, 'error' => 'AI API key is invalid or expired'];
        } catch (RateLimitException $e) {
            $result = ['ok' => false, 'status' => 429, 'error' => 'AI API rate limit reached'];
            if ($e->retryAfter !== null) {
                $result['retry_after'] = $e->retryAfter;
            }

            return $result;
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
     * Parse an AI expansion response and extract terms, an optional sort hint, optional subject terms, and optional filter hint.
     *
     * Returns ['terms' => string[], 'sort_hint' => array{field: string, direction: string}|null, 'subject_terms' => string[]|null, 'filter_hint' => array<string,string>|null].
     * Parses defensively: malformed or absent fields are silently ignored.
     *
     * @param string $response      Raw AI response text.
     * @param string $originalQuery The original query (used as fallback for terms).
     * @return array{terms: array, sort_hint: array{field: string, direction: string}|null, subject_terms: array|null, filter_hint: array<string,string>|null}
     */
    protected function parseExpansionResult(string $response, string $originalQuery): array
    {
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        // New object format: {"terms": [...], "sort": {...}, "subject_terms": [...], "filters": {...}}
        if (is_array($decoded) && isset($decoded['terms']) && is_array($decoded['terms'])) {
            $terms = count($decoded['terms']) >= 2 ? $decoded['terms'] : [$originalQuery];
            $sortHint = $this->extractSortHint($decoded['sort'] ?? null);
            $subjectTerms = $this->extractSubjectTerms($decoded['subject_terms'] ?? null);
            $filterHint = $this->extractFilterHint($decoded['filters'] ?? null);

            return ['terms' => $terms, 'sort_hint' => $sortHint, 'subject_terms' => $subjectTerms, 'filter_hint' => $filterHint];
        }

        // Legacy array format: ["term1", "term2", ...]
        if (is_array($decoded) && count($decoded) >= 2) {
            return ['terms' => $decoded, 'sort_hint' => null, 'subject_terms' => null, 'filter_hint' => null];
        }

        return ['terms' => [$originalQuery], 'sort_hint' => null, 'subject_terms' => null, 'filter_hint' => null];
    }

    /**
     * Validate and normalise a raw subject_terms value from the LLM response.
     *
     * Returns null when the value is absent, malformed, or empty — so a bad LLM
     * response never breaks expansion.
     *
     * @param mixed $raw The raw "subject_terms" value from the decoded JSON.
     * @return string[]|null
     */
    private function extractSubjectTerms(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $filtered = array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''));

        return !empty($filtered) ? $filtered : null;
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
     * Validate and normalise a raw filter hint from the LLM response.
     *
     * Returns null when the hint is absent, malformed, or references invalid
     * dimensions — so a bad LLM response never breaks expansion.
     *
     * @param mixed $raw The raw "filters" value from the decoded JSON.
     * @return array<string, string>|null Validated dimension → value pairs, or null.
     *
     * @since 1.1.0
     * @stability experimental
     */
    private function extractFilterHint(mixed $raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }

        $validated = [];
        foreach ($raw as $dimension => $value) {
            if (!is_string($dimension) || $dimension === '') {
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            // Only honour filter hints when filter fields are configured and the dimension is in the list.
            if (empty($this->filterFields) || !in_array($dimension, $this->filterFields, true)) {
                continue;
            }
            $validated[$dimension] = $value;
        }

        return !empty($validated) ? $validated : null;
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

        // Build field list with descriptions when available.
        $fieldLines = [];
        foreach ($this->sortableFields as $field) {
            $desc = $this->sortableFieldDescriptions[$field] ?? '';
            $fieldLines[] = $desc !== '' ? "- {$field}: {$desc}" : "- {$field}";
        }
        $fieldList = implode("\n", $fieldLines);

        $prompt .= <<<'ENDSORTINSTR'


SORT INTENT (optional):
Available sortable fields:
{FIELD_LIST}

Only add a "sort" key when sorting results is the query's PRIMARY purpose — the user explicitly wants results ordered by a specific field's value.

Format: {"terms": [...], "sort": {"field": "<field_name>", "direction": "asc|desc"}, "subject_terms": ["..."]}

DECISION SEQUENCE — follow in order, stop at the first match:

STEP 0: FIELD AVAILABILITY CHECK (always perform first)
Identify what concept the user's sort intent implies (recency, length, price, citation count, etc.). Check if ANY available sortable field directly measures that exact concept. If no field measures it, set sort to null and skip all further steps. Do NOT substitute an unrelated field.
Example: "newest articles" → concept is RECENCY → requires a date/time field → no date field in the list → sort is null, STOP.
Example: "oldest history articles" → concept is RECENCY → requires a date/time field → no date field in the list → sort is null, STOP.

STEP 1: EXPLICIT SORT SYNTAX (always classify)
If the query contains "sort by", "sorted by", "order by", "arrange by", "rank by", or "group by" followed by a field name or concept, this IS sort intent regardless of query length or complexity. Map the named concept to the closest available field.
Examples: "sort by date" → date desc, "articles about wars sorted by date" → date desc, "rank by word count" → word_count desc.

STEP 2: DISCOVERY QUALIFIERS / SUPERLATIVES AS QUALIFIERS (never classify as sort)
If the sort-like word is a QUALIFIER describing what TYPE of result the user wants to discover — not how to ORDER them — do NOT add sort. These qualifiers describe the item itself, not a ranking preference:
- "most common", "most popular", "best known", "well known", "top rated", "widely used", "most famous", "most important", "best", "worst", "top", "leading"
- These words answer "WHICH items?" not "IN WHAT ORDER?"
- "Most common elements" = "find elements that are well-known" (discovery) — NOT "sort elements by commonality"
- "Most popular crystals" = "find famous crystals" — NOT "sort by a popularity metric"
- "Best practices for deployment" = "find good practices" — NOT sort intent
- "Top programming languages" = "find the leading languages" — NOT sort intent
NOT discovery qualifiers — these describe MEASURABLE quantities, not subjective fame:
- "most cited", "most referenced" → these count citations, which is a measurable numeric field, NOT a fame/popularity qualifier. Proceed to STEP 4.
- "longest", "shortest", "most words" → these measure word count, a numeric field. Proceed to STEP 4.
EXCEPTION: classify as sort ONLY if a field explicitly described as measuring the qualifier concept exists (e.g., a "popularity_score" field for "most popular") AND the query is clearly requesting an ordered list, not information about popular items.

STEP 3: RESEARCH / ADVICE / CONVERSATIONAL QUERIES (never classify as sort)
If the query is asking a question, seeking advice, or requesting information, do NOT add sort — even if sort-like words appear:
- "the latest research on..." (research question, "latest" = current, not a date sort)
- "cheapest way to comply with..." (advice question)
- "first aid for radiation exposure" ("first" is not temporal)
- "what are the newest trends in..." (informational)
- "best practices for..." (advice)
- "how to find the most..." (instructional)

STEP 4: CLEAR SORT-INTENT SIGNALS (classify when a matching field exists)
If the query's primary purpose is ordering results by a measurable field, AND a matching sortable field exists, add sort. Map user language to available fields AND directions:
  · Price/cost (desc): "most expensive", "priciest", "highest price", "costliest" → price field, direction desc
  · Price/cost (asc): "cheapest", "lowest price", "most affordable", "least expensive", "budget" → price field, direction asc
  · Recency (desc): "newest", "latest", "most recent" → date field, direction desc
  · Recency (asc): "oldest", "earliest" → date field, direction asc
  · Recency (adverb forms): "recently updated", "recently added", "recently published", "newly created", "freshly posted" → date field (desc)
  · Size/depth: "longest", "most comprehensive", "most in-depth", "most detailed" → length/count field (desc); "shortest" → length/count field (asc)
  · Citation/quality: "most cited", "most referenced", "best researched" → citation/reference count field (desc)
  · Severity: "most severe", "highest risk", "most critical" → severity/risk field (desc)
  · Engagement: "most starred", "most liked", "most discussed" → engagement count field (desc)
  If there is no clear, direct semantic match between the user's language and an available field's description, do NOT add sort.
  WRONG: "newest articles" → word_count desc (word_count measures LENGTH, not DATE — this is a concept mismatch, omit sort)
  WRONG: "oldest articles" → reference_count asc (reference_count measures CITATIONS, not DATE — omit sort)
  RIGHT: "newest articles" when no date field exists → omit sort entirely

GENERAL RULES:
- field MUST be one of the available sortable fields listed above — no other values permitted.
- direction must be "asc" or "desc".
- NEVER SUBSTITUTE A DIFFERENT FIELD. If the user's sort intent maps to a concept with no matching available field, omit sort entirely. For example: "newest" implies date sorting — if no date field is available, do NOT fall back to word_count or any other field. A wrong sort is worse than no sort.
- Prefer false negatives over false positives: a missed sort is far less harmful than a wrong reorder. When uncertain, ALWAYS omit "sort".
- When sort is detected, exclude sort signal words (most, cheapest, affordable, budget, newest, highest, lowest, recently, etc.) from expanded terms.

SUBJECT TERMS (required when sort is detected):
When you add a "sort" key, you MUST also add a "subject_terms" array containing ONLY the words from the original query that describe WHAT the user is searching for — with all sort-related words removed. Sort-related words include: superlatives (most, least, best, worst), comparatives (more, less, cheaper, faster), order words (expensive, cheap, costly, affordable, budget, newest, oldest, latest, earliest), adverb modifiers (recently, newly, freshly), explicit sort syntax (sort by, sorted by, order by), and the sort field name itself.
Examples:
- "most expensive tooth" → subject_terms: ["tooth"]
- "cheapest blue stone" → subject_terms: ["blue stone"]
- "most affordable crystals" → subject_terms: ["crystals"]
- "least expensive gemstones" → subject_terms: ["gemstones"]
- "newest blog posts about React" → subject_terms: ["blog posts about React"]
- "most comprehensive quantum physics articles" → subject_terms: ["quantum physics articles"]
- "most expensive" → subject_terms: [] (empty — query is ONLY sort intent, no subject)
- "oldest fossils" → subject_terms: ["fossils"]
- "recently updated chemistry articles" → subject_terms: ["chemistry articles"]
- "articles about wars sorted by date" → subject_terms: ["articles about wars"]
If no sort is detected, omit subject_terms entirely.
ENDSORTINSTR;

        $prompt = str_replace('{FIELD_LIST}', $fieldList, $prompt);

        return $prompt;
    }

    /**
     * Append filter-intent instructions to the expansion prompt when filter fields are configured.
     *
     * When no filter fields are configured the prompt is returned unchanged,
     * so sites that have not opted into filter metadata see zero behaviour change.
     *
     * @param string $prompt The resolved, enriched system prompt.
     * @return string The prompt with filter-intent instructions appended (when applicable).
     *
     * @since 1.1.0
     * @stability experimental
     */
    private function appendFilterFieldsInstruction(string $prompt): string
    {
        if (empty($this->filterFields)) {
            return $prompt;
        }

        // Build filter field list with descriptions when available.
        $fieldLines = [];
        foreach ($this->filterFields as $field) {
            $desc = $this->filterFieldDescriptions[$field] ?? '';
            $fieldLines[] = $desc !== '' ? "- {$field}: {$desc}" : "- {$field}";
        }
        $fieldList = implode("\n", $fieldLines);

        $prompt .= <<<'ENDFILTERINSTR'


FILTER INTENT:
Available filter dimensions:
{FILTER_LIST}

When the user's query implies restricting results to a specific category, type, or dimension — AND that dimension matches one of the available filter fields above — add a "filters" key to your response.

Format: {"terms": [...], "filters": {"<dimension>": "<value>"}}

Rules:
- dimension MUST be one of the available filter dimensions listed above — no other values are permitted.
- value should be the most specific match from the field description. Use title case for category names (e.g., "Science" not "science", "Medieval" not "medieval") unless the field description indicates otherwise.
- Multiple filters can be active simultaneously: {"filters": {"topic": "Science", "era": "Ancient"}} is valid when the query implies both constraints.
- Filter intent is detected when the user names or strongly implies a specific category value. "Science articles about water" → topic: "Science". "Ancient Roman engineering" → era: "Ancient". "European history" → region: "Europe".
- Add a filter when the user names a subject that clearly maps to one of the categories, even via subcategory terms listed in parentheses. "physics articles" → topics: "Science" because the description shows Science includes physics. "music history" → topics: "Arts" because the description shows Arts includes music. Only omit the filter when the query is genuinely cross-domain or doesn't map to any single category.
- Filters and sort can coexist: "newest Science articles" → both a topic filter AND a date sort.
- When a filter is detected, KEEP the filtered category's name in the expanded terms too. Filters narrow the result set; the expanded terms still need to match within that narrowed set.
- Do NOT strip filter words from terms the way you strip sort words. Filter words like "Science" or "Medieval" are often meaningful search terms within the filtered set.

If no filter intent is detected, omit the "filters" key entirely.
ENDFILTERINSTR;

        $prompt = str_replace('{FILTER_LIST}', $fieldList, $prompt);

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
