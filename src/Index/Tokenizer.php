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

    /**
     * Set by tokenize() and read by normalize() and splitCompound().
     * When true, the current text is pure ASCII: byte offset == char offset,
     * strtolower suffices for lowercasing, and neither ICU nor CJK handling
     * is needed — both are skipped entirely.
     */
    private bool $textIsAscii = false;

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

        // Detect pure-ASCII text once per call. When true:
        //   - byte offset == char offset (skip all mb_strlen on gaps/words)
        //   - strtolower() suffices (skip mb_strtolower)
        //   - no diacritics possible (skip ICU transliterator in normalize())
        //   - no CJK characters possible (skip CJK preg_match in splitCompound())
        // Detection cost ~1-2 µs is amortized over N tokens that each save ~0.45 µs
        // of ICU work — >100:1 savings ratio for any page with more than ~5 tokens.
        $this->textIsAscii = strlen($text) === mb_strlen($text);

        // Split on word boundaries BEFORE lowercasing (preserves camelCase info).
        // Include internal apostrophes for contractions (don't, it's, we've).
        $pattern = "/[\p{L}\p{N}\p{Emoji_Presentation}]+(?:'[\p{L}]+)*/u";
        if (preg_match_all($pattern, $originalText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        // Track byte and char positions incrementally to avoid O(n²) behaviour.
        // preg_match_all returns byte offsets; for multibyte text the byte offset
        // and char offset diverge. For ASCII text they are identical so we skip
        // the mb_strlen(substr()) calls entirely.
        $prevByteEnd = 0;
        $charPos     = 0;

        foreach ($matches[0] as [$word, $byteOffset]) {
            // Advance char position by the gap between end of last word and
            // start of this word (handles inter-word whitespace/punctuation).
            $gapBytes = $byteOffset - $prevByteEnd;
            if ($gapBytes > 0) {
                $charPos += $this->textIsAscii
                    ? $gapBytes
                    : mb_strlen(substr($originalText, $prevByteEnd, $gapBytes));
            }
            $charOffset  = $charPos;
            $prevByteEnd = $byteOffset + strlen($word);
            $position    = $startPosition + $charOffset;

            // Handle compound words (before lowercasing to detect camelCase).
            $parts = $this->splitCompound($word);

            foreach ($parts as $partOffset => $part) {
                if ($part === '') {
                    continue;
                }

                // Lowercase after splitting.
                $lower = $this->textIsAscii ? strtolower($part) : mb_strtolower($part);

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

            // Advance char position past this word so the next gap is measured
            // from the correct byte boundary.
            $charPos += $this->textIsAscii ? strlen($word) : mb_strlen($word);
        }

        return $tokens;
    }

    /**
     * Normalize text by removing diacritics.
     *
     * Pure-ASCII text (flagged by tokenize()) is returned immediately — there
     * are no diacritics to strip, and the ICU transliterator call is ~0.45 µs
     * even when it would be a no-op.
     *
     * Uses ICU Transliterator when ext-intl is available, falls back
     * to a strtr() mapping table for common Latin diacritics.
     */
    private function normalize(string $text): string
    {
        // ASCII fast-path: no diacritics possible, skip transliterator entirely.
        if ($this->textIsAscii) {
            return $text;
        }

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
        // CJK/Hiragana/Katakana/Hangul characters only occur in multibyte text.
        // Skip the per-word preg_match when the calling text is pure ASCII.
        if (!$this->textIsAscii && preg_match('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $word)) {
            return $this->tokenizeMixedCjk($word);
        }

        // Hyphen splitting: "mother-in-law" → ["mother", "in", "law", "motherinlaw"].
        // Pagefind indexes both the parts AND the joined compound.
        if (str_contains($word, '-')) {
            $parts  = [];
            $offset = 0;
            foreach (explode('-', $word) as $segment) {
                $segLen = $this->textIsAscii ? strlen($segment) : mb_strlen($segment);
                if ($segLen >= 2) {
                    $parts[$offset] = $segment;
                }
                $offset += $segLen + 1;
            }

            // Also include the joined compound (without hyphens).
            $compound    = str_replace('-', '', $word);
            $compoundLen = $this->textIsAscii ? strlen($compound) : mb_strlen($compound);
            $wordLen     = $this->textIsAscii ? strlen($word) : mb_strlen($word);
            if ($compoundLen >= 3 && count($parts) > 1) {
                $parts[$wordLen + 1] = $compound;
            }

            return $parts ?: [0 => $word];
        }

        // camelCase splitting: "myPageTitle" → ["my", "page", "title"].
        if (preg_match('/[a-z][A-Z]/', $word)) {
            $parts    = [];
            $segments = preg_split('/(?<=[a-z])(?=[A-Z])/u', $word);
            if ($segments !== false && count($segments) > 1) {
                $offset = 0;
                foreach ($segments as $segment) {
                    $lower  = $this->textIsAscii ? strtolower($segment) : mb_strtolower($segment);
                    $segLen = $this->textIsAscii ? strlen($lower) : mb_strlen($lower);
                    if ($segLen >= 2) {
                        $parts[$offset] = $lower;
                    }
                    $offset += $this->textIsAscii ? strlen($segment) : mb_strlen($segment);
                }

                return $parts ?: [0 => $word];
            }
        }

        return [0 => $word];
    }

    /**
     * Tokenize a word containing CJK/Hiragana/Katakana/Hangul using bigram strategy.
     *
     * Non-CJK runs are emitted as a single token. CJK runs of length ≥ 2 emit
     * overlapping bigrams; a single CJK character is emitted as-is.
     *
     * @return array<int, string> Offset => token.
     */
    private function tokenizeMixedCjk(string $word): array
    {
        $cjkPattern = '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}]/u';

        $chars = mb_str_split($word);
        $parts = [];

        $runStart = 0;
        $runChars = [];
        $runIsCjk = null;

        $flushRun = function (int $startOffset, array $runChars, bool $isCjk) use (&$parts): void {
            $count = count($runChars);
            if ($count === 0) {
                return;
            }
            if (!$isCjk) {
                $parts[$startOffset] = implode('', $runChars);
            } elseif ($count === 1) {
                $parts[$startOffset] = $runChars[0];
            } else {
                for ($i = 0; $i < $count - 1; ++$i) {
                    $parts[$startOffset + $i] = $runChars[$i] . $runChars[$i + 1];
                }
            }
        };

        foreach ($chars as $i => $char) {
            $isCjk = preg_match($cjkPattern, $char) === 1;

            if ($runIsCjk === null) {
                $runIsCjk = $isCjk;
                $runStart = $i;
            }

            if ($isCjk !== $runIsCjk) {
                $flushRun($runStart, $runChars, $runIsCjk);
                $runStart = $i;
                $runChars = [];
                $runIsCjk = $isCjk;
            }

            $runChars[] = $char;
        }

        $flushRun($runStart, $runChars, $runIsCjk ?? false);

        return $parts;
    }
}
