<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 signed-request client for the Integration Runtime /v1 API.
 *
 * The PHP twin of `sdks/typescript/src/client.ts`: builds a PSR-7 request,
 * signs it with {@see Signer} (the runtime's HMAC contract), and sends it via
 * the injected PSR-18 client. The wire-compat invariant throughout: the bytes
 * that are HASHED are exactly the bytes that are SENT — bodies are serialized
 * once, `pathWithQuery` is derived from the FINAL request URI (after any
 * PSR-7 normalization), and the five X-IR-* headers are set last so caller
 * headers can never shadow them.
 *
 * The secret is held only as a private readonly property: no getter,
 * masked in {@see __debugInfo}, and serialization throws
 * ({@see __serialize}).
 *
 * ─── Retrying 429s (issue #134) ─────────────────────────────────────────────
 *
 * The runtime rate-limits inbound calls on TWO layers (issue #96):
 *
 *   Layer 1  `rate_limit.shield_shed`     — rejected BEFORE serviceAuth runs,
 *                                           so the request's nonce is untouched.
 *   Layer 2  `rate_limit.quota_exceeded`  — rejected AFTER serviceAuth, which
 *                                           has ALREADY INSERTED the nonce.
 *
 * Replaying the identical signed bytes after a Layer 2 429 therefore answers
 * 401 `hmac.nonce_reused` — not a retry, not a success. So {@see send()} builds
 * and signs a COMPLETELY FRESH request on every attempt: new nonce, new
 * timestamp, new signature. A naive resend of the buffered request looks
 * correct in a unit test and fails in production. See the NONCE-BURN CONTRACT
 * block in src/middleware/inbound-rate-limit.ts and §4 of
 * docs/runbooks/inbound-rate-limits.md.
 *
 * ONE code path serves both layers on purpose: re-signing is a strict superset
 * of what Layer 1 needs — a fresh nonce is ALWAYS valid — so there is nothing
 * to gain from telling the two apart, and a branch keyed on the problem `code`
 * would make correctness depend on parsing a body we may not be able to read.
 */
final class Client
{
    /** The only status that is ever retried — see {@see send()}. */
    private const RETRY_STATUS = 429;

    /**
     * `Retry-After` is read as DELAY-SECONDS ONLY. RFC 9110 §10.2.3 also allows
     * an HTTP-date, but the runtime only ever emits delay-seconds, and three
     * independent date parsers (PHP `DateTime`, JS `Date.parse`, Python
     * `email.utils`) on a cross-SDK equivalence surface would buy nothing but
     * divergence. A date — or any other garbage — falls back to exponential
     * backoff, which is always a safe answer.
     *
     * `\A…\z`, NOT `^…$`: PCRE's `$` also matches immediately before a final
     * newline, so `^\d{1,9}$` would read "5\n" as 5 seconds where the JS and
     * Python SDKs fall back to backoff. The trim below already strips that
     * newline, so this is defence in depth rather than a live bug — but it is
     * the same anchor that WAS a live bug in `Signer::decodeSecret`, so it does
     * not get to stay loose.
     */
    private const RETRY_AFTER_DELAY_SECONDS = '/\A\d{1,9}\z/';

    /**
     * ASCII whitespace trimmed from a `Retry-After` value — the same six
     * characters the TypeScript and Python SDKs trim.
     *
     * PHP's bare `trim()` uses `" \t\n\r\0\x0B"`, which omits `\f` and includes
     * NUL; JS's `.trim()` covers Unicode White_Space plus U+FEFF; Python's
     * `.strip()` covers Unicode but not U+FEFF. Three built-ins, three answers.
     * Spelling the set out is what makes the three agree.
     */
    private const ASCII_WHITESPACE = " \t\n\v\f\r";

    /**
     * The runtime hashes an EMPTY body for these methods regardless of any
     * payload (src/middleware/service-auth.ts#readBodyBytes). To stay
     * wire-compatible we sign over — and send — no body for them.
     */
    private const NO_BODY_METHODS = ['GET', 'HEAD', 'DELETE'];

    /**
     * Printable ASCII the WHATWG URL serializer percent-encodes in a
     * http(s) PATH but PSR-7 implementations may leave raw — the spec's
     * path percent-encode set (query set + `?`, `^`, backtick, `{`, `}`):
     * space, `"`, `#`, `<`, `>`, `?`, `^`, backtick, `{`, `}`. (`'` is
     * deliberately ABSENT: WHATWG leaves it raw in paths — encoding it
     * here would diverge. Same for `|`, `[`, `]`.) Controls and non-ASCII
     * bytes are appended by encodeRawWhatwgSet. Each entry is pinned
     * against the real workerd parser by a wire fixture + the per-fixture
     * `new URL()` sanity check in test/sdk/php-wire-compat.test.ts.
     */
    private const PATH_ENCODE_SET = ' "#<>?^`{}';

    /**
     * Printable ASCII the WHATWG serializer percent-encodes in a http(s)
     * QUERY (the "special-query" set): space, `"`, `#`, `<`, `>`, `'`.
     * The `'` entry is the one PSR-7 implementations reliably leave raw —
     * the exact divergence this normalization exists to close.
     */
    private const QUERY_ENCODE_SET = ' "#<>\'';

    /** Origin (and optional path prefix), stored with any trailing "/" removed. */
    private readonly string $baseUrl;

    /** 429 retry policy for {@see send()}; never applied to {@see createSignedRequest()}. */
    private readonly RetryPolicy $retry;

    /**
     * Testing seam — sleep for N seconds (a float). Production splits the value
     * into sleep() for whole seconds and usleep() for the remainder; see the
     * constructor for why a bare usleep() is wrong at the default 60s backoff.
     *
     * @var \Closure(float): void
     */
    private readonly \Closure $sleep;

    /**
     * Testing seam — a random number in [0, 1), used only for retry jitter.
     *
     * @var \Closure(): float
     */
    private readonly \Closure $random;

    /**
     * The retry parameters are APPENDED after the existing ones, so every
     * positional caller written before issue #134 keeps working unchanged.
     *
     * @param RetryPolicy|null           $retry  429 retry policy; null uses the defaults.
     *                                           Pass {@see RetryPolicy::disabled()} to opt out.
     * @param (\Closure(float): void)|null $sleep  testing seam; production leaves it null
     * @param (\Closure(): float)|null     $random testing seam; production leaves it null
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $baseUrl,
        private readonly string $appId,
        private readonly string $keyId,
        #[\SensitiveParameter]
        private readonly string $secret,
        ?RetryPolicy $retry = null,
        ?\Closure $sleep = null,
        ?\Closure $random = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->retry = $retry ?? new RetryPolicy();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            // Seconds on the seam in all three SDKs; the split below is the
            // PHP-side conversion, not part of the contract.
            //
            // NOT a bare usleep(). PHP's manual warns that values above
            // 1_000_000 microseconds "may not be supported by the operating
            // system" — and this is reachable with DEFAULT configuration, not
            // some exotic policy: the runtime advertises `Retry-After: 60`, so
            // the ordinary rate-limit path lands on usleep(60_000_000). Where
            // that is unsupported the call returns without waiting, and the
            // client immediately re-signs and re-sends INTO the same rate
            // limit — burning a fresh nonce per pass and turning the backoff
            // into a hot loop against a runtime already asking it to slow down.
            //
            // sleep() takes whole seconds and usleep() the sub-second
            // remainder, so neither is ever handed a value outside its
            // documented range.
            if ($seconds <= 0) {
                return;
            }
            $whole = (int) floor($seconds);
            $micro = (int) round(($seconds - $whole) * 1_000_000);
            // round() can carry the remainder up to a full second (e.g. 1.9999995).
            if ($micro >= 1_000_000) {
                $whole++;
                $micro -= 1_000_000;
            }
            if ($whole > 0) {
                sleep($whole);
            }
            if ($micro > 0) {
                usleep($micro);
            }
        };
        // mt_getrandmax() + 1 as the divisor, so the range is [0, 1) — matching
        // JS `Math.random()` and Python `random.random()` exactly.
        $this->random = $random ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Build and sign a request WITHOUT sending it — for callers with their
     * own PSR-18 client. Send the returned request AS-IS: its body stream
     * holds the exact bytes the signature was computed over, and
     * re-serializing the original value yourself may produce different bytes
     * and a 401.
     *
     * Body handling:
     *  - string           → sent verbatim, byte-for-byte (you already serialized)
     *  - array / \JsonSerializable → json_encode'd exactly ONCE; that string is
     *                       both what is signed and what is sent
     *  - null, or any body on GET/HEAD/DELETE → no body; the EMPTY body is
     *                       signed (the runtime hashes an empty body there)
     *
     * @param string                                          $path    must start with "/", e.g. "/v1/operations/execute"
     * @param string|array<array-key, mixed>|\JsonSerializable|null $body
     * @param array<string, string>                           $headers extra headers; the five X-IR-* headers always win
     *
     * @throws \InvalidArgumentException when $path does not start with "/"
     * @throws \JsonException            when an array|\JsonSerializable body fails to encode
     */
    public function createSignedRequest(
        string $method,
        string $path,
        string|array|\JsonSerializable|null $body = null,
        array $headers = [],
    ): RequestInterface {
        // The SIGNER's canonicalizer, not strtoupper(): the NO_BODY_METHODS
        // decision below and the method inside the canonical payload must be
        // the same string, and each SDK's native casing function disagrees
        // with the others on non-ASCII input (see Signer::canonicalizeMethod).
        $method = Signer::canonicalizeMethod($method);

        return $this->buildSignedRequest($method, $path, $this->normalizeBody($method, $body), $headers);
    }

    /**
     * Sign ONE request over an already-canonicalized method and an already
     * normalized body. Split out of {@see createSignedRequest()} so that
     * {@see send()} can normalize the body ONCE and then re-sign it per retry
     * attempt: each call mints a fresh nonce + timestamp (the whole point of
     * the nonce-burn contract) while the bytes stay identical.
     *
     * @param string                $method     already through {@see Signer::canonicalizeMethod()}
     * @param string                $bodyBytes  already through {@see normalizeBody()}; null = no body
     * @param array<string, string> $headers
     *
     * @throws \InvalidArgumentException when $path does not start with "/"
     */
    private function buildSignedRequest(
        string $method,
        string $path,
        ?string $bodyBytes,
        array $headers,
    ): RequestInterface {
        if (! str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'path must start with "/" (got "%s")',
                $path,
            ));
        }
        // Checked on the RAW input: PSR-7 factories may percent-encode "\"
        // before the URI-level checks below could see it, and the WHATWG
        // parser on the verifier side rewrites a raw "\" to "/" — but ONLY
        // in the PATH. In the query a backslash survives raw end-to-end
        // (e.g. ?filter=C:\logs is legitimate), so scope the rejection to
        // backslashes before the first "?".
        $queryStart = strpos($path, '?');
        $pathPart = $queryStart === false ? $path : substr($path, 0, $queryStart);
        if (str_contains($pathPart, '\\')) {
            throw new \InvalidArgumentException(
                'path must not contain "\\" before the query string (did you mean "/"? the runtime URL parser rewrites a path "\\" to "/")',
            );
        }

        $request = $this->requestFactory->createRequest($method, $this->baseUrl . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($bodyBytes !== null) {
            if (! $request->hasHeader('Content-Type')) {
                $request = $request->withHeader('Content-Type', 'application/json');
            }
            $request = $request->withBody($this->streamFactory->createStream($bodyBytes));
        }

        // Sign what the VERIFIER derives: the runtime rebuilds the signed
        // string via WHATWG `new URL(url).pathname + search`, and WHATWG
        // percent-encodes some characters that PSR-7 implementations leave
        // raw (e.g. "'" in a query). Normalize the final URI to the WHATWG
        // serialization and write it BACK onto the request, so the wire
        // bytes, the signed bytes, and the verifier's derivation all agree.
        $uri = $request->getUri();
        self::assertPathSegmentsSafe($uri->getPath());
        $encPath = self::encodeRawWhatwgSet($uri->getPath(), self::PATH_ENCODE_SET);
        $encQuery = self::encodeRawWhatwgSet($uri->getQuery(), self::QUERY_ENCODE_SET);
        if ($encPath !== $uri->getPath() || $encQuery !== $uri->getQuery()) {
            $request = $request->withUri($uri->withPath($encPath)->withQuery($encQuery));
        }
        $uri = $request->getUri();
        $query = $uri->getQuery();
        $pathWithQuery = $uri->getPath() . ($query !== '' ? '?' . $query : '');

        $signed = Signer::signRequest(
            $this->appId,
            $this->keyId,
            $this->secret,
            $method,
            $pathWithQuery,
            $bodyBytes ?? '',
        );

        // X-IR-* headers are authoritative — set them LAST so caller-supplied
        // headers can never shadow the signature.
        foreach ($signed->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Build, sign, and send a request via the injected PSR-18 client, retrying
     * a 429 per the {@see RetryPolicy}.
     *
     * Every attempt is a FRESHLY SIGNED request — new nonce, new timestamp —
     * over byte-identical body bytes. See the nonce-burn note in this class's
     * doc block for why a resend of the same bytes cannot work.
     *
     * When attempts are exhausted the final 429 is RETURNED, not thrown: the
     * caller must be able to read the real response, its problem+json body
     * (`type`, `detail`) and its `Retry-After`.
     *
     * @param string                                          $path    must start with "/", e.g. "/v1/operations/execute"
     * @param string|array<array-key, mixed>|\JsonSerializable|null $body
     * @param array<string, string>                           $headers extra headers; the five X-IR-* headers always win
     *
     * @throws \InvalidArgumentException  when $path does not start with "/"
     * @throws \JsonException             when an array|\JsonSerializable body fails to encode
     * @throws ClientExceptionInterface   on transport failure
     */
    public function send(
        string $method,
        string $path,
        string|array|\JsonSerializable|null $body = null,
        array $headers = [],
    ): ResponseInterface {
        $method = Signer::canonicalizeMethod($method);
        // Normalized ONCE, before the retry loop: every attempt must sign and
        // send the SAME bytes. Re-encoding per attempt would re-run
        // \JsonSerializable::jsonSerialize() — caller code that is under no
        // obligation to be pure — so attempt 2 could send a different document
        // from the one attempt 1 signed. Only the SIGNING repeats.
        $bodyBytes = $this->normalizeBody($method, $body);

        for ($attempt = 1;; $attempt++) {
            $response = $this->httpClient->sendRequest(
                $this->buildSignedRequest($method, $path, $bodyBytes, $headers),
            );
            // Only the status 429 is retried — never a transport exception
            // (those propagate), never a 5xx. A 429 is rejected before ANY
            // handler runs (Layer 1 pre-auth; Layer 2 post-auth but still ahead
            // of provider-permissions, tenant-scope and the route), so it is
            // guaranteed to carry NO business side effect: re-issuing is safe
            // even for a non-idempotent POST such as quickbooks.invoice.create.
            // A 5xx offers no such guarantee — the write may have landed — so
            // it is handed back untouched.
            if ($response->getStatusCode() !== self::RETRY_STATUS || $attempt >= $this->retry->maxAttempts) {
                return $response;
            }

            $seconds = $this->retryDelaySeconds($response->getHeaderLine('Retry-After'), $attempt);
            // Release the 429 we are about to discard. For a buffered PSR-7
            // body this just frees the buffer; for a streamed one it detaches
            // the underlying resource, which is what returns the connection to
            // the pool (the PSR-7 analogue of cancelling a fetch Response body).
            $response->getBody()->close();
            ($this->sleep)($seconds);
        }
    }

    /**
     * How long to wait before the next attempt, given the 429's `Retry-After`
     * header value and the 1-based number of the attempt that was just refused.
     *
     * Jitter is ADDITIVE ON TOP of the delay, never a fraction of it. The
     * runbook's contract (docs/runbooks/inbound-rate-limits.md §4) is "wait AT
     * LEAST `Retry-After`, plus jitter": full jitter (random() * d) would retry
     * BEFORE the window resets and burn another nonce for a guaranteed second
     * 429, while the jitter still exists to stop a fleet re-flooding the
     * identical instant.
     */
    private function retryDelaySeconds(string $retryAfter, int $attempt): float
    {
        $header = trim($retryAfter, self::ASCII_WHITESPACE);
        $delay = preg_match(self::RETRY_AFTER_DELAY_SECONDS, $header) === 1
            ? (float) $header
            // 2^(n-1) with n 1-based: 1x, 2x, 4x … the base.
            : $this->retry->baseDelaySeconds * 2 ** ($attempt - 1);
        $capped = min($delay, $this->retry->maxDelaySeconds);

        return $capped + ($this->random)() * $this->retry->jitterFraction * $capped;
    }

    /**
     * Normalize the caller's body to the exact bytes to sign AND send, or
     * null for the no-body branch (GET/HEAD/DELETE, or no body given).
     *
     * A string body is the caller's own serialization — passed through
     * verbatim, never re-encoded. Arrays and \JsonSerializable are
     * json_encode'd exactly once; PHP's default "/"-escaping and
     * unicode-escaping are harmless because we sign what we send.
     *
     * @param string|array<array-key, mixed>|\JsonSerializable|null $body
     *
     * @throws \JsonException
     */
    private function normalizeBody(string $method, string|array|\JsonSerializable|null $body): ?string
    {
        if (in_array($method, self::NO_BODY_METHODS, true) || $body === null) {
            return null;
        }
        if (is_string($body)) {
            return $body;
        }

        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * Byte-wise percent-encode every RAW occurrence in $component of a
     * character the WHATWG URL serializer would encode in that component.
     * Existing %XX sequences pass through untouched (WHATWG neither
     * validates nor re-encodes them). Byte-wise %XX over the UTF-8 string
     * is equivalent to WHATWG's code-point UTF-8 percent-encoding, and
     * rawurlencode emits the same uppercase hex.
     */
    private static function encodeRawWhatwgSet(string $component, string $printableSet): string
    {
        // No /u modifier on purpose: operate on raw bytes so invalid UTF-8
        // cannot break the match. \x00-\x1f + \x7f-\xff = controls, DEL,
        // and every non-ASCII byte (the WHATWG "C0 control" encode base).
        $pattern = '/[' . preg_quote($printableSet, '/') . '\x00-\x1f\x7f-\xff]/';

        return (string) preg_replace_callback(
            $pattern,
            static fn(array $m): string => rawurlencode($m[0]),
            $component,
        );
    }

    /**
     * Reject "." / ".." path segments (incl. "%2e" spellings,
     * case-insensitive): the verifier's WHATWG `new URL(...)` COLLAPSES
     * them rather than percent-encoding, so no signed string can
     * round-trip. Failing loudly here beats an undiagnosable uniform 401.
     * (Raw "\" is rejected earlier, on the caller's input, before a PSR-7
     * factory can percent-encode it out of sight.)
     */
    private static function assertPathSegmentsSafe(string $path): void
    {
        foreach (explode('/', $path) as $segment) {
            $normalized = str_ireplace('%2e', '.', $segment);
            if ($normalized === '.' || $normalized === '..') {
                throw new \InvalidArgumentException(sprintf(
                    'path must not contain "." or ".." segments (the runtime URL parser collapses them; got segment "%s")',
                    $segment,
                ));
            }
        }
    }

    /**
     * Mask the secret in var_dump()/print_r() output.
     *
     * (var_export() bypasses __debugInfo by design — do not var_export the
     * client.)
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'httpClient' => $this->httpClient,
            'requestFactory' => $this->requestFactory,
            'streamFactory' => $this->streamFactory,
            'baseUrl' => $this->baseUrl,
            'appId' => $this->appId,
            'keyId' => $this->keyId,
            'secret' => '***',
            'retry' => $this->retry,
            'sleep' => $this->sleep,
            'random' => $this->random,
        ];
    }

    /**
     * The client holds a service-key secret and must never be serialized —
     * a serialized copy would persist the plaintext outside the process.
     *
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            'Ezee\IntegrationRuntime\Client must not be serialized: it holds a service-key secret. '
            . 'Construct a fresh client where you need one instead.',
        );
    }
}
