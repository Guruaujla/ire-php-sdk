<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\InvalidNonceException;
use Ezee\IntegrationRuntime\Exception\InvalidSecretException;
use Ezee\IntegrationRuntime\SignedRequest;
use Ezee\IntegrationRuntime\Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Signer primitives: secret decoding, hashing, base64url,
 * canonical-payload construction, nonce/timestamp generation, request signing
 * and its validation/propagation behavior, plus a hardening pin on the
 * #[\SensitiveParameter] attributes.
 */
#[CoversClass(Signer::class)]
final class SignerTest extends TestCase
{
    /**
     * Same TEST-ONLY secret as openapi/test-vectors/hmac-signing.json —
     * base64url-no-pad of bytes 0x00..0x1f. Never a real service-key secret.
     */
    private const TEST_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private const EMPTY_BODY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const SHA256_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    // ---------------------------------------------------------------- decodeSecret

    #[DataProvider('invalidSecrets')]
    public function testDecodeSecretRejectsInvalidInput(string $secret): void
    {
        $this->expectException(InvalidSecretException::class);
        Signer::decodeSecret($secret);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'empty string' => [''];
        yield 'padding only (trims to empty)' => ['===='];
        yield 'length 1 mod 4' => ['A'];
        yield 'padded length 1 mod 4' => ['A==='];
        yield 'standard-base64 plus' => ['ab+cd'];
        yield 'standard-base64 slash' => ['ab/cd'];
        yield 'embedded space' => ['ab cd'];
        yield 'embedded newline' => ["ab\ncd"];
        yield 'embedded equals' => ['ab=cd'];
    }

    public function testDecodeSecretRoundTripsAllLengths(): void
    {
        for ($n = 1; $n <= 48; $n++) {
            $bytes = random_bytes($n);
            self::assertSame(
                $bytes,
                Signer::decodeSecret(Signer::base64UrlEncode($bytes)),
                "decodeSecret(base64UrlEncode(bytes)) round-trip failed for length {$n}",
            );
        }
    }

    public function testDecodeSecretToleratesTrailingPadding(): void
    {
        $expected = implode('', array_map(chr(...), range(0, 31)));
        self::assertSame($expected, Signer::decodeSecret(self::TEST_SECRET));

        $padded = self::TEST_SECRET . str_repeat('=', (4 - strlen(self::TEST_SECRET) % 4) % 4);
        self::assertSame($expected, Signer::decodeSecret($padded));
    }

    public function testDecodeSecretDecodesSingleNullByte(): void
    {
        self::assertSame("\x00", Signer::decodeSecret('AA'));
    }

    // ---------------------------------------------------------------- sha256Hex

    public function testSha256HexOfEmptyStringIsTheWellKnownDigest(): void
    {
        self::assertSame(self::EMPTY_BODY_SHA256, Signer::sha256Hex(''));
    }

    public function testSha256HexIsLowercaseHex(): void
    {
        $digest = Signer::sha256Hex('abc');
        self::assertSame(self::SHA256_ABC, $digest);
        self::assertSame(strtolower($digest), $digest, 'digest must already be lowercase');
    }

    // ---------------------------------------------------------------- base64UrlEncode

    public function testBase64UrlEncodeUsesUrlSafeAlphabetOnly(): void
    {
        self::assertSame('', Signer::base64UrlEncode(''));

        for ($i = 0; $i < 16; $i++) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z0-9_-]*$/',
                Signer::base64UrlEncode(random_bytes(33)),
            );
        }
    }

    public function testBase64UrlEncodeMapsPlusSlashAndStripsPadding(): void
    {
        // Standard base64 of these bytes is '+/+/' — every char needs the
        // url-safe remapping. Pin the standard encoding first so the expected
        // value below is demonstrably exercising the '+' -> '-', '/' -> '_' map.
        self::assertSame('+/+/', base64_encode("\xfb\xff\xbf"));
        self::assertSame('-_-_', Signer::base64UrlEncode("\xfb\xff\xbf"));

        // Standard base64 '////' -> all underscores.
        self::assertSame('____', Signer::base64UrlEncode("\xff\xff\xff"));

        // Standard base64 'AA==' -> padding stripped.
        self::assertSame('AA', Signer::base64UrlEncode("\x00"));
    }

    // ---------------------------------------------------------------- computeCanonicalPayload

    public function testComputeCanonicalPayloadBuildsExactString(): void
    {
        $payload = Signer::computeCanonicalPayload(
            'post',
            '/v1/sync-jobs?status=running&limit=10',
            '1700000000',
            'nonce0000000000000000000000',
            '',
        );

        $expected = "v1\nPOST\n/v1/sync-jobs?status=running&limit=10\n1700000000\nnonce0000000000000000000000\n"
            . self::EMPTY_BODY_SHA256;
        self::assertSame($expected, $payload);
        self::assertStringEndsNotWith("\n", $payload, 'canonical payload must have NO trailing newline');
    }

    public function testComputeCanonicalPayloadUpperCasesMethod(): void
    {
        $nonce = str_repeat('n', 22);
        $lower = Signer::computeCanonicalPayload('post', '/v1/x', '1700000000', $nonce, 'body');
        $upper = Signer::computeCanonicalPayload('POST', '/v1/x', '1700000000', $nonce, 'body');

        self::assertSame($upper, $lower);
        self::assertStringContainsString("\nPOST\n", $lower);
    }

    // ---------------------------------------------------------------- generateNonce

    public function testGenerateNonceShapeAndUniqueness(): void
    {
        $first = Signer::generateNonce();
        $second = Signer::generateNonce();

        self::assertSame(32, strlen($first));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $first);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $second);
        self::assertNotSame($first, $second, 'two generated nonces must differ');

        self::assertGreaterThanOrEqual(Signer::NONCE_MIN_LEN, strlen($first));
        self::assertLessThanOrEqual(Signer::NONCE_MAX_LEN, strlen($first));
    }

    // ---------------------------------------------------------------- currentTimestamp

    public function testCurrentTimestampIsUnixSecondsNow(): void
    {
        $before = time();
        $timestamp = Signer::currentTimestamp();
        $after = time();

        self::assertTrue(ctype_digit($timestamp), 'timestamp must be all ASCII digits');
        self::assertGreaterThanOrEqual($before, (int) $timestamp);
        self::assertLessThanOrEqual($after, (int) $timestamp);
        self::assertLessThanOrEqual(5, abs(time() - (int) $timestamp), 'timestamp must be within 5s of now');
    }

    // ---------------------------------------------------------------- signRequest

    #[DataProvider('outOfBoundsNonceLengths')]
    public function testSignRequestRejectsOutOfBoundsNonce(int $length): void
    {
        $this->expectException(InvalidNonceException::class);
        Signer::signRequest(
            'app_erp',
            'key_primary',
            self::TEST_SECRET,
            'GET',
            '/v1/sync-jobs',
            nonce: str_repeat('a', $length),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfBoundsNonceLengths(): iterable
    {
        yield 'one below minimum (21)' => [21];
        yield 'one above maximum (65)' => [65];
    }

    public function testSignRequestAcceptsBoundaryNonceLengths(): void
    {
        foreach ([Signer::NONCE_MIN_LEN, Signer::NONCE_MAX_LEN] as $length) {
            $nonce = str_repeat('a', $length);
            $result = Signer::signRequest(
                'app_erp',
                'key_primary',
                self::TEST_SECRET,
                'GET',
                '/v1/sync-jobs',
                nonce: $nonce,
            );
            self::assertSame($nonce, $result->nonce);
            self::assertSame($nonce, $result->headers['X-IR-Nonce']);
        }
    }

    public function testSignRequestAutoFillsTimestampAndNonce(): void
    {
        $before = time();
        $result = Signer::signRequest('app_erp', 'key_primary', self::TEST_SECRET, 'GET', '/v1/sync-jobs');
        $after = time();

        self::assertSame(32, strlen($result->nonce));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $result->nonce);

        self::assertTrue(ctype_digit($result->timestamp));
        self::assertGreaterThanOrEqual($before, (int) $result->timestamp);
        self::assertLessThanOrEqual($after, (int) $result->timestamp);

        self::assertSame($result->timestamp, $result->headers['X-IR-Timestamp']);
        self::assertSame($result->nonce, $result->headers['X-IR-Nonce']);
    }

    public function testSignRequestIsDeterministicForFixedInputs(): void
    {
        $sign = static fn(): SignedRequest => Signer::signRequest(
            'app_erp',
            'key_primary',
            self::TEST_SECRET,
            'POST',
            '/v1/operations/execute',
            '{"operation":"quickbooks.customer.upsert","tenant_id":"t_123"}',
            '1700000000',
            '0123456789abcdef01234567',
        );

        $first = $sign();
        $second = $sign();

        self::assertSame($first->headers, $second->headers);
        self::assertSame($first->canonicalPayload, $second->canonicalPayload);
    }

    public function testSignRequestSignatureChangesWhenOneBodyByteChanges(): void
    {
        $signatureOf = static fn(string $body): string => Signer::signRequest(
            'app_erp',
            'key_primary',
            self::TEST_SECRET,
            'POST',
            '/v1/operations/execute',
            $body,
            '1700000000',
            '0123456789abcdef01234567',
        )->headers['X-IR-Signature'];

        self::assertNotSame($signatureOf('{"amount":100}'), $signatureOf('{"amount":101}'));
    }

    public function testSignRequestPropagatesInvalidSecret(): void
    {
        $this->expectException(InvalidSecretException::class);
        Signer::signRequest('app_erp', 'key_primary', 'not+a/valid secret!', 'GET', '/v1/sync-jobs');
    }

    public function testSignRequestHeadersContainExactlyTheFiveHeaders(): void
    {
        $result = Signer::signRequest('app_erp', 'key_primary', self::TEST_SECRET, 'GET', '/v1/sync-jobs');

        self::assertSame(
            ['X-IR-App-Id', 'X-IR-Key-Id', 'X-IR-Timestamp', 'X-IR-Nonce', 'X-IR-Signature'],
            array_keys($result->headers),
        );
        self::assertSame('app_erp', $result->headers['X-IR-App-Id']);
        self::assertSame('key_primary', $result->headers['X-IR-Key-Id']);
    }

    // ---------------------------------------------------------------- hardening pins

    public function testSecretParametersCarrySensitiveParameterAttribute(): void
    {
        foreach (['signRequest', 'decodeSecret'] as $methodName) {
            $secretParameter = null;
            foreach ((new \ReflectionMethod(Signer::class, $methodName))->getParameters() as $parameter) {
                if ($parameter->getName() === 'secret') {
                    $secretParameter = $parameter;
                    break;
                }
            }

            if ($secretParameter === null) {
                self::fail(sprintf('Signer::%s() has no $secret parameter', $methodName));
            }

            self::assertCount(
                1,
                $secretParameter->getAttributes(\SensitiveParameter::class),
                sprintf('Signer::%s() $secret must carry #[\SensitiveParameter]', $methodName),
            );
        }
    }
}
