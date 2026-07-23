<?php

declare(strict_types=1);

/*
 * Deterministic wire-fixture generator for the PHP SDK ↔ runtime
 * wire-compatibility proof (GitHub issue #94, PHP slice).
 *
 * Signs a small set of /v1 requests with the PHP Signer using FIXED
 * timestamps and nonces plus the published ADR-012 test secret (bytes
 * 0..31 — never a real key), then writes sdks/php/fixtures/wire-fixtures.json.
 * The committed file is replayed through the REAL serviceAuth middleware by
 * test/sdk/php-wire-compat.test.ts (root vitest suite), which pins the
 * middleware clock at `nowSeconds` via the production ServiceAuthSeam — no
 * fake timers, no PHP needed at runtime-test time.
 *
 * Deterministic by construction: running it twice yields byte-identical
 * output. CI (.github/workflows/sdk-php-ci.yml) regenerates and diffs the
 * committed file to catch signer/fixture drift. Regenerate locally with
 * `composer fixtures`.
 *
 * No composer autoload needed — the src files are required directly so
 * `php tools/generate-wire-fixtures.php` works from a bare checkout.
 */

use Ezee\IntegrationRuntime\Signer;

require __DIR__ . '/../src/Exception/InvalidSecretException.php';
require __DIR__ . '/../src/Exception/InvalidNonceException.php';
require __DIR__ . '/../src/SignedRequest.php';
require __DIR__ . '/../src/Signer.php';

// The published ADR-012 Phase-0 TEST secret (openapi/test-vectors/
// hmac-signing.json): base64url of bytes 0..31. Published on purpose;
// never a real service-key secret.
const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
const APP_ID = 'app_erp';
const KEY_ID = 'key_primary';
// The instant the wire-compat test pins the middleware clock to (2023-11-14).
const NOW = 1700000000;

/**
 * Fixture specs. Every nonce is distinct (nonce replay is a D1 unique
 * constraint per (app_id, key_id, nonce)) and inside the runtime's [22, 64]
 * length bound. `body` is the RAW wire body (null = no body; GET/HEAD/DELETE
 * sign ''). `signedPathWithQuery` (optional, defaults to `path`) is the
 * WHATWG-normalized form the signature is computed over when it differs from
 * the wire path — the verifier derives its signed string via WHATWG
 * `new URL(...)`, which percent-encodes characters like `'` that arrive raw
 * on the wire (see Client::QUERY_ENCODE_SET).
 *
 * @var list<array{
 *     name: string,
 *     method: string,
 *     path: string,
 *     signedPathWithQuery?: string,
 *     body: string|null,
 *     timestamp: string,
 *     nonce: string,
 *     expect: 'accept'|'reject',
 * }> $specs
 */
$specs = [
    [
        // The common signed-write case: POST with a JSON body.
        'name' => 'post-json-accept',
        'method' => 'POST',
        'path' => '/v1/echo',
        'body' => '{"invoice":"INV-1","amount":100}',
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-post-json-00001',
        'expect' => 'accept',
    ],
    [
        // GET with a query string — signed over '' (the GET empty-body rule).
        'name' => 'get-query-accept',
        'method' => 'GET',
        'path' => '/v1/echo?status=running&limit=10',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-get-query-00002',
        'expect' => 'accept',
    ],
    [
        // Multi-byte UTF-8 body — proves byte-exact hashing across the
        // PHP/JS boundary. Written RAW (not re-encoded) into the fixture.
        'name' => 'post-unicode-accept',
        'method' => 'POST',
        'path' => '/v1/echo',
        'body' => '{"memo":"té st — façade ✓ 日本語"}',
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-unicode-000003',
        'expect' => 'accept',
    ],
    [
        // Correctly signed but 1h stale — outside the ±300s window when the
        // middleware clock is pinned at NOW, so serviceAuth must 401.
        'name' => 'expired-timestamp',
        'method' => 'POST',
        'path' => '/v1/echo',
        'body' => '{"amount":100}',
        'timestamp' => (string) (NOW - 3600),
        'nonce' => 'php-fixture-nonce-expired-000004',
        'expect' => 'reject',
    ],
    [
        // DELETE with a query — pins the third NO_BODY method end-to-end
        // (the runtime's readBodyBytes hashes '' for DELETE too).
        'name' => 'delete-query-accept',
        'method' => 'DELETE',
        'path' => '/v1/echo?id=42&reason=cleanup',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-delete-000005',
        'expect' => 'accept',
    ],
    [
        // A raw apostrophe in the wire query. The verifier's WHATWG parser
        // derives `%27`, so the signature is computed over the normalized
        // form — exactly what Client::createSignedRequest produces. Proves
        // the WHATWG-derivation fix against the REAL middleware.
        'name' => 'apostrophe-query-accept',
        'method' => 'GET',
        'path' => "/v1/echo?q='quoted'",
        'signedPathWithQuery' => '/v1/echo?q=%27quoted%27',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-apostrophe-06',
        'expect' => 'accept',
    ],
    // One fixture per divergent PATH percent-encode class (chars the WHATWG
    // path set encodes but HTTP layers may leave raw). Together with the
    // per-fixture `new URL()` sanity check in the wire-compat test, each
    // Client::PATH_ENCODE_SET entry is structurally validated against the
    // REAL verifier parser — a wrong set entry can no longer pass both
    // suites in agreement. (Unicode is covered by post-unicode-accept.)
    [
        'name' => 'path-space-accept',
        'method' => 'GET',
        'path' => '/v1/echo/two words',
        'signedPathWithQuery' => '/v1/echo/two%20words',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-path-space-07',
        'expect' => 'accept',
    ],
    [
        'name' => 'path-caret-accept',
        'method' => 'GET',
        'path' => '/v1/echo/a^b',
        'signedPathWithQuery' => '/v1/echo/a%5Eb',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-path-caret-08',
        'expect' => 'accept',
    ],
    [
        'name' => 'path-braces-accept',
        'method' => 'GET',
        'path' => '/v1/echo/{id}',
        'signedPathWithQuery' => '/v1/echo/%7Bid%7D',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-path-brace-09',
        'expect' => 'accept',
    ],
    [
        'name' => 'path-backtick-accept',
        'method' => 'GET',
        'path' => '/v1/echo/a`b',
        'signedPathWithQuery' => '/v1/echo/a%60b',
        'body' => null,
        'timestamp' => (string) NOW,
        'nonce' => 'php-fixture-nonce-backtick-0010',
        'expect' => 'accept',
    ],
];

$fixtures = [];
foreach ($specs as $spec) {
    $signedPathWithQuery = $spec['signedPathWithQuery'] ?? $spec['path'];
    $signed = Signer::signRequest(
        appId: APP_ID,
        keyId: KEY_ID,
        secret: SECRET,
        method: $spec['method'],
        pathWithQuery: $signedPathWithQuery,
        bodyBytes: $spec['body'] ?? '',
        timestamp: $spec['timestamp'],
        nonce: $spec['nonce'],
    );
    $fixtures[] = [
        'name' => $spec['name'],
        'method' => $spec['method'],
        'path' => $spec['path'],
        'signedPathWithQuery' => $signedPathWithQuery,
        'body' => $spec['body'],
        'headers' => $signed->headers,
        'expect' => $spec['expect'],
    ];
}

$doc = [
    '$comment' => 'GENERATED by sdks/php/tools/generate-wire-fixtures.php — deterministic '
        . '(fixed timestamps/nonces + the published TEST secret, bytes 0..31). Regenerate with '
        . '`composer fixtures`; CI diffs the committed file. Consumed by test/sdk/php-wire-compat.test.ts.',
    'secret' => SECRET,
    'appId' => APP_ID,
    'keyId' => KEY_ID,
    'nowSeconds' => NOW,
    'fixtures' => $fixtures,
];

$json = json_encode(
    $doc,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . "\n";

$outPath = __DIR__ . '/../fixtures/wire-fixtures.json';
$outDir = dirname($outPath);
if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "failed to create fixtures directory: {$outDir}\n");
    exit(1);
}
$bytes = file_put_contents($outPath, $json);
if ($bytes === false) {
    fwrite(STDERR, "failed to write {$outPath}\n");
    exit(1);
}

printf("Wrote %d wire fixtures (%d bytes) to %s\n", count($fixtures), $bytes, $outPath);
