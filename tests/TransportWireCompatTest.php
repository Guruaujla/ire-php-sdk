<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\RetryPolicy;
use Ezee\IntegrationRuntime\Signer;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The signed bytes and the WIRE bytes, measured on a real socket (issue #194).
 *
 * Every other suite in this package stops above the transport: ClientTest
 * drives an in-memory PSR-18 stub, and the wire-fixture suite replays committed
 * signatures through the runtime's verifier. Both were green while the PHP SDK
 * signed one path and sent another — because both compare the SIGNER to a
 * VERIFIER, and the defect lived between the signer and the TRANSPORT:
 *
 *   signed:  /v1/sync-jobs/A%7eB?tenant_id=t_1     (the PSR-7 URI)
 *   wire:    /v1/sync-jobs/A%7EB?tenant_id=t_1     (what ext-curl emitted)
 *
 * Result: a permanent, unrecoverable 401 (a retry re-signs the same lowercase
 * string), on curl only — so it passes in a dev environment without ext-curl
 * and fails in production. No mock transport can reproduce that. This suite
 * therefore crosses a real socket, and runs every case on BOTH Guzzle handlers
 * explicitly, because the whole point is that the two disagreed.
 *
 * THE assertion is {@see assertSignedEqualsWire}: it recomputes the HMAC over
 * the bytes that ARRIVED — the request-line target, the method, the body — and
 * compares it to the `X-IR-Signature` that arrived alongside them. That is
 * precisely what `src/middleware/service-auth.ts` does, so a pass here means
 * "this request would authenticate", with no appeal to SDK internals for the
 * thing being proven.
 */
#[CoversClass(Client::class)]
final class TransportWireCompatTest extends TestCase
{
    /**
     * The published TEST secret from the shared test-vectors file
     * (openapi/test-vectors/hmac-signing.json): bytes 0..31 base64url'd.
     * Never real key material.
     */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private const APP_ID = 'app_erp';

    private const KEY_ID = 'key_primary';

    /** @var list<string> paths of capture files to remove in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    /**
     * One case per class of path the fix has to hold for, crossed with both
     * transports by {@see wireCases}.
     *
     * The last column is the EXPECTED WIRE TARGET, spelled out as a literal
     * rather than derived from the client. Without it one whole class of
     * regression would sail through: dropping the "#" pre-encoding leaves
     * signed and wire in perfect agreement (both become `/v1/sync-jobs/a`,
     * because the fragment is discarded on BOTH sides) while the caller's
     * `tenant_id` silently disappears — a 400 nobody can trace, and exactly the
     * shape of test that stays green with the bug present. `signed == wire`
     * proves authentication; only a literal proves the bytes are the RIGHT
     * bytes.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string}>
     */
    public static function paths(): array
    {
        return [
            // The #194 case itself: a lowercase escape in a path segment. This
            // is the one that was 401ing on curl and passing on streams.
            [
                'lowercase-escape-in-path', 'GET', '/v1/sync-jobs/A%7eB?tenant_id=t_1', null,
                '/v1/sync-jobs/A%7EB?tenant_id=t_1',
            ],
            // A lowercase MULTI-BYTE escape — three hex pairs to rewrite, and
            // the case a naive "uppercase the first escape" fix would miss.
            [
                'lowercase-utf8-escape-in-path', 'GET', '/v1/sync-jobs/caf%c3%a9?tenant_id=t_1', null,
                '/v1/sync-jobs/caf%C3%A9?tenant_id=t_1',
            ],
            // An encoded slash. Must survive ESCAPED end-to-end: decoding it
            // would inject path structure the caller never wrote.
            [
                'encoded-slash-in-path', 'GET', '/v1/sync-jobs/A%2fB?tenant_id=t_1', null,
                '/v1/sync-jobs/A%2FB?tenant_id=t_1',
            ],
            // A raw "#" (issue #195). Pre-encoded to %23, so the query after it
            // still reaches the server instead of vanishing into a fragment.
            [
                'raw-hash-in-path', 'GET', '/v1/sync-jobs/a#b?tenant_id=t_1', null,
                '/v1/sync-jobs/a%23b?tenant_id=t_1',
            ],
            // An already-written %23 must NOT be double-encoded: its expected
            // target is IDENTICAL to the raw-"#" case above, which is what
            // makes `%2523` a visible failure rather than a silent one.
            [
                'pre-encoded-hash-in-path', 'GET', '/v1/sync-jobs/a%23b?tenant_id=t_1', null,
                '/v1/sync-jobs/a%23b?tenant_id=t_1',
            ],
            // Both belts at once in a QUERY: the apostrophe the WHATWG set
            // encodes, and a lowercase escape beside it.
            [
                'apostrophe-and-escape-in-query', 'GET', "/v1/sync-jobs?filter=name%3d'x'", null,
                '/v1/sync-jobs?filter=name%3D%27x%27',
            ],
            // The "#" case again, but in the QUERY. Guzzle's PSR-7 routes the
            // two components through different filters (filterPath vs
            // filterQueryAndFragment) and preEncodeRawHash deliberately covers
            // both by running before either — untested, "cover both" is an
            // intention rather than a property. Losing it here is the quiet
            // failure: `&t=1` becomes fragment and vanishes, signed still
            // equals wire, and only the literal below notices.
            [
                'raw-hash-in-query', 'GET', '/v1/sync-jobs?q=a#b&t=1', null,
                '/v1/sync-jobs?q=a%23b&t=1',
            ],
            // A "\" in the QUERY is legitimate (a Windows path in a filter) and
            // must go through. This is the input that separates the scoped "\"
            // rejection from a footgun: the guard reads only the bytes before
            // the first "?", so widening it to the whole string would turn this
            // caller's request into an InvalidArgumentException. Guzzle's `Uri`
            // escapes it to %5C on the way in — that is client path derivation,
            // where the SDKs are allowed to differ (the TypeScript SDK sends it
            // raw); what the cross-SDK contract fixes is that whatever goes on
            // the wire is what got signed.
            [
                'backslash-in-query', 'GET', '/v1/sync-jobs?filter=C:\\logs', null,
                '/v1/sync-jobs?filter=C:%5Clogs',
            ],
            // A bare trailing "?" must be DROPPED, matching TypeScript and
            // Python. It is signed-vs-sent in miniature: an empty query that
            // one side keeps and the other trims is a one-character difference
            // in the canonical payload and a 401 with nothing to see in a log.
            [
                'bare-trailing-question', 'GET', '/v1/sync-jobs?', null,
                '/v1/sync-jobs',
            ],
            // A body case: the signature covers the body hash, so this proves
            // the bytes hashed are the bytes that reached the socket.
            [
                'post-json-body', 'POST', '/v1/operations/execute?tenant_id=t_1',
                '{"invoice":"INV-1","amount":100}', '/v1/operations/execute?tenant_id=t_1',
            ],
            // CONTROL: the shape every /v1 path param actually has today. It
            // must pass before and after the fix — if this one ever fails, the
            // harness is broken, not the SDK.
            [
                'control-plain-uuidv7', 'GET',
                '/v1/sync-jobs/0192f3a1-7c2e-7b3d-9f10-2a5c6d7e8f90?tenant_id=t_1', null,
                '/v1/sync-jobs/0192f3a1-7c2e-7b3d-9f10-2a5c6d7e8f90?tenant_id=t_1',
            ],
        ];
    }

    /**
     * Every path on every transport. Guzzle's handler is chosen EXPLICITLY
     * rather than left to `choose_handler()`: the default depends on which
     * extensions are installed, which is the very thing that made this bug
     * environment-dependent, and a suite that inherits that choice would test
     * whichever transport the runner happened to have.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: string}>
     */
    public static function wireCases(): iterable
    {
        foreach (['curl', 'stream'] as $transport) {
            foreach (self::paths() as [$label, $method, $path, $body, $expectedTarget]) {
                yield "{$transport}/{$label}" => [$transport, $label, $method, $path, $body, $expectedTarget];
            }
        }
    }

    #[DataProvider('wireCases')]
    public function test_signed_path_equals_the_wire_path_on_both_transports(
        string $transport,
        string $label,
        string $method,
        string $path,
        ?string $body,
        string $expectedTarget,
    ): void {
        if ($transport === 'curl' && ! extension_loaded('curl')) {
            // Explicit, and never silent: skipping curl is skipping the only
            // transport that had the defect, so it has to be visible in the
            // run output rather than inferred from a green suite.
            $why = 'ext-curl is not loaded, so the curl transport — the one issue #194 was measured on — cannot be exercised here.';

            // On CI a skip is the gate defeating itself. ext-curl is the only
            // transport that rewrites the request-line path, so these legs ARE
            // the #194 regression; the stream legs would still pass with the
            // fix reverted. A runner image that loses ext-curl would therefore
            // turn the whole regression into "8 skipped" under a green tick.
            // sdk-php-ci.yml pins `extensions: curl` on setup-php to stop that
            // upstream — this branch is the independent half, so that the test
            // still refuses to certify a run it did not perform even if the
            // workflow is edited, replaced, or run somewhere else.
            if (self::runningOnCi()) {
                self::fail($why . ' On CI that is not a limitation to work around: it means the'
                    . ' curl legs of the issue #194 regression did not run. sdk-php-ci.yml pins'
                    . ' `extensions: curl` on setup-php precisely so this cannot happen, so'
                    . ' reaching here means that pin was removed or the job is running elsewhere.');
            }

            self::markTestSkipped($why);
        }

        [$capture, $port, $proc, $pipes] = $this->startListener();

        try {
            $handler = $transport === 'curl' ? new CurlHandler() : new StreamHandler();
            $guzzle = new GuzzleClient([
                'handler' => HandlerStack::create($handler),
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 5,
            ]);
            $factory = new HttpFactory();
            $client = new Client(
                $guzzle,
                $factory,
                $factory,
                "http://127.0.0.1:{$port}",
                self::APP_ID,
                self::KEY_ID,
                self::SECRET,
                RetryPolicy::disabled(),
            );

            $request = $client->createSignedRequest($method, $path, $body);
            $uri = $request->getUri();
            $query = $uri->getQuery();
            $signedPathWithQuery = $uri->getPath() . ($query !== '' ? '?' . $query : '');

            $guzzle->sendRequest($request);
        } finally {
            $this->stopListener($proc, $pipes);
        }

        $captured = $this->readCapture($capture);
        self::assertCount(1, $captured, "{$transport}/{$label}: expected exactly one captured request");
        $wire = $captured[0];

        // Asserted FIRST purely for legibility: when this fails the diff shows
        // the two path strings side by side, which names the defect. The
        // signature assertion below is the one that actually proves the
        // invariant — it is what the verifier computes.
        self::assertSame(
            $signedPathWithQuery,
            $wire['target'],
            "{$transport}/{$label}: the SDK signed a different path than the transport sent",
        );

        // The bytes are not merely CONSISTENT, they are the right bytes. See
        // {@see paths()} for the regression this column exists to catch.
        self::assertSame(
            $expectedTarget,
            $wire['target'],
            "{$transport}/{$label}: the request that reached the socket is not the one the caller asked for",
        );

        $this->assertSignedEqualsWire($transport, $label, $wire);
    }

    /**
     * THE SENSOR: prove {@see assertSignedEqualsWire} can actually fail.
     *
     * Every case above is evidence only if the helper rejects a wrong target.
     * It never fails during a healthy run, so nothing else in this file
     * distinguishes "the invariant holds" from "the helper compares two copies
     * of the same thing" — a harness that captured the SDK's own idea of the
     * target, or a signer that ignored the path, would leave all sixteen legs
     * green while certifying nothing.
     *
     * The tamper is the #194 rewrite itself, applied by hand: sign the
     * lowercase target, then present the uppercase one ext-curl would have put
     * on the wire. One hex digit. No socket is involved — this is a test OF the
     * assertion, not a test of the transport.
     */
    public function test_the_wire_assertion_rejects_a_tampered_target(): void
    {
        $signed = Signer::signRequest(
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
            'GET',
            '/v1/sync-jobs/A%7eB?tenant_id=t_1',
            '',
            '1700000000',
            'php-transport-sensor-nonce-0001',
        );

        $curlWouldHaveSent = [
            'method' => 'GET',
            // Signed with "%7e", presented as "%7E".
            'target' => '/v1/sync-jobs/A%7EB?tenant_id=t_1',
            'headers' => [
                'x-ir-timestamp' => $signed->timestamp,
                'x-ir-nonce' => $signed->nonce,
                'x-ir-signature' => $signed->headers['X-IR-Signature'],
            ],
            'body' => '',
        ];

        try {
            $this->assertSignedEqualsWire('sensor', 'tampered-target', $curlWouldHaveSent);
        } catch (ExpectationFailedException $failure) {
            // Not merely "it threw": pin the message, so the sensor cannot be
            // satisfied by the header-presence assertions failing for an
            // unrelated reason instead of the signature comparison.
            self::assertStringContainsString('hmac.signature_invalid', $failure->getMessage());

            return;
        }

        self::fail(
            'assertSignedEqualsWire accepted a target that had been tampered with in exactly the way '
            . 'issue #194 tampered with it, so it cannot be evidence for any of the cases above.',
        );
    }

    /**
     * True when this run is a CI run, used to turn the missing-ext-curl skip
     * into a failure.
     *
     * Deliberately tolerant about the value: GitHub Actions sets `CI=true`, but
     * an empty or falsy value is treated as "not CI" so that a shell exporting
     * `CI=` or `CI=0` does not fail a developer's local run — the point is to
     * catch a CI run that quietly skipped, not to punish a stray variable.
     */
    private static function runningOnCi(): bool
    {
        $ci = getenv('CI');

        return $ci !== false && ! in_array(strtolower($ci), ['', '0', 'false'], true);
    }

    /**
     * Recompute the signature from what ARRIVED and compare it to the
     * `X-IR-Signature` that arrived with it.
     *
     * This is the runtime's own check, reproduced: `service-auth.ts` reads the
     * request-line target, the method and the raw body off the wire, rebuilds
     * the canonical payload with the request's own timestamp and nonce
     * headers, and constant-time-compares. Nothing here reads the SDK's idea of
     * what it signed — so a pass is definitionally "these bytes authenticate",
     * and no future refactor of the client can make this assertion vacuous by
     * changing what the SDK reports about itself.
     *
     * @param array{method: string, target: string, headers: array<string, string>, body: string} $wire
     */
    private function assertSignedEqualsWire(string $transport, string $label, array $wire): void
    {
        foreach (['x-ir-timestamp', 'x-ir-nonce', 'x-ir-signature'] as $header) {
            self::assertArrayHasKey($header, $wire['headers'], "{$transport}/{$label}: {$header} missing on the wire");
        }

        $recomputed = Signer::signRequest(
            self::APP_ID,
            self::KEY_ID,
            self::SECRET,
            $wire['method'],
            $wire['target'],
            $wire['body'],
            $wire['headers']['x-ir-timestamp'],
            $wire['headers']['x-ir-nonce'],
        );

        self::assertSame(
            $wire['headers']['x-ir-signature'],
            $recomputed->headers['X-IR-Signature'],
            "{$transport}/{$label}: the signature that arrived does not verify against the bytes that arrived — "
            . 'the runtime would answer 401 hmac.signature_invalid. Wire target: ' . $wire['target'],
        );
    }

    /**
     * Start the capture listener in a child process, serving exactly one
     * connection.
     *
     * The port comes from the CHILD (bound to :0, printed on stdout) and is
     * never hardcoded: a fixed port could be answered by a server another
     * worktree or suite left running, and the test would then measure someone
     * else's process and pass.
     *
     * @return array{0: string, 1: int, 2: resource, 3: array<int, resource>}
     */
    private function startListener(): array
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'ir-wire-');
        $this->tempFiles[] = $capture;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/wire-capture-listener.php', '1', $capture],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc, 'failed to start the capture listener');

        // Blocks only until the child has bound; the child prints the line
        // immediately after stream_socket_server() returns.
        $line = fgets($pipes[1]);
        if ($line === false || preg_match('/\APORT (\d+)\R/', $line, $matches) !== 1) {
            $stderr = (string) stream_get_contents($pipes[2]);
            $this->stopListener($proc, $pipes);
            self::fail('capture listener did not report a port: ' . var_export($line, true) . " stderr: {$stderr}");
        }

        return [$capture, (int) $matches[1], $proc, $pipes];
    }

    /**
     * Always run, via `finally` — a listener left behind would hold a port and
     * a process for the rest of the run.
     *
     * @param resource               $proc
     * @param array<int, resource>   $pipes
     */
    private function stopListener($proc, array $pipes): void
    {
        proc_terminate($proc);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($proc);
    }

    /**
     * @return list<array{method: string, target: string, headers: array<string, string>, body: string}>
     */
    private function readCapture(string $capture): array
    {
        $lines = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, "capture file {$capture} could not be read");

        $out = [];
        foreach ($lines as $line) {
            /** @var array{method: string, target: string, headers: array<string, string>, body: string} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $out[] = $decoded;
        }

        return $out;
    }
}
