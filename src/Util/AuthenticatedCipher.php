<?php

declare(strict_types=1);

namespace Tag1\Scolta\Util;

use Tag1\Scolta\Exception\CryptoException;

/**
 * Authenticated encryption (encrypt-then-MAC) for adapter credential storage.
 *
 * AES-256-CBC with an HMAC-SHA256 authentication tag over the version,
 * IV, and ciphertext. Two independent keys are derived from the caller's
 * key material via HKDF, so the encryption and MAC keys are never the
 * same bytes. The envelope format is:
 *
 *     scolta-enc:v1:base64(iv || ciphertext || mac)
 *
 * The version identifier participates in the MAC input, so it cannot be
 * stripped or swapped without invalidating the tag.
 *
 * Key sourcing is deliberately the caller's problem: platform adapters
 * supply their CMS-native secret (WordPress salts, Drupal hash_salt,
 * Laravel APP_KEY) and this class does nothing platform-aware. Likewise,
 * migration of legacy unauthenticated blobs stays adapter-side —
 * {@see isEnvelope()} is the branch point between the two formats.
 *
 * @since 1.0.4
 * @stability experimental
 */
final class AuthenticatedCipher
{
    private const PREFIX = 'scolta-enc:';
    private const VERSION = 'v1';
    private const CIPHER_METHOD = 'aes-256-cbc';
    private const IV_LENGTH = 16;
    private const CIPHER_BLOCK_LENGTH = 16;
    private const MAC_LENGTH = 32;

    private readonly string $encryptionKey;

    private readonly string $macKey;

    /**
     * Derive independent encryption and MAC keys from caller key material.
     *
     * @param string $keyMaterial High-entropy secret from the platform
     *   (e.g. WP salts, Drupal hash_salt, Laravel APP_KEY). At least
     *   32 bytes; used only as HKDF input, never directly as a key.
     *
     * @throws \InvalidArgumentException If the key material is shorter than 32 bytes.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function __construct(#[\SensitiveParameter] string $keyMaterial)
    {
        if (strlen($keyMaterial) < 32) {
            throw new \InvalidArgumentException('Key material must be at least 32 bytes.');
        }

        $this->encryptionKey = hash_hkdf('sha256', $keyMaterial, 32, 'scolta-enc-v1');
        $this->macKey = hash_hkdf('sha256', $keyMaterial, 32, 'scolta-mac-v1');
    }

    /**
     * Encrypt a plaintext into an authenticated envelope.
     *
     * @param string $plaintext The secret to protect. May be empty.
     * @return string Envelope in the form `scolta-enc:v1:` + base64(iv || ciphertext || mac).
     *
     * @throws CryptoException If the underlying cipher fails.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER_METHOD, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new CryptoException('Encryption failed.');
        }

        $mac = hash_hmac('sha256', self::VERSION . $iv . $ciphertext, $this->macKey, true);

        return self::PREFIX . self::VERSION . ':' . base64_encode($iv . $ciphertext . $mac);
    }

    /**
     * Decrypt an authenticated envelope produced by {@see encrypt()}.
     *
     * The MAC is verified (constant-time) before any decryption is
     * attempted. Every failure mode throws; this method never degrades
     * to a null/false return, so a corrupted or tampered credential
     * cannot silently read as "not configured".
     *
     * @param string $envelope An envelope string from {@see encrypt()}.
     * @return string The original plaintext.
     *
     * @throws CryptoException If the envelope is malformed, has an
     *   unsupported version, fails authentication, or fails to decrypt.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public function decrypt(string $envelope): string
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            throw new CryptoException('Value is not a Scolta encryption envelope.');
        }

        $rest = substr($envelope, strlen(self::PREFIX));
        $separator = strpos($rest, ':');
        if ($separator === false) {
            throw new CryptoException('Malformed envelope: missing version separator.');
        }

        $version = substr($rest, 0, $separator);
        if ($version !== self::VERSION) {
            throw new CryptoException(sprintf('Unsupported envelope version "%s".', $version));
        }

        $payload = base64_decode(substr($rest, $separator + 1), true);
        if ($payload === false) {
            throw new CryptoException('Malformed envelope: payload is not valid base64.');
        }
        if (strlen($payload) < self::IV_LENGTH + self::CIPHER_BLOCK_LENGTH + self::MAC_LENGTH) {
            throw new CryptoException('Malformed envelope: payload is truncated.');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH, -self::MAC_LENGTH);
        $mac = substr($payload, -self::MAC_LENGTH);

        $expectedMac = hash_hmac('sha256', $version . $iv . $ciphertext, $this->macKey, true);
        if (!hash_equals($expectedMac, $mac)) {
            throw new CryptoException('Envelope authentication failed.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER_METHOD, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new CryptoException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Whether a stored value is a Scolta encryption envelope.
     *
     * Cheap prefix check, no key material needed. Adapters use this to
     * branch between the authenticated format and legacy plain-cipher
     * blobs awaiting migration. A true result does not imply the
     * envelope is valid — only {@see decrypt()} authenticates it.
     *
     * @param string $value A stored value of unknown format.
     *
     * @since 1.0.4
     * @stability experimental
     */
    public static function isEnvelope(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
