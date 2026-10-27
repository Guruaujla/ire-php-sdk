<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Exception\InvalidMethodException;
use Ezee\IntegrationRuntime\RetryPolicy;
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

/**
 * PSR-18 stub that answers a SCRIPTED sequence of responses and records EVERY
 * request. The retry tests need all of them — the acceptance property is about
 * how attempt 2 differs from attempt 1, not just that a retry happened.
 */
final class ScriptedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface> $script one response per attempt, in order */
    public function __construct(private readonly array $script) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $index = count($this->requests) - 1;
        if (! isset($this->script[$index])) {
            throw new \LogicException(sprintf(
                'sendRequest called %d time(s) but the script has %d response(s)',
                $index + 1,
                count($this->script),
            ));
        }

        return $this->script[$index];
    }
}

/**
 * A 429 whose `Retry-After` is handed back RAW, bypassing PSR-7 validation.
 *
 * Guzzle validates header values in the Response constructor and rejects any
 * string containing CR, LF or NUL — so the characters the three SDKs have to
 * agree about (\n, \r, \v, \f, NUL) mostly cannot be expressed through a normal
 * Response at all. That validation is a defence, not the contract under test:
 * without this stub the trim would be barely exercised here, and a test named
 * for the trim would in fact be asserting Guzzle's behaviour. Overriding
 * getHeaderLine() is enough because Client reads the header only through it.
 */
final class RawRetryAfterResponse extends Response
{
    public function __construct(private readonly string $rawRetryAfter)
    {
        parent::__construct(429, [], '{}');
    }

    // Signature intentionally untyped on $header: Guzzle's Response declares it
    // without a parameter type, and PHP requires an override to be compatible.
    public function getHeaderLine($header): string
    {
        return strtolower((string) $header) === 'retry-after'
            ? $this->rawRetryAfter
            : parent::getHeaderLine($header);
    }
}

/** Testing seam for Client's $sleep: records the seconds instead of sleeping. */
final class SleepRecorder
{
    /** @var list<float> */
    public array $seconds = [];

    public function __invoke(float $seconds): void
    {
        $this->seconds[] = $seconds;
    }
}

#[CoversClass(Client::class)]
#[CoversClass(RetryPolicy::class)]
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

    // ── Retry on 429 (issue #134) ──────────────────────────────────────────
    //
    // The acceptance property is NOT "it retried" — it is "every attempt is a
    // COMPLETELY FRESH signed request". A Layer 2 quota 429 lands after
    // serviceAuth has already burned the request's nonce, so a resend of the
    // identical bytes answers 401 hmac.nonce_reused. See the class doc block
    // in src/Client.php.

    /** A 429 shaped like the runtime's, optionally carrying Retry-After. */
    private static function tooManyRequests(?string $retryAfter = null): Response
    {
        $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];

        return new Response(429, $headers, '{"type":"rate_limit.quota_exceeded","status":429}');
    }

    /**
     * A client wired to a scripted transport with both testing seams injected,
     * so no test ever actually sleeps.
     *
     * @param float $randomRoll the fixed jitter roll; the real seam returns [0, 1)
     */
    private function retryingClient(
        ScriptedHttpClient $http,
        SleepRecorder $sleeps,
        float $randomRoll = 0.0,
        ?RetryPolicy $retry = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $http,
            $factory,
            $factory,
            'https://ir.example.com',
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
            $retry,
            $sleeps(...),
            static fn(): float => $randomRoll,
        );
    }

    public function testWithoutRetryReturnsTheFirst429WithoutSleeping(): void
    {
        $http = new ScriptedHttpClient([self::tooManyRequests('30'), new Response(200, [], '{"ok":true}')]);
        $sleeps = new SleepRecorder();
        $client = $this->retryingClient($http, $sleeps)->withoutRetry();

        $response = $client->send('GET', '/v1/health');

        self::assertSame(429, $response->getStatusCode());
        self::assertCount(1, $http->requests);
        self::assertSame([], $sleeps->seconds);
    }

    public function testEverySignedAttemptGetsAFreshNonceOverByteIdenticalBody(): void
    {
        // A body whose serialization is caller code that is NOT pure: it
        // returns a different document on every jsonSerialize() call. If body
        // normalization ran inside the retry loop, attempts 2 and 3 would sign
        // and send a different document from attempt 1. This is PHP's stand-in
        // for mutating a caller-supplied buffer (strings here are immutable).
        $body = new class implements \JsonSerializable {
            public int $calls = 0;

            /** @return array<string, int> */
            public function jsonSerialize(): array
            {
                $this->calls++;

                return ['n' => $this->calls];
            }
        };

        $http = new ScriptedHttpClient([
            self::tooManyRequests('1'),
            self::tooManyRequests('1'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $sleeps = new SleepRecorder();
        $client = $this->retryingClient($http, $sleeps);

        $response = $client->send('POST', '/v1/operations/execute', $body);

        // 1. exactly three requests were sent, and the last response is returned.
        self::assertCount(3, $http->requests);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $sleeps->seconds);

        // 2. the three nonces are ALL DISTINCT — the load-bearing assertion. A
        //    replay of the buffered signed request would repeat one nonce here
        //    and earn a 401 hmac.nonce_reused from the real runtime.
        $nonces = array_map(
            static fn(RequestInterface $r): string => $r->getHeaderLine('X-IR-Nonce'),
            $http->requests,
        );
        self::assertCount(3, array_unique($nonces), 'every attempt must carry a FRESH nonce');
        foreach ($nonces as $nonce) {
            self::assertGreaterThanOrEqual(Signer::NONCE_MIN_LEN, strlen($nonce));
        }

        // 3. the three signatures are all distinct, and each verifies over ITS
        //    OWN timestamp + nonce (assertSignatureVerifies). That recomputation
        //    is what proves re-SIGNING rather than a copied header block: a
        //    signature carried over from attempt 1 cannot match a recomputation
        //    against attempt 2's nonce. Distinct TIMESTAMPS are not assertable
        //    — the client deliberately has no clock seam and three attempts land
        //    inside one unix second — so this is the proof that each timestamp
        //    was re-derived and re-signed rather than reused.
        $signatures = array_map(
            static fn(RequestInterface $r): string => $r->getHeaderLine('X-IR-Signature'),
            $http->requests,
        );
        self::assertCount(3, array_unique($signatures), 'every attempt must be re-SIGNED');

        foreach ($http->requests as $index => $request) {
            // 4. byte-identical bodies across all three attempts.
            self::assertSame(
                '{"n":1}',
                (string) $request->getBody(),
                "attempt {$index} sent different body bytes",
            );
            self::assertSame('https://ir.example.com/v1/operations/execute', (string) $request->getUri());
            self::assertMatchesRegularExpression('/^\d+$/', $request->getHeaderLine('X-IR-Timestamp'));
            $this->assertSignatureVerifies($request);
        }

        // The body really was serialized exactly ONCE, before the loop.
        self::assertSame(1, $body->calls, 'the body must be normalized once, not per attempt');
    }

    public function testA500IsNotRetried(): void
    {
        // Only a 429 is guaranteed side-effect-free (it is refused before any
        // handler runs). A 5xx may mean the write landed, so it is returned.
        $http = new ScriptedHttpClient([new Response(500, [], '{}'), new Response(200, [], '{}')]);
        $sleeps = new SleepRecorder();

        $response = $this->retryingClient($http, $sleeps)
            ->send('POST', '/v1/operations/execute', ['a' => 1]);

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $http->requests);
        self::assertSame([], $sleeps->seconds);
    }

    /** @return iterable<string, array{float, float}> */
    public static function jitterRolls(): iterable
    {
        // Retry-After: 5 → the sleep is 5 + roll * 0.25 * 5, i.e. [5, 6.25].
        yield 'no jitter' => [0.0, 5.0];
        yield 'half jitter' => [0.5, 5.625];
        yield 'almost full jitter' => [0.999999, 6.24999875];
    }

    #[DataProvider('jitterRolls')]
    public function testRetryAfterIsHonouredAndJitterIsAddedOnTop(float $roll, float $expected): void
    {
        $http = new ScriptedHttpClient([self::tooManyRequests('5'), new Response(200, [], '{}')]);
        $sleeps = new SleepRecorder();

        $this->retryingClient($http, $sleeps, $roll)->send('GET', '/v1/health');

        self::assertEqualsWithDelta($expected, $sleeps->seconds[0], 1e-9);
        // The floor is the advertised window ITSELF: retrying earlier would hit
        // the same unreset window and burn another nonce for nothing.
        self::assertGreaterThanOrEqual(5.0, $sleeps->seconds[0]);
        self::assertLessThanOrEqual(6.25, $sleeps->seconds[0]);
    }

    /**
     * Sleeps recorded while send() walks one 429 carrying $retryAfter VERBATIM,
     * followed by a 200.
     *
     * Uses {@see RawRetryAfterResponse} rather than a normal PSR-7 Response:
     * Guzzle validates header values on construction and rejects any string
     * containing CR, LF or NUL, so most of the characters the three SDKs must
     * agree about cannot be expressed through the normal path at all. That
     * validation is a defence, not the contract under test — the TypeScript
     * suite bypasses its own Headers API for exactly the same reason and with
     * the same inputs (see `rawHeaderFetch` there).
     *
     * @return list<float>
     */
    private function sleepsForRawRetryAfter(string $retryAfter): array
    {
        $http = new ScriptedHttpClient([
            new RawRetryAfterResponse($retryAfter),
            new Response(200, [], '{}'),
        ]);
        $sleeps = new SleepRecorder();

        $this->retryingClient($http, $sleeps)->send('GET', '/v1/health');

        return $sleeps->seconds;
    }

    /**
     * Every member of the six-character ASCII whitespace set the three SDKs
     * share. Built with chr(), never typed as a literal — an invisible
     * character that a file write or an editor normalises leaves a test that
     * passes while asserting nothing.
     *
     * @return iterable<string, array{string}>
     */
    public static function paddedRetryAfterValues(): iterable
    {
        yield 'unpadded' => ['5'];
        yield 'spaces' => [chr(0x20) . '5' . chr(0x20)];
        yield 'tab' => [chr(0x09) . '5' . chr(0x09)];
        yield 'newline' => [chr(0x0A) . '5' . chr(0x0A)];
        yield 'carriage return' => [chr(0x0D) . '5' . chr(0x0D)];
        yield 'vertical tab' => [chr(0x0B) . '5' . chr(0x0B)];
        yield 'form feed' => [chr(0x0C) . '5' . chr(0x0C)];
    }

    #[DataProvider('paddedRetryAfterValues')]
    public function testRetryAfterIsTrimmedBeforeParsing(string $retryAfter): void
    {
        // PSR-7's getHeaderLine() does NOT trim, so without an explicit trim
        // here the three SDKs would disagree on " 5 ".
        //
        // The trim uses an EXPLICIT six-character set rather than bare trim():
        // PHP's default charlist omits \f and includes NUL, JS's .trim() covers
        // Unicode White_Space plus U+FEFF, and Python's .strip() covers Unicode
        // but not U+FEFF. Three built-ins, three answers — measured, they
        // disagreed on \f and NUL. Same defect shape as the method-casing bug
        // in #139, so it gets the same treatment: spell the set out.
        self::assertSame([5.0], $this->sleepsForRawRetryAfter($retryAfter));
    }

    /**
     * A policy whose COMPUTED delay overflows is rejected at construction.
     *
     * Each field is individually finite and non-negative, so the per-field
     * checks all pass — and the product is INF. That policy used to construct
     * happily and fail on the first 429, differently in each SDK: PHP casts INF
     * and does not wait, JS `setTimeout(Infinity)` fires after ~1ms and
     * hot-loops the retry, Python's `time.sleep(inf)` raises. A policy that
     * validates and then cannot work is worse than one that never validated,
     * because the failure lands mid-incident.
     */
    public function testAPolicyWhoseComputedDelayOverflowsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be <=');
        new RetryPolicy(maxDelaySeconds: PHP_FLOAT_MAX, jitterFraction: 1.0);
    }

    /**
     * Finite but past the SHARED ceiling is rejected too.
     *
     * PHP could sleep for 30 days; JS `setTimeout` cannot (it overflows beyond
     * 2^31-1 ms and fires immediately). The bound is shared so all three SDKs
     * accept an identical set of policies — a config every SDK takes and only
     * two honour is the divergence this package exists to avoid.
     */
    public function testAFinitePolicyPastTheSharedCeilingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be <=');
        new RetryPolicy(maxDelaySeconds: 30 * 24 * 60 * 60);
    }

    /** The defaults are nowhere near the ceiling: 60 * 1.25 = 75 seconds. */
    public function testTheDefaultPolicyIsWellInsideTheCeiling(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(75.0, $policy->maxDelaySeconds * (1 + $policy->jitterFraction));
        self::assertLessThan(RetryPolicy::MAX_TOTAL_DELAY_SECONDS, 75.0);
    }

    /**
     * The DEFAULT sleeper must never hand usleep() a value above 1_000_000.
     *
     * PHP's manual warns that usleep() "may not be supported by the operating
     * system" beyond one second, and this is reachable with DEFAULT config
     * rather than some exotic policy: the runtime advertises `Retry-After: 60`,
     * so the ordinary rate-limit path used to land on usleep(60_000_000). Where
     * that is unsupported the call returns WITHOUT waiting, and the client
     * re-signs and re-sends straight into the same rate limit — a hot loop
     * burning a fresh nonce per pass, against a runtime already asking it to
     * slow down.
     *
     * Asserted on the arithmetic rather than by sleeping: a test that really
     * waited 60 seconds would be deleted by the next person to run the suite.
     * The expression mirrors the constructor's default sleeper.
     */
    #[DataProvider('backoffDurations')]
    public function testTheDefaultSleeperNeverExceedsUsleepsDocumentedRange(
        float $seconds,
        int $expectedWholeSeconds,
        int $expectedMicroseconds,
    ): void {
        $whole = (int) floor($seconds);
        $micro = (int) round(($seconds - $whole) * 1_000_000);
        if ($micro >= 1_000_000) {
            $whole++;
            $micro -= 1_000_000;
        }

        self::assertSame($expectedWholeSeconds, $whole);
        self::assertSame($expectedMicroseconds, $micro);
        self::assertLessThan(1_000_000, $micro, 'usleep() must never be handed a full second or more');
        self::assertGreaterThanOrEqual(0, $micro);
        // The split must still add up to the delay the policy computed.
        self::assertEqualsWithDelta($seconds, $whole + $micro / 1_000_000, 1e-6);
    }

    /** @return iterable<string, array{float, int, int}> */
    public static function backoffDurations(): iterable
    {
        // The default path: Retry-After 60, no jitter.
        yield 'default 60s window' => [60.0, 60, 0];
        // 60s + full jitter at the defaults — the longest sleep this SDK issues.
        yield 'capped window plus jitter' => [75.0, 75, 0];
        yield 'sub-second' => [0.25, 0, 250000];
        yield 'exponential fallback' => [1.0, 1, 0];
        yield 'fractional over a second' => [1.5, 1, 500000];
        // round() carries the remainder to a FULL second here, which is the
        // case the carry guard exists for and the one a naive split gets wrong
        // (it would call usleep(1_000_000) — exactly the boundary the manual
        // warns about). Value chosen by measurement: 1.9999995 does NOT carry,
        // because in double precision it is 1.99999949999..., so the product
        // rounds to 999999. Reasoning about which decimals carry is how you
        // write a test that passes for the wrong reason.
        yield 'rounds up into the next second' => [1.9999999, 2, 0];
        yield 'carries from a sub-second delay' => [0.9999999, 1, 0];
    }

    /**
     * NON-ASCII whitespace is NOT trimmed, in any of the three SDKs.
     *
     * The point is the agreement, not the direction: an HTTP header value has
     * no business carrying U+00A0 or a BOM, and whichever way the three
     * behaved would be defensible — what is not defensible is behaving
     * differently. Before the trim was harmonized, "\u{00A0}5" honoured a 5s
     * window in TypeScript and Python but fell back to 1s backoff here.
     *
     * Same inputs as the TypeScript and Python suites.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonAsciiWhitespaceRetryAfterValues(): iterable
    {
        yield 'leading NBSP' => ["\u{00A0}5"];
        yield 'trailing NBSP' => ["5\u{00A0}"];
        yield 'leading BOM' => ["\u{FEFF}5"];
        yield 'trailing BOM' => ["5\u{FEFF}"];
        yield 'leading NUL' => [chr(0x00) . '5'];
        yield 'trailing NUL' => ['5' . chr(0x00)];
    }

    #[DataProvider('nonAsciiWhitespaceRetryAfterValues')]
    public function testNonAsciiWhitespaceAroundRetryAfterFallsBackToBackoff(string $retryAfter): void
    {
        // baseDelaySeconds 1.0, attempt 1, jitter roll 0.0 → exactly 1.0s.
        self::assertSame([1.0], $this->sleepsForRawRetryAfter($retryAfter));
    }

    /** @return iterable<string, array{string|null}> */
    public static function unparseableRetryAfterValues(): iterable
    {
        yield 'header absent' => [null];
        yield 'garbage' => ['not-a-number'];
        // Deliberately NOT parsed: the runtime only emits delay-seconds, and
        // three per-language date parsers would be three ways to disagree.
        yield 'RFC 9110 HTTP-date' => ['Wed, 21 Oct 2026 07:28:00 GMT'];
        yield 'more than 9 digits' => ['1234567890'];
        yield 'negative' => ['-5'];
        yield 'fractional' => ['5.5'];
    }

    #[DataProvider('unparseableRetryAfterValues')]
    public function testUnparseableRetryAfterFallsBackToExponentialBackoff(?string $retryAfter): void
    {
        $http = new ScriptedHttpClient([
            self::tooManyRequests($retryAfter),
            self::tooManyRequests($retryAfter),
            new Response(200, [], '{}'),
        ]);
        $sleeps = new SleepRecorder();

        $this->retryingClient($http, $sleeps)->send('GET', '/v1/health');

        // baseDelaySeconds * 2^(n-1) = 1s then 2s.
        self::assertSame([1.0, 2.0], $sleeps->seconds);
    }

    public function testAHugeRetryAfterIsCappedAtMaxDelaySeconds(): void
    {
        $http = new ScriptedHttpClient([self::tooManyRequests('86400'), new Response(200, [], '{}')]);
        $sleeps = new SleepRecorder();

        $this->retryingClient($http, $sleeps)->send('GET', '/v1/health');

        self::assertSame([(new RetryPolicy())->maxDelaySeconds], $sleeps->seconds);
    }

    public function testExhaustedAttemptsRETURNTheFinal429RatherThanThrowing(): void
    {
        $http = new ScriptedHttpClient([
            self::tooManyRequests('1'),
            self::tooManyRequests('1'),
            self::tooManyRequests('1'),
        ]);
        $sleeps = new SleepRecorder();

        $response = $this->retryingClient($http, $sleeps)
            ->send('POST', '/v1/operations/execute', ['a' => 1]);

        self::assertSame(429, $response->getStatusCode());
        // The caller must still be able to read the problem+json body and the
        // Retry-After header off the real response.
        self::assertStringContainsString('rate_limit.quota_exceeded', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertCount((new RetryPolicy())->maxAttempts, $http->requests);
        self::assertCount((new RetryPolicy())->maxAttempts - 1, $sleeps->seconds);
    }

    public function testMaxAttemptsOneSendsExactlyOnceAndNeverSleeps(): void
    {
        // The canonical opt-out: one policy value, no false/null special case.
        self::assertEquals(new RetryPolicy(maxAttempts: 1), RetryPolicy::disabled());

        foreach ([RetryPolicy::disabled(), new RetryPolicy(maxAttempts: 1)] as $policy) {
            $http = new ScriptedHttpClient([self::tooManyRequests('1')]);
            $sleeps = new SleepRecorder();

            $response = $this->retryingClient($http, $sleeps, 0.0, $policy)->send('GET', '/v1/health');

            self::assertSame(429, $response->getStatusCode());
            self::assertCount(1, $http->requests);
            self::assertSame([], $sleeps->seconds);
        }
    }

    /** @return iterable<string, array{\Closure(): RetryPolicy, string}> */
    public static function invalidRetryPolicies(): iterable
    {
        yield 'zero attempts' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 0),
            'maxAttempts must be an integer >= 1 (got 0)',
        ];
        yield 'negative attempts' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: -1),
            'maxAttempts must be an integer >= 1 (got -1)',
        ];
        yield 'negative base delay' => [
            static fn(): RetryPolicy => new RetryPolicy(baseDelaySeconds: -1.0),
            'baseDelaySeconds must be a finite number >= 0',
        ];
        yield 'negative max delay' => [
            static fn(): RetryPolicy => new RetryPolicy(maxDelaySeconds: -0.5),
            'maxDelaySeconds must be a finite number >= 0',
        ];
        yield 'infinite max delay' => [
            static fn(): RetryPolicy => new RetryPolicy(maxDelaySeconds: INF),
            'maxDelaySeconds must be a finite number >= 0',
        ];
        yield 'nan max delay' => [
            static fn(): RetryPolicy => new RetryPolicy(maxDelaySeconds: NAN),
            'maxDelaySeconds must be a finite number >= 0',
        ];
        yield 'negative jitter' => [
            static fn(): RetryPolicy => new RetryPolicy(jitterFraction: -0.25),
            'jitterFraction must be a finite number >= 0',
        ];
    }

    /** @param \Closure(): RetryPolicy $construct */
    #[DataProvider('invalidRetryPolicies')]
    public function testInvalidRetryPolicyIsRejectedAtConstruction(\Closure $construct, string $message): void
    {
        // Fail where the client is wired up, not mid-incident on the first 429.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $construct();
    }

    public function testZeroDelaysAndZeroJitterAreLegitimate(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, baseDelaySeconds: 0.0, maxDelaySeconds: 0.0, jitterFraction: 0.0);
        $http = new ScriptedHttpClient([self::tooManyRequests(), new Response(200, [], '{}')]);
        $sleeps = new SleepRecorder();

        $response = $this->retryingClient($http, $sleeps, 0.0, $policy)->send('GET', '/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([0.0], $sleeps->seconds, 'a zero-delay policy retries immediately');
    }

    public function testCreateSignedRequestNeverRetries(): void
    {
        // It sends nothing, so there is no response to retry. Re-calling it is
        // how such a caller gets a fresh nonce.
        $http = new ScriptedHttpClient([self::tooManyRequests('1')]);
        $sleeps = new SleepRecorder();
        $client = $this->retryingClient($http, $sleeps);

        $first = $client->createSignedRequest('GET', '/v1/health');
        $second = $client->createSignedRequest('GET', '/v1/health');

        self::assertSame([], $http->requests);
        self::assertNotSame(
            $first->getHeaderLine('X-IR-Nonce'),
            $second->getHeaderLine('X-IR-Nonce'),
        );
    }

    // ------------------------------------------- response enum tolerance (#178)

    /**
     * A response body poisoning all four public response enums with members no
     * version of this SDK has ever seen.
     */
    private const POISONED_BODY = '{"job":{"id":"job_1","status":"quantum_superposition"},'
        . '"steps":[{"id":"step_1","status":"warp_drive_engaged","mapping_source":"telepathy"}],'
        . '"warnings":[{"code":"mapping.invented_by_the_server","message":"hello from the future"}]}';

    public function testUnknownResponseEnumMembersRoundTripUntouched(): void
    {
        // #132 declares all eight public enums extensible and tells consumers
        // they must tolerate unknown members. That declaration is decorative if
        // our own SDK throws at the boundary. Today it cannot: this SDK does not
        // decode a single byte of a response body — every response touch is
        // getStatusCode(), the Retry-After header, and the body stream. That is
        // an accident of ABSENCE, not a designed guarantee: ADR-012 §12 calls
        // for typed per-endpoint responses, which would close it without anyone
        // noticing the contract implication. This test must FAIL if a validator
        // is inserted.
        $http = new MockHttpClient(new Response(200, ['Content-Type' => 'application/json'], self::POISONED_BODY));
        $factory = new HttpFactory();
        $client = new Client($http, $factory, $factory, 'https://ir.example.com', self::APP_ID, self::KEY_ID, self::SECRET);

        $response = $client->send('GET', '/v1/sync-jobs/job_1');

        self::assertSame(200, $response->getStatusCode());
        // Byte-for-byte, not a shape check: a validator that STRIPPED the
        // unknown members rather than throwing would pass a "no exception"
        // assertion.
        self::assertSame(self::POISONED_BODY, (string) $response->getBody());
        // And the members really are unknown — decoded HERE, by the test, never
        // by the SDK.
        $decoded = json_decode(self::POISONED_BODY, true);
        self::assertSame('quantum_superposition', $decoded['job']['status']);
        self::assertSame('warp_drive_engaged', $decoded['steps'][0]['status']);
        self::assertSame('telepathy', $decoded['steps'][0]['mapping_source']);
        self::assertSame('mapping.invented_by_the_server', $decoded['warnings'][0]['code']);
    }

    public function testClientReturnsTheTransportResponseObjectItself(): void
    {
        // Nothing is wrapped, re-serialized, or validated on the way out.
        $psrResponse = new Response(200, [], self::POISONED_BODY);
        $http = new MockHttpClient($psrResponse);
        $factory = new HttpFactory();
        $client = new Client($http, $factory, $factory, 'https://ir.example.com', self::APP_ID, self::KEY_ID, self::SECRET);

        self::assertSame($psrResponse, $client->send('GET', '/v1/sync-jobs/job_1'));
    }

    public function testNegativeControlTheSdkStillThrowsWhereItShould(): void
    {
        // Paired with the tolerance tests on purpose. If a future edit made this
        // harness swallow exceptions, every "no exception escaped" assertion
        // above would pass while proving nothing. The method allowlist is the
        // nearest known-throwing path.
        $http = new MockHttpClient(new Response(200, [], self::POISONED_BODY));
        $factory = new HttpFactory();
        $client = new Client($http, $factory, $factory, 'https://ir.example.com', self::APP_ID, self::KEY_ID, self::SECRET);

        $this->expectException(InvalidMethodException::class);
        $client->send('BREW', '/v1/sync-jobs');
    }
}
