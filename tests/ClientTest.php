<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Signer;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Capturing PSR-18 stub: records the last request it was asked to send and
 * returns a preset response. No network.
 */
final class MockHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public int $callCount = 0;

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->callCount++;

        return $this->response;
    }
}

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    /**
     * The published TEST secret from the shared test-vectors file
     * (openapi/test-vectors/hmac-signing.json): bytes 0..31 base64url'd.
     * Never real key material.
     */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private const APP_ID = 'app_erp';

    private const KEY_ID = 'key_test_primary';

    private MockHttpClient $http;

    private Client $client;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient(new Response(200, [], '{"ok":true}'));
        $factory = new HttpFactory();
        $this->client = new Client(
            $this->http,
            $factory,
            $factory,
            // Trailing slash on purpose: the client must rtrim it.
            'https://ir.example.com/',
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
        );
    }

    /** The captured request, or fail loudly if nothing was sent. */
    private function capturedRequest(): RequestInterface
    {
        $request = $this->http->lastRequest;
        if ($request === null) {
            self::fail('no request was captured by the mock http client');
        }

        return $request;
    }

    /**
     * The sign-what-you-send proof: recompute the signature over the CAPTURED
     * request — its method, its URI's path+query, its actual body bytes, and
     * its X-IR-Timestamp/X-IR-Nonce header values — and assert it equals the
     * X-IR-Signature header the client set.
     */
    private function assertSignatureVerifies(RequestInterface $request): void
    {
        $uri = $request->getUri();
        $query = $uri->getQuery();
        $pathWithQuery = $uri->getPath() . ($query !== '' ? '?' . $query : '');

        $recomputed = Signer::signRequest(
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
            $request->getMethod(),
            $pathWithQuery,
            (string) $request->getBody(),
            $request->getHeaderLine('X-IR-Timestamp'),
            $request->getHeaderLine('X-IR-Nonce'),
        );

        self::assertSame(
            $recomputed->headers['X-IR-Signature'],
            $request->getHeaderLine('X-IR-Signature'),
            'signature over the captured request does not match X-IR-Signature',
        );
    }

    public function testPostWithArrayBodySignsTheExactJsonSent(): void
    {
        $body = [
            'operation' => 'quickbooks.customer.upsert',
            'tenant_id' => 't_123',
            'input' => ['name' => 'Acme', 'balance' => 12.5],
        ];

        $response = $this->client->send('POST', '/v1/operations/execute', $body);

        self::assertSame(200, $response->getStatusCode());
        $request = $this->capturedRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://ir.example.com/v1/operations/execute', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            json_encode($body, JSON_THROW_ON_ERROR),
            (string) $request->getBody(),
            'wire body must be the single json_encode of the array',
        );

        foreach (['X-IR-App-Id', 'X-IR-Key-Id', 'X-IR-Timestamp', 'X-IR-Nonce', 'X-IR-Signature'] as $header) {
            self::assertTrue($request->hasHeader($header), "missing {$header}");
        }
        self::assertSame(self::APP_ID, $request->getHeaderLine('X-IR-App-Id'));
        self::assertSame(self::KEY_ID, $request->getHeaderLine('X-IR-Key-Id'));
        self::assertMatchesRegularExpression('/^\d+$/', $request->getHeaderLine('X-IR-Timestamp'));
        $nonceLen = strlen($request->getHeaderLine('X-IR-Nonce'));
        self::assertGreaterThanOrEqual(Signer::NONCE_MIN_LEN, $nonceLen);
        self::assertLessThanOrEqual(Signer::NONCE_MAX_LEN, $nonceLen);

        $this->assertSignatureVerifies($request);
    }

    public function testGetWithQueryStringSignsEmptyBodyAndKeepsQuery(): void
    {
        $this->client->send('GET', '/v1/sync-jobs?status=running&limit=10');

        $request = $this->capturedRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(0, $request->getBody()->getSize(), 'GET must carry no body on the wire');
        self::assertSame('', (string) $request->getBody());
        self::assertSame('/v1/sync-jobs', $request->getUri()->getPath());
        self::assertSame('status=running&limit=10', $request->getUri()->getQuery());

        // assertSignatureVerifies rebuilds pathWithQuery as path + '?' + query,
        // so a passing signature proves the query string was signed too.
        $this->assertSignatureVerifies($request);
    }

    public function testGetGivenABodyIgnoresIt(): void
    {
        $this->client->send('GET', '/v1/sync-jobs', ['should' => 'be ignored']);

        $request = $this->capturedRequest();
        self::assertSame(0, $request->getBody()->getSize(), 'body must be dropped for GET');
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'), 'no default Content-Type without a body');

        // Verifies over the EMPTY body — the runtime hashes '' for GET.
        $this->assertSignatureVerifies($request);
    }

    public function testPreEncodedStringBodyIsSentVerbatim(): void
    {
        // Forward slashes + unicode: json_encode would turn this into
        // {"url":"https:\/\/a\/b","memo":"café"} — the client must NOT
        // re-encode a caller-serialized string.
        $raw = '{"url":"https://a/b","memo":"café"}';

        $this->client->send('POST', '/v1/operations/execute', $raw);

        $request = $this->capturedRequest();
        self::assertSame($raw, (string) $request->getBody(), 'string body must be byte-for-byte verbatim');
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSignatureVerifies($request);
    }

    public function testCallerHeadersArePreservedButXIrHeadersAlwaysWin(): void
    {
        $this->client->send('POST', '/v1/operations/execute', ['a' => 1], [
            'X-Request-Id' => 'req_abc123',
            'X-IR-Signature' => 'forged-signature',
            'X-IR-App-Id' => 'spoofed-app',
        ]);

        $request = $this->capturedRequest();
        self::assertSame('req_abc123', $request->getHeaderLine('X-Request-Id'), 'extra caller header preserved');
        self::assertSame(self::APP_ID, $request->getHeaderLine('X-IR-App-Id'), 'client overwrites spoofed X-IR-App-Id');
        self::assertNotSame('forged-signature', $request->getHeaderLine('X-IR-Signature'));
        self::assertCount(1, $request->getHeader('X-IR-Signature'), 'exactly one signature value');
        self::assertCount(1, $request->getHeader('X-IR-App-Id'), 'exactly one app-id value');

        // The one signature on the wire is the client's real one.
        $this->assertSignatureVerifies($request);
    }

    public function testPathWithoutLeadingSlashThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path must start with "/"');

        $this->client->createSignedRequest('GET', 'v1/sync-jobs');
    }

    /**
     * The runtime verifier derives its signed string via WHATWG
     * `new URL(url).pathname + search`, which percent-encodes characters
     * PSR-7 implementations leave raw (notably "'" in a query). The client
     * must sign — and put on the wire — the WHATWG serialization, or these
     * requests 401 undiagnosably.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function whatwgDerivationCases(): iterable
    {
        yield 'apostrophe in query' => [
            "/v1/customers?name=O'Brien",
            '/v1/customers?name=O%27Brien',
        ];
        yield 'space in query' => [
            '/v1/sync-jobs?q=two words',
            '/v1/sync-jobs?q=two%20words',
        ];
        yield 'raw unicode in query' => [
            '/v1/sync-jobs?memo=café',
            '/v1/sync-jobs?memo=caf%C3%A9',
        ];
        yield 'braces in path' => [
            '/v1/items/{id}',
            '/v1/items/%7Bid%7D',
        ];
        yield 'caret in path' => [
            '/v1/items/a^b',
            '/v1/items/a%5Eb',
        ];
        // `^` is a PATH-set-only char: the client's QUERY_ENCODE_SET leaves
        // it alone. Guzzle's own URI filter pre-encodes it to %5E before our
        // normalization runs — consistent either way (pre-encoded sequences
        // pass through the verifier untouched). The raw-`^`-in-query
        // parser truth is pinned by the workerd `new URL()` fixture check.
        yield 'caret in QUERY is factory-encoded, passes through consistently' => [
            '/v1/items?range=1^9',
            '/v1/items?range=1%5E9',
        ];
        yield 'pre-encoded sequences pass through untouched' => [
            '/v1/sync-jobs?memo=caf%C3%A9&q=%27x%27',
            '/v1/sync-jobs?memo=caf%C3%A9&q=%27x%27',
        ];
        yield "apostrophe in PATH stays raw (WHATWG doesn't encode it there)" => [
            "/v1/o'brien",
            "/v1/o'brien",
        ];
    }

    #[DataProvider('whatwgDerivationCases')]
    public function testSignedPathWithQueryMatchesWhatwgDerivation(string $inputPath, string $expected): void
    {
        $request = $this->client->createSignedRequest('GET', $inputPath);

        // The wire URI carries the WHATWG serialization…
        $uri = $request->getUri();
        $query = $uri->getQuery();
        self::assertSame($expected, $uri->getPath() . ($query !== '' ? '?' . $query : ''));

        // …and the signature was computed over exactly that string: recompute
        // over $expected (NOT over the request URI, which would be circular).
        $recomputed = Signer::signRequest(
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
            'GET',
            $expected,
            '',
            $request->getHeaderLine('X-IR-Timestamp'),
            $request->getHeaderLine('X-IR-Nonce'),
        );
        self::assertSame(
            $recomputed->headers['X-IR-Signature'],
            $request->getHeaderLine('X-IR-Signature'),
            'signature must be over the WHATWG-normalized pathWithQuery',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rewrittenPathShapes(): iterable
    {
        yield 'dot-dot segment' => ['/v1/../echo'];
        yield 'single-dot segment' => ['/v1/./echo'];
        yield 'percent-encoded dot-dot' => ['/v1/%2e%2e/echo'];
        yield 'mixed-case percent-encoded dot' => ['/v1/%2E/echo'];
        yield 'backslash' => ['/v1\\echo'];
    }

    #[DataProvider('rewrittenPathShapes')]
    public function testPathShapesTheVerifierWouldRewriteAreRejected(string $path): void
    {
        // WHATWG REWRITES these (collapses dot-segments, "\" → "/") rather
        // than percent-encoding them — no signed string can round-trip, so
        // the client fails loudly instead of producing a mystery 401.
        $this->expectException(\InvalidArgumentException::class);

        $this->client->createSignedRequest('GET', $path);
    }

    public function testBackslashInQueryIsAllowedAndSignedConsistently(): void
    {
        // WHATWG only rewrites "\" → "/" in the PATH; in a query it
        // survives raw end-to-end, so ?filter=C:\logs is legitimate. The
        // rejection is scoped to backslashes before the first "?".
        $request = $this->client->createSignedRequest('GET', '/v1/sync-jobs?filter=C:\\logs');

        // Whatever encoding the PSR-7 factory applied (Guzzle: "\" → %5C,
        // ":" left raw) is what was signed — sign-what-you-send holds.
        self::assertStringContainsString('filter=C:%5Clogs', $request->getUri()->getQuery());
        $this->assertSignatureVerifies($request);
    }

    public function testDeleteWithQuerySendsNoBodyAndSignsEmpty(): void
    {
        $this->client->send('DELETE', '/v1/connections/conn_1?reason=cleanup', ['should' => 'be ignored']);

        $request = $this->capturedRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(0, $request->getBody()->getSize(), 'DELETE must carry no body on the wire');
        self::assertFalse($request->hasHeader('Content-Type'), 'no default Content-Type without a body');
        self::assertSame('reason=cleanup', $request->getUri()->getQuery());

        // Verifies over the EMPTY body — the runtime hashes '' for DELETE.
        $this->assertSignatureVerifies($request);
    }

    public function testHeadSendsNoBodyAndSignsEmpty(): void
    {
        $this->client->send('HEAD', '/v1/health', 'ignored-body');

        $request = $this->capturedRequest();
        self::assertSame('HEAD', $request->getMethod());
        self::assertSame(0, $request->getBody()->getSize(), 'HEAD must carry no body on the wire');
        self::assertFalse($request->hasHeader('Content-Type'), 'no default Content-Type without a body');

        $this->assertSignatureVerifies($request);
    }

    public function testCreateSignedRequestDoesNotInvokeTheHttpClient(): void
    {
        $request = $this->client->createSignedRequest('POST', '/v1/operations/execute', ['a' => 1]);

        self::assertSame(0, $this->http->callCount, 'createSignedRequest must not send');
        self::assertNull($this->http->lastRequest);

        // The returned request is fully signed and self-consistent.
        $this->assertSignatureVerifies($request);
    }

    public function testJsonSerializableBodyIsEncodedOnceAndSigned(): void
    {
        $body = new class implements \JsonSerializable {
            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return ['kind' => 'jsonable', 'n' => 7];
            }
        };

        $this->client->send('POST', '/v1/operations/execute', $body);

        $request = $this->capturedRequest();
        self::assertSame('{"kind":"jsonable","n":7}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSignatureVerifies($request);
    }

    public function testCallerSuppliedContentTypeWinsOverDefault(): void
    {
        $this->client->send('POST', '/v1/operations/execute', '{"a":1}', [
            'Content-Type' => 'application/vnd.api+json',
        ]);

        $request = $this->capturedRequest();
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Content-Type'));
        self::assertCount(1, $request->getHeader('Content-Type'));
        $this->assertSignatureVerifies($request);
    }

    public function testSecretIsMaskedInDebugOutputAndSerializationThrows(): void
    {
        $debug = $this->client->__debugInfo();
        self::assertSame('***', $debug['secret']);

        ob_start();
        var_dump($this->client);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $dumped, 'var_dump must not expose the secret');

        $printed = print_r($this->client, true);
        self::assertStringNotContainsString(self::SECRET, $printed, 'print_r must not expose the secret');

        $this->expectException(\LogicException::class);
        serialize($this->client);
    }
}
