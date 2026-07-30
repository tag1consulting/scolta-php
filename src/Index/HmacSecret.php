<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * The one place that decides whether a chunk HMAC secret counts as configured.
 *
 * Chunk integrity tagging is optional: with no secret, ChunkWriter still
 * computes CRC32 and the chunk is still a valid, readable chunk. "No secret"
 * used to be spelled `null` and only `null`, which put every caller that
 * sources the secret from framework configuration one step from a crash —
 * `config('app.key')` on an app without a generated key is `''`, not `null`,
 * and `hash_init('sha256', HASH_HMAC, '')` throws
 * `ValueError: Argument #3 ($key) must not be empty when HMAC is requested`.
 *
 * So an empty string means unset, and a whitespace-only string means unset
 * too. Whitespace is never a deliberate key: accepting `"   "` would produce
 * a tag that only a caller who reproduces the same accidental whitespace
 * could verify, which is worse than no tag at all because it looks like
 * integrity coverage and is not.
 *
 * Every consumer of a chunk secret normalises through here before testing it
 * or handing it to `hash_init()`, so the writer's notion of "tagged" and the
 * reader's notion of "expects a tag" cannot drift apart. Normalisation is
 * idempotent, so passing an already-normalised value through again is safe.
 *
 * @since 1.1.0
 * @stability experimental
 */
final class HmacSecret
{
    /**
     * Reduce a configured secret to `null` when it carries no key material.
     *
     * @param string|null $secret Raw secret as supplied by the caller.
     * @return string|null The secret unchanged, or null if empty or whitespace-only.
     *
     * @since 1.1.0
     * @stability experimental
     */
    public static function normalize(?string $secret): ?string
    {
        if ($secret === null || trim($secret) === '') {
            return null;
        }

        return $secret;
    }
}
