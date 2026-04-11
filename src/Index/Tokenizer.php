<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * Tokenize text for search indexing.
 *
 * Replicates Pagefind's tokenization from pagefind/src/fossick/splitting.rs:
 * - Unicode-aware lowercasing
 * - Diacritic normalization (NFD strip marks NFC)
 * - Word boundary splitting
 * - Compound word handling (hyphens, camelCase)
 * - CJK character splitting
 */
class Tokenizer
{
    private ?\Transliterator $transliterator = null;

    /** Common diacritic mappings for pure PHP fallback when ext-intl is missing. */
    private const DIACRITIC_MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ÿ' => 'y', 'ý' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'ð' => 'd', 'þ' => 'th',
    ];

    public function __construct()
    {
        if (extension_loaded('intl')) {
            $this->transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        }
    }

    /**
     * Tokenize text into an array of token records.
     *
     * @param string $text Input text (plain text, not HTML).
     * @param int $startPosition Character position offset for position tracking.
     * @return array<int, array{stem: string, original: string, position: int}>
     */
    public function tokenize(string $text, int $startPosition = 0): array
    {
        if (trim($text) === '') {
            return [];
        }

        $tokens = [];
        $originalText = $text;

        // Split on word boundaries BEFORE lowercasing (preserves camelCase info).
        $pattern = '/[\p{L}\p{N}\p{Emoji_Presentation}]+/u';
        if (preg_match_all($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($matches[0] as [$word, $byteOffset]) {
            // Convert byte offset to character offset.
            $charOffset = mb_strlen(substr($originalText, 0, $byteOffset));
            $position = $startPosition + $charOffset;

            // Handle compound words (before lowercasing to detect camelCase).
            $parts = $this->splitCompound($word);

            foreach ($parts as $partOffset => $part) {
                if (mb_strlen($part) < 1) {
                    continue;
                }

                // Lowercase after splitting.
                $lower = mb_strtolower($part);

                // Normalize diacritics for the stem form.
                $normalized = $this->normalize($lower);

                if ($normalized === '') {
                    continue;
                }

                $tokens[] = [
                    'stem' => $normalized,
                    'original' => $lower,
                    'position' => $position + $partOffset,
                ];
            }
        }

        return $tokens;
    }

    /**
     * Normalize text by removing diacritics.
     *
     * Uses ICU Transliterator when ext-intl is available, falls back
     * to a strtr() mapping table for common Latin diacritics.
     */
    private function normalize(string $text): string
    {
        if ($this->transliterator !== null) {
            $result = $this->transliterator->transliterate($text);

            return $result !== false ? $result : $text;
        }

        // Pure PHP fallback: strtr for common diacritics.
        return strtr($text, self::DIACRITIC_MAP);
    }

    /**
     * Split compound words (hyphens, camelCase) into parts.
     *
     * @return array<int, string> Offset => part.
     */
    private function splitCompound(string $word): array
    {
        // Check for CJK characters — split each as individual token.
        if (preg_match('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}]/u', $word)) {
            $parts = [];
            $offset = 0;
            $chars = mb_str_split($word);
            foreach ($chars as $char) {
                $parts[$offset] = $char;
                $offset += mb_strlen($char);
            }

            return $parts;
        }

        // Hyphen splitting: "mother-in-law" → ["mother", "in", "law"].
        if (str_contains($word, '-')) {
            $parts = [];
            $offset = 0;
            foreach (explode('-', $word) as $segment) {
                if (mb_strlen($segment) >= 2) {
                    $parts[$offset] = $segment;
                }
                $offset += mb_strlen($segment) + 1; // +1 for the hyphen.
            }

            return $parts ?: [0 => $word];
        }

        // camelCase splitting: "myPageTitle" → ["my", "page", "title"].
        if (preg_match('/[a-z][A-Z]/', $word)) {
            $parts = [];
            $segments = preg_split('/(?<=[a-z])(?=[A-Z])/u', $word);
            if ($segments !== false && count($segments) > 1) {
                $offset = 0;
                foreach ($segments as $segment) {
                    $lower = mb_strtolower($segment);
                    if (mb_strlen($lower) >= 2) {
                        $parts[$offset] = $lower;
                    }
                    $offset += mb_strlen($segment);
                }

                return $parts ?: [0 => $word];
            }
        }

        return [0 => $word];
    }
}
