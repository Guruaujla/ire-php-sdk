# ezee/integration-runtime-sdk

PHP SDK for making **HMAC-signed** requests to the Integration Runtime
`/v1` API, so product backends don't hand-roll the canonical-payload format or
the signature.

The signing core mirrors the runtime's verifying side
([`src/security/hmac.ts`](../../src/security/hmac.ts) +
[`src/middleware/service-auth.ts`](../../src/middleware/service-auth.ts))
**byte-for-byte**. A repo-root replay test signs a real request with this SDK
and runs it through the actual `serviceAuth` middleware to prove
wire-compatibility (see [`test/sdk/`](../../test/sdk/)).

Transport-agnostic: the signing core (`Signer`) has zero runtime dependencies,
and the `Client` speaks **PSR-18 / PSR-17 / PSR-7** — bring any compliant HTTP
client (Guzzle, Symfony HttpClient's PSR-18 adapter, ...).

## Install

This is a standalone Composer package at `sdks/php` (not part of a repo-wide
workspace).

```bash
cd sdks/php
composer install
```

Requires **PHP >= 8.2**. (Floor chosen for `readonly` classes
(`SignedRequest`) + `#[\SensitiveParameter]` — both 8.2 features; 8.1 is
EOL. Still to be confirmed against the PHP versions the product backends
actually run.)

## Usage

With Guzzle (which implements PSR-18 and PSR-17):

```php
use Ezee\IntegrationRuntime\Client;

$client = new Client(
    new \GuzzleHttp\Client(),
    new \GuzzleHttp\Psr7\HttpFactory(),   // PSR-17 request factory
    new \GuzzleHttp\Psr7\HttpFactory(),   // PSR-17 stream factory
    'https://integration-runtime.example.com',
    'app_erp',                            // X-IR-App-Id
    'key_primary',                        // X-IR-Key-Id
    // The base64url secret shown ONCE when the key was created (see Security).
    getenv('IR_SERVICE_KEY_SECRET') ?: throw new \RuntimeException('missing secret'),
);

// Sign + send. The five X-IR-* headers are added automatically.
$response = $client->send('POST', '/v1/operations/execute', [
    'operation' => 'quickbooks.customer.upsert',
    'tenant_id' => 't_123',
    'input' => [],
]);

if ($response->getStatusCode() === 401) {
    throw new \RuntimeException('signature rejected');
}
$result = json_decode((string) $response->getBody(), true);
```

`$body` may be an array or `\JsonSerializable` (JSON-serialized for you,
exactly once), a pre-encoded string (sent **verbatim**, never re-encoded), or
`null`. `Content-Type: application/json` is set by default unless you supply
your own in `$headers`.

### Signing without sending

If you drive your own PSR-18 client, build the fully signed PSR-7 request
without sending it:

```php
$request = $client->createSignedRequest('POST', '/v1/operations/execute', [
    'operation' => 'quickbooks.customer.upsert',
    'tenant_id' => 't_123',
]);
// $request carries X-IR-App-Id, X-IR-Key-Id, X-IR-Timestamp, X-IR-Nonce,
// X-IR-Signature — plus the body stream.
$response = $myPsr18Client->sendRequest($request);
```

**IMPORTANT: send the returned request AS-IS.** Its body stream holds the
exact bytes the signature was computed over — re-serializing the original
payload yourself may produce different bytes and a 401.

### GET / HEAD / DELETE have no body

The runtime hashes an **empty** body for `GET`, `HEAD`, and `DELETE`
regardless of any payload, and the SDK matches that: for these methods any
`$body` argument is ignored — the empty body is signed and no body is sent.

## Security

- **The secret is the base64url string shown exactly once at key creation** in
  the service-keys console (or via the create/rotate service-key API). It is
  the only time the plaintext is exposed — store it in your secret manager,
  not in source. If you lose it, rotate the key.
- The `Client` holds the secret only as a private readonly property: **no
  getter, masked in `var_dump()`/`print_r()` output (`__debugInfo`), and
  `serialize($client)` throws** — a serialized copy would persist the
  plaintext outside the process. It is never logged and never placed in a URL.
- Requests are signed with a per-request random nonce and a unix-seconds
  timestamp. The runtime enforces a ±5-minute timestamp window and rejects
  nonce reuse, so keep client clocks roughly in sync and never resend a
  request with the same nonce — retry by calling `send()` /
  `createSignedRequest()` again to re-sign with a fresh nonce.

## What this SDK does NOT do (yet)

- No typed per-endpoint methods — this is the signing core + a thin PSR-18
  client. A generated per-endpoint client is a follow-up.
- The Python SDK is a separate follow-up (see GitHub issue #94; the
  TypeScript SDK already ships at [`sdks/typescript`](../typescript)).

## Development

```bash
composer test        # phpunit
composer analyse     # phpstan (level max)
composer lint        # pint --test
composer format      # pint
composer fixtures    # regenerate wire fixtures (tools/generate-wire-fixtures.php)
```
