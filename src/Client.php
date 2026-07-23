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
 */
final class Client
{
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

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $baseUrl,
        private readonly string $appId,
        private readonly string $keyId,
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
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
        $method = strtoupper($method);
        $bodyBytes = $this->normalizeBody($method, $body);

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
     * Build, sign, and send a request via the injected PSR-18 client.
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
        return $this->httpClient->sendRequest(
            $this->createSignedRequest($method, $path, $body, $headers),
        );
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
