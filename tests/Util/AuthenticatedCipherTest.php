<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Util;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Exception\CryptoException;
use Tag1\Scolta\Util\AuthenticatedCipher;

/**
 * Tests for the AuthenticatedCipher utility.
 */
class AuthenticatedCipherTest extends TestCase
{
    private const KEY_MATERIAL = 'a-test-key-material-string-that-is-well-over-32-bytes-long';

    private function cipher(): AuthenticatedCipher
    {
        return new AuthenticatedCipher(self::KEY_MATERIAL);
    }

    // -----------------------------------------------------------------
    // Round-trips
    // -----------------------------------------------------------------

    public function testRoundTrip(): void
    {
        $cipher = $this->cipher();
        $this->assertSame('secret-token', $cipher->decrypt($cipher->encrypt('secret-token')));
    }

    public function testRoundTripEmptyString(): void
    {
        $cipher = $this->cipher();
        $this->assertSame('', $cipher->decrypt($cipher->encrypt('')));
    }

    public function testRoundTripMultiKilobytePlaintext(): void
    {
        $cipher = $this->cipher();
        $plaintext = str_repeat('credential payload with spice: äöü 🔑 ', 200);
        $this->assertGreaterThan(4096, strlen($plaintext));
        $this->assertSame($plaintext, $cipher->decrypt($cipher->encrypt($plaintext)));
    }

    public function testRoundTripBinaryPlaintext(): void
    {
        $cipher = $this->cipher();
        $plaintext = "\x00\x01\xff\xfe binary \x00 bytes";
        $this->assertSame($plaintext, $cipher->decrypt($cipher->encrypt($plaintext)));
    }

    public function testRoundTripAcrossInstancesWithSameKeyMaterial(): void
    {
        $envelope = $this->cipher()->encrypt('shared-secret');
        $this->assertSame('shared-secret', $this->cipher()->decrypt($envelope));
    }

    // -----------------------------------------------------------------
    // Envelope shape
    // -----------------------------------------------------------------

    public function testEnvelopeHasVersionedPrefix(): void
    {
        $envelope = $this->cipher()->encrypt('secret');
        $this->assertStringStartsWith('scolta-enc:v1:', $envelope);
    }

    public function testSamePlaintextProducesDifferentEnvelopes(): void
    {
        $cipher = $this->cipher();
        $this->assertNotSame($cipher->encrypt('secret'), $cipher->encrypt('secret'));
    }

    // -----------------------------------------------------------------
    // Tampering — every region of the envelope is authenticated
    // -----------------------------------------------------------------

    public function testDecryptRejectsNonEnvelopePrefix(): void
    {
        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt('not-an-envelope');
    }

    public function testDecryptRejectsLegacyRawBase64Blob(): void
    {
        // A legacy unauthenticated CBC blob: raw base64, no envelope prefix.
        $legacy = base64_encode(random_bytes(48));

        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($legacy);
    }

    public function testDecryptRejectsAlteredVersion(): void
    {
        $envelope = $this->cipher()->encrypt('secret');
        $tampered = str_replace('scolta-enc:v1:', 'scolta-enc:v2:', $envelope);

        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($tampered);
    }

    public function testDecryptRejectsMissingVersionSeparator(): void
    {
        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt('scolta-enc:v1-no-separator');
    }

    public function testDecryptRejectsInvalidBase64Payload(): void
    {
        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt('scolta-enc:v1:!!!not-base64!!!');
    }

    public function testDecryptRejectsTamperedIv(): void
    {
        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($this->withFlippedPayloadByte($this->cipher()->encrypt('secret'), 0));
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($this->withFlippedPayloadByte($this->cipher()->encrypt('secret'), 16));
    }

    public function testDecryptRejectsTamperedMac(): void
    {
        $envelope = $this->cipher()->encrypt('secret');
        $payloadLength = strlen($this->payloadOf($envelope));

        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($this->withFlippedPayloadByte($envelope, $payloadLength - 1));
    }

    public function testDecryptRejectsTruncatedPayload(): void
    {
        $envelope = $this->cipher()->encrypt('secret');
        $payload = $this->payloadOf($envelope);
        $truncated = 'scolta-enc:v1:' . base64_encode(substr($payload, 0, 20));

        $this->expectException(CryptoException::class);
        $this->cipher()->decrypt($truncated);
    }

    public function testDecryptRejectsWrongKeyMaterial(): void
    {
        $envelope = $this->cipher()->encrypt('secret');
        $otherCipher = new AuthenticatedCipher('a-completely-different-key-material-over-32-bytes');

        $this->expectException(CryptoException::class);
        $otherCipher->decrypt($envelope);
    }

    // -----------------------------------------------------------------
    // Key material validation
    // -----------------------------------------------------------------

    public function testRejectsShortKeyMaterial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthenticatedCipher(str_repeat('k', 31));
    }

    public function testAcceptsExactly32ByteKeyMaterial(): void
    {
        $cipher = new AuthenticatedCipher(str_repeat('k', 32));
        $this->assertSame('ok', $cipher->decrypt($cipher->encrypt('ok')));
    }

    // -----------------------------------------------------------------
    // isEnvelope — the legacy-migration branch point
    // -----------------------------------------------------------------

    public function testIsEnvelopeTrueForFreshEnvelope(): void
    {
        $this->assertTrue(AuthenticatedCipher::isEnvelope($this->cipher()->encrypt('secret')));
    }

    public function testIsEnvelopeFalseForLegacyRawBase64Blob(): void
    {
        $this->assertFalse(AuthenticatedCipher::isEnvelope(base64_encode(random_bytes(48))));
    }

    public function testIsEnvelopeFalseForArbitraryString(): void
    {
        $this->assertFalse(AuthenticatedCipher::isEnvelope('hello world'));
    }

    public function testIsEnvelopeFalseForEmptyString(): void
    {
        $this->assertFalse(AuthenticatedCipher::isEnvelope(''));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Extract the raw (decoded) base64 payload of an envelope.
     */
    private function payloadOf(string $envelope): string
    {
        $base64 = substr($envelope, strlen('scolta-enc:v1:'));
        $payload = base64_decode($base64, true);
        $this->assertNotFalse($payload);

        return $payload;
    }

    /**
     * Re-encode an envelope with one payload byte XOR-flipped.
     */
    private function withFlippedPayloadByte(string $envelope, int $offset): string
    {
        $payload = $this->payloadOf($envelope);
        $payload[$offset] = chr(ord($payload[$offset]) ^ 0x01);

        return 'scolta-enc:v1:' . base64_encode($payload);
    }
}
