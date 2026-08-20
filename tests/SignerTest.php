<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Exception\InvalidMethodException;
use Ezee\IntegrationRuntime\Exception\InvalidNonceException;
use Ezee\IntegrationRuntime\Exception\InvalidSecretException;
use Ezee\IntegrationRuntime\SignedRequest;
use Ezee\IntegrationRuntime\Signer;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    /**
     * The empty/whitespace-only branch reports "must not be empty", NOT a
     * charset error: an operator staring at an invisible value is not helped
     * by being told to hunt for an unexpected character. Byte-identical
     * message and branch in all three SDKs (issue #138).
     */
    #[DataProvider('blankSecrets')]
    public function testDecodeSecretReportsBlankSecretsAsEmpty(string $secret): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('service-key secret must not be empty');
        Signer::decodeSecret($secret);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankSecrets(): iterable
    {
        yield 'empty string' => [''];
        yield 'single padding char' => ['='];
        yield 'padding only' => ['===='];
        yield 'spaces only' => ['   '];
        yield 'tab only' => ["\t"];
        yield 'newline only' => ["\n"];
        yield 'every ASCII whitespace' => [" \t\n\v\f\r"];
    }

    /**
     * THE cross-SDK regression this guard exists for (found in review of the
     * #138/#139 sweep).
     *
     * This method previously anchored its charset check with
     * `/^[A-Za-z0-9_-]+$/`, and PCRE's `$` ALSO matches immediately before a
     * final newline. So a trailing-newline secret passed the check here while
     * the TypeScript SDK (JS `$` anchors only at end of input) and the Python
     * SDK (`\A…\Z`) rejected it — and it did not stop at a different error
     * message: 43 + 1 = 44 is ≡0 (mod 4) and `base64_decode` skips whitespace,
     * so PHP went on to derive the CORRECT key from a malformed value. One
     * config value, two behaviours across the SDKs — exactly what #138 exists
     * to remove. The anchor is now `\A…\z` (PCRE's `\Z` is the LENIENT one;
     * Python's `\Z` is the strict one — do not translate the spellings by eye).
     *
     * Note that `nonBase64UrlSecrets` already carried "ab\ncd" — an EMBEDDED
     * newline, which the `$`-vs-`\z` anchor treats identically. That is
     * precisely why the bug survived review: only a LEADING or TRAILING one
     * discriminates, so those are asserted here.
     *
     * What this test pins is the WHITESPACE GUARD, not the anchor. The guard
     * runs first and shadows the charset check for every input here, so
     * mutating the anchor alone leaves this green — measured, not assumed.
     * Removing the guard turns these into the charset message (10 failures);
     * removing the guard AND loosening the anchor makes decodeSecret ACCEPT
     * the trailing-newline secret and derive the correct 32-byte key, which is
     * the originally reported defect.
     *
     * The message is deliberately not the charset one: the character is
     * INVISIBLE, and sending an operator hunting through a base64 blob for
     * something they cannot see is not help.
     */
    #[DataProvider('surroundingWhitespaceSecrets')]
    public function testDecodeSecretRejectsSurroundingWhitespace(string $secret): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage(
            'service-key secret must not have leading or trailing whitespace '
            . '(usually a trailing newline from a file-mounted secret)',
        );
        Signer::decodeSecret($secret);
    }

    /**
     * Hostile characters are built with chr()/escape sequences, never typed as
     * literals — an invisible character that a file write or an editor
     * normalises leaves a test that passes while asserting nothing.
     *
     * @return iterable<string, array{string}>
     */
    public static function surroundingWhitespaceSecrets(): iterable
    {
        // The exact reported shape: the published 43-char console secret as a
        // file-mounted value, carrying the newline that `$(cat secret.txt)`, a
        // `.env` line, or a Kubernetes/Docker secret file leaves on it.
        yield 'real console secret + trailing newline' => [self::TEST_SECRET . chr(0x0A)];
        yield 'trailing newline, length 3 mod 4' => ['AAA' . chr(0x0A)];
        yield 'trailing newline, length 0 mod 4' => ['AAAA' . chr(0x0A)];
        yield 'leading newline' => [chr(0x0A) . 'AAAA'];
        yield 'leading space' => [chr(0x20) . 'AAAA'];
        yield 'trailing space' => ['AAAA' . chr(0x20)];
        yield 'trailing tab' => ['AAAA' . chr(0x09)];
        yield 'trailing CR' => ['AAAA' . chr(0x0D)];
        yield 'trailing vertical tab' => ['AAAA' . chr(0x0B)];
        yield 'trailing form feed' => ['AAAA' . chr(0x0C)];
    }

    /**
     * Negative control: the guard must reject SURROUNDING whitespace and
     * nothing else, or every correctly configured caller starts failing at
     * credential-loading time instead of signing.
     */
    public function testDecodeSecretWhitespaceGuardIsNotOverBroad(): void
    {
        self::assertSame(32, strlen(Signer::decodeSecret(self::TEST_SECRET)));
        self::assertSame(
            Signer::decodeSecret('AAAA'),
            Signer::decodeSecret('AAAA='),
            'padding is still tolerated — the guard must not have swallowed it',
        );
    }

    #[DataProvider('nonBase64UrlSecrets')]
    public function testDecodeSecretReportsUnexpectedCharacters(string $secret): void
    {
        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('service-key secret is not valid base64url (unexpected characters)');
        Signer::decodeSecret($secret);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonBase64UrlSecrets(): iterable
    {
        yield 'standard-base64 plus' => ['ab+cd'];
        yield 'standard-base64 slash' => ['ab/cd'];
        yield 'embedded space' => ['ab cd'];
        yield 'embedded newline' => ["ab\ncd"];
        yield 'embedded equals' => ['ab=cd'];
        yield 'punctuation' => ['abc$def'];
    }

    public function testLengthValidStandardBase64SecretIsRejectedByTheCharsetGuard(): void
    {
        // The charset guard must be reachable independently of the length
        // guard: every other invalid-charset case above is ALSO length-invalid.
        // A standard-base64 (not base64url) secret of a legal length would
        // otherwise decode SILENTLY to different key bytes than the base64url
        // reading — every request 401ing with a "wrong key" smell.
        self::assertSame(0, strlen('++//') % 4, 'must be length-valid so only the charset can reject it');

        $this->expectException(InvalidSecretException::class);
        $this->expectExceptionMessage('service-key secret is not valid base64url (unexpected characters)');
        Signer::decodeSecret('++//');
    }

    public function testDecodeSecretReportsInvalidLengthSeparately(): void
    {
        // No base64 payload can be ≡1 (mod 4) chars; the message says so
        // rather than blaming the charset.
        foreach (['A', 'AAAAA', 'A==='] as $secret) {
            try {
                Signer::decodeSecret($secret);
                self::fail("decodeSecret('{$secret}') should have thrown");
            } catch (InvalidSecretException $e) {
                self::assertSame(
                    'service-key secret is not valid base64url (invalid length)',
                    $e->getMessage(),
                );
            }
        }
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

    // ---------------------------------------------------------------- canonicalizeMethod

    /**
     * Methods whose UNICODE upper-case folds onto an allowlist member but
     * whose ASCII upper-case does not. These are the exact inputs behind
     * issue #139: before the allowlist, "optıons" was ACCEPTED by the
     * TypeScript and Python SDKs (both upper-case U+0131 to "I") and produced
     * "OPTıONS" here — one call, two canonical payloads, an undiagnosable 401.
     *
     * @return list<string>
     */
    private static function unicodeLookalikeMethods(): array
    {
        return [
            "opt\u{0131}ons",  // U+0131 LATIN SMALL LETTER DOTLESS I -> "I"
            "po\u{017F}t",     // U+017F LATIN SMALL LETTER LONG S -> "S"
        ];
    }

    public function testCanonicalizeMethodReturnsTheAllowlistConstantForEveryAsciiSpelling(): void
    {
        foreach (Signer::HTTP_METHODS as $method) {
            self::assertSame($method, Signer::canonicalizeMethod($method));
            self::assertSame($method, Signer::canonicalizeMethod(strtolower($method)));
            self::assertSame(
                $method,
                Signer::canonicalizeMethod($method[0] . strtolower(substr($method, 1))),
            );
        }
    }

    public function testHttpMethodsIsExactlyTheNineStandardMethods(): void
    {
        self::assertSame(
            ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'],
            Signer::HTTP_METHODS,
        );
    }

    public function testEveryAllowlistedMethodIsPureUpperCaseAscii(): void
    {
        // This is what makes the runtime's Unicode `String.prototype
        // .toUpperCase` (src/security/hmac.ts) and this SDK's ASCII
        // `strtoupper` provably agree: over pure upper-case ASCII, both case
        // mappings are the identity. Adding a method that violates this would
        // silently re-open the divergence #139 closed.
        foreach (Signer::HTTP_METHODS as $method) {
            self::assertMatchesRegularExpression('/^[A-Z]+$/', $method);
        }
    }

    #[DataProvider('unsupportedMethods')]
    public function testCanonicalizeMethodRejectsUnsupportedMethods(string $method): void
    {
        $this->expectException(InvalidMethodException::class);
        Signer::canonicalizeMethod($method);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedMethods(): iterable
    {
        yield 'unknown verb' => ['BREW'];
        yield 'WebDAV verb outside RFC 9110' => ['PROPFIND'];
        yield 'empty' => [''];
        yield 'leading space' => [' GET'];
        yield 'trailing space' => ['GET '];
        yield 'header injection attempt' => ["GET\r\nX-Evil: 1"];

        foreach (self::unicodeLookalikeMethods() as $method) {
            yield 'unicode lookalike ' . bin2hex($method) => [$method];
        }
    }

    public function testCanonicalizeMethodMessageNamesTheInputAndTheAllowedSet(): void
    {
        // Byte-identical wording across the three SDKs — asserted here, in
        // signing.test.ts, and in test_signer.py.
        $this->expectException(InvalidMethodException::class);
        $this->expectExceptionMessage(
            'method "brew" is not a supported HTTP method '
            . '(expected one of: CONNECT, DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT, TRACE)',
        );
        Signer::canonicalizeMethod('brew');
    }

    public function testComputeCanonicalPayloadRejectsAnUnsupportedMethod(): void
    {
        $this->expectException(InvalidMethodException::class);
        Signer::computeCanonicalPayload("po\u{017F}t", '/v1/x', '1700000000', str_repeat('n', 22), '');
    }

    public function testClientRejectsAnUnsupportedMethodBeforeBuildingAnyRequest(): void
    {
        // Asserted through the CLIENT entry point, not just the signer: the
        // client used to `strtoupper` the method itself, so a canonicalizer
        // wired into Signer alone would leave the client free to disagree with
        // it — the exact re-entry point for #139. (This assertion lives here
        // rather than in ClientTest because it pins Signer's contract; the
        // PSR-18 stub below throws if anything tries to send.)
        $factory = new HttpFactory();
        $client = new Client(
            new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('no request may be sent for a rejected method');
                }
            },
            $factory,
            $factory,
            'https://ir.example.com',
            'app_erp',
            'key_primary',
            self::TEST_SECRET,
        );

        foreach ([...self::unicodeLookalikeMethods(), 'BREW'] as $method) {
            try {
                $client->createSignedRequest($method, '/v1/health');
                self::fail("createSignedRequest('{$method}') should have thrown");
            } catch (InvalidMethodException $e) {
                self::assertStringContainsString('is not a supported HTTP method', $e->getMessage());
            }
        }
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

    // ---------------------------------------------------------------- header-value guards (#150, #151)

    /**
     * A 32-char base64url nonce — the exact shape generateNonce() produces, so
     * the only thing wrong with the hostile variants below is the added
     * character.
     */
    private const CLEAN_NONCE = 'abcdefghijklmnopqrstuvwxyz012345';

    private const WHITESPACE_MESSAGE = 'nonce must not have leading or trailing whitespace (usually a trailing '
        . 'newline from a file- or env-sourced value) — HTTP header encoding STRIPS '
        . 'it after the signature is computed, so the runtime would verify a '
        . 'different string than was signed';

    private const CHARSET_MESSAGE = 'nonce must consist of visible ASCII characters (no spaces, control '
        . 'characters, or non-ASCII) so it survives HTTP header encoding intact';

    /**
     * @param array{nonce?: string, timestamp?: string, appId?: string, keyId?: string} $overrides
     */
    private static function signWith(array $overrides): SignedRequest
    {
        return Signer::signRequest(
            $overrides['appId'] ?? 'app_erp',
            $overrides['keyId'] ?? 'key_primary',
            self::TEST_SECRET,
            'GET',
            '/v1/health',
            '',
            $overrides['timestamp'] ?? null,
            $overrides['nonce'] ?? self::CLEAN_NONCE,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strippedWhitespaceNonces(): iterable
    {
        // Hostile characters are ESCAPE SEQUENCES, never literals: during the
        // original investigation a literal NUL silently became a space during a
        // file write, leaving a test that passed while asserting nothing.
        // POSITION is an explicit dimension — two independent probes both
        // generalised from single-position samples and both got the rule wrong.
        $chars = ['SP' => "\x20", 'HT' => "\x09", 'LF' => "\x0a", 'CR' => "\x0d", 'CRLF' => "\x0d\x0a"];
        foreach ($chars as $name => $char) {
            yield "leading {$name}" => [$char . self::CLEAN_NONCE];
            yield "trailing {$name}" => [self::CLEAN_NONCE . $char];
            yield "both ends {$name}" => [$char . self::CLEAN_NONCE . $char];
        }
    }

    #[DataProvider('strippedWhitespaceNonces')]
    public function testSignRequestRejectsSurroundingHttpWhitespaceInTheNonce(string $nonce): void
    {
        // In bounds by length, so ONLY the whitespace guard can reject it.
        self::assertGreaterThanOrEqual(Signer::NONCE_MIN_LEN, strlen($nonce));
        self::assertLessThanOrEqual(Signer::NONCE_MAX_LEN, strlen($nonce));

        $this->expectException(InvalidNonceException::class);
        $this->expectExceptionMessage(self::WHITESPACE_MESSAGE);
        self::signWith(['nonce' => $nonce]);
    }

    public function testVerticalTabAndFormFeedGetTheCharsetMessageNotTheWhitespaceOne(): void
    {
        // This is the assertion that says WHICH predicate ran. VT and FF are in
        // the SDK's six-character ASCII_WHITESPACE set but are NOT HTTP
        // whitespace: measured, a leading or trailing VT/FF round-trips through
        // a Headers API VERBATIM. Reusing ASCII_WHITESPACE here would still
        // reject them — with a message claiming they get stripped, which is
        // false. Both messages reject, so only this discrimination catches it.
        foreach (["\x0b", "\x0c"] as $char) {
            foreach ([self::CLEAN_NONCE . $char, $char . self::CLEAN_NONCE] as $nonce) {
                try {
                    self::signWith(['nonce' => $nonce]);
                    self::fail('expected InvalidNonceException');
                } catch (InvalidNonceException $e) {
                    self::assertSame(self::CHARSET_MESSAGE, $e->getMessage());
                }
            }
        }
    }

    public function testWhitespaceGuardIsEdgesOnly(): void
    {
        // Negative control: a guard that rejected the character ANYWHERE would
        // pass every case above while breaking working callers.
        $result = self::signWith(['nonce' => 'abcdefghij-klmnopqrstuvwxyz']);
        self::assertSame('abcdefghij-klmnopqrstuvwxyz', $result->nonce);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonVisibleAsciiNonces(): iterable
    {
        yield 'embedded space' => ['abcdefghijkl mnopqrstuvwxyz'];
        yield 'embedded NUL' => ["abcdefghijkl\x00mnopqrstuvwxyz"];
        yield 'leading NUL' => ["\x00" . self::CLEAN_NONCE];
        yield 'BEL' => [self::CLEAN_NONCE . "\x07"];
        yield 'DEL' => [self::CLEAN_NONCE . "\x7f"];
        yield 'latin-1 U+00E9' => [self::CLEAN_NONCE . "\u{00e9}"];
        yield 'astral U+1F600' => [self::CLEAN_NONCE . "\u{1f600}"];
        yield 'embedded CR' => ["abcdefghijkl\x0dmnopqrstuvwxyz"];
        yield 'embedded LF' => ["abcdefghijkl\x0amnopqrstuvwxyz"];
    }

    #[DataProvider('nonVisibleAsciiNonces')]
    public function testSignRequestRejectsNonVisibleAsciiNonces(string $nonce): void
    {
        $this->expectException(InvalidNonceException::class);
        $this->expectExceptionMessage(self::CHARSET_MESSAGE);
        self::signWith(['nonce' => $nonce]);
    }

    public function testNonceBoundIsMeasuredInUtf16CodeUnitsNotBytes(): void
    {
        // Issue #151. Eleven U+4E2D characters are 33 BYTES but only 11 UTF-16
        // code units, and the runtime measures `nonce.length` in JS — UTF-16
        // units. `strlen` counted bytes, so this SDK SIGNED a nonce that
        // TypeScript, Python AND the runtime all reject.
        $nonce = str_repeat("\u{4e2d}", 11);
        self::assertSame(33, strlen($nonce), 'precondition: 33 bytes, which strlen would accept');

        try {
            self::signWith(['nonce' => $nonce]);
            self::fail('expected InvalidNonceException');
        } catch (InvalidNonceException $e) {
            self::assertSame(
                "nonce length 11 is outside the runtime's [22, 64] bound",
                $e->getMessage(),
            );
        }
    }

    public function testAstralCharactersCountAsTwoUtf16Units(): void
    {
        // Twelve U+1F600 are 48 bytes, 12 code points, and 24 UTF-16 units. In
        // UTF-16 that CLEARS the length gate and dies on CHARSET instead — that
        // difference in WHICH error is what pins the unit. A byte count (48) or
        // a code-point count (12) would produce the length message.
        $nonce = str_repeat("\u{1f600}", 12);
        self::assertSame(48, strlen($nonce), 'precondition: 48 bytes');

        try {
            self::signWith(['nonce' => $nonce]);
            self::fail('expected InvalidNonceException');
        } catch (InvalidNonceException $e) {
            self::assertSame(self::CHARSET_MESSAGE, $e->getMessage());
        }
    }

    public function testCheckOrderIsPinnedNotJustTheSetOfChecks(): void
    {
        // Every input below fails MORE THAN ONE guard, so asserting only "it
        // throws" could not tell which branch ran. The message is the only
        // thing that can.
        $cases = [
            // whitespace beats length: 64 chars + a newline is 65 units.
            [str_repeat('a', Signer::NONCE_MAX_LEN) . "\x0a", self::WHITESPACE_MESSAGE],
            // whitespace beats charset.
            ["abcdefghij\x00klmnopqrstuvwxyz\x20", self::WHITESPACE_MESSAGE],
            // length beats charset.
            [str_repeat("\u{4e2d}", 3), "nonce length 3 is outside the runtime's [22, 64] bound"],
        ];
        foreach ($cases as [$nonce, $expected]) {
            try {
                self::signWith(['nonce' => $nonce]);
                self::fail('expected InvalidNonceException');
            } catch (InvalidNonceException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testNonceGuardBeatsTimestampGuard(): void
    {
        $this->expectException(InvalidNonceException::class);
        self::signWith(['nonce' => self::CLEAN_NONCE . "\x0a", 'timestamp' => 'nope']);
    }

    public function testTimestampGuardBeatsAppIdGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timestamp must be 1-15 digits');
        self::signWith(['timestamp' => 'nope', 'appId' => "app\x0a"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalTimestamps(): iterable
    {
        yield 'not digits' => ['17000000oo'];
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'fractional' => ['1.5'];
        yield 'leading space' => ["\x201700000000"];
        yield 'trailing newline' => ["1700000000\x0a"];
        yield '16 digits' => [str_repeat('1', 16)];
        // Python's `\d` over a str matches every Unicode decimal digit unless
        // re.ASCII is set; the verifier's JS `\d` is [0-9]. All three SDKs must
        // reject this.
        yield 'arabic-indic digits' => ["\u{0661}\u{0667}" . str_repeat("\u{0660}", 8)];
    }

    #[DataProvider('nonCanonicalTimestamps')]
    public function testSignRequestRejectsNonCanonicalTimestamps(string $timestamp): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timestamp must be 1-15 digits (unix seconds)');
        self::signWith(['timestamp' => $timestamp]);
    }

    public function testSignRequestAcceptsOneToFifteenAsciiDigits(): void
    {
        self::assertSame('1', self::signWith(['timestamp' => '1'])->timestamp);
        self::assertSame(str_repeat('1', 15), self::signWith(['timestamp' => str_repeat('1', 15)])->timestamp);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerUnsafeIds(): iterable
    {
        $values = [
            'leading space' => "\x20app_erp",
            'trailing newline' => "app_erp\x0a",
            'trailing tab' => "app_erp\x09",
            'embedded CRLF' => "app_erp\x0d\x0aX-Evil: 1",
            'non-ASCII' => "app_erp\u{00e9}",
        ];
        foreach ($values as $name => $value) {
            yield "app_id {$name}" => ['appId', $value];
            yield "key_id {$name}" => ['keyId', $value];
        }
    }

    #[DataProvider('headerUnsafeIds')]
    public function testSignRequestRejectsHeaderUnsafeIds(string $field, string $value): void
    {
        // These are NOT part of the canonical payload, so a rewritten value
        // cannot break the signature — it changes which KEY the runtime looks
        // up, which is the same undiagnosable 401 by another route.
        $label = $field === 'appId' ? 'app_id' : 'key_id';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($label . ' must consist of visible ASCII characters');
        self::signWith([$field => $value]);
    }

    public function testSignRequestAcceptsTheIdsTheConsoleMints(): void
    {
        $result = self::signWith(['appId' => 'app_erp', 'keyId' => 'key_primary']);
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
