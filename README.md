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

This package is **not published to Packagist**. Today, the supported consumption
path is an approved local checkout of this private repository plus a Composer
[`path`](https://getcomposer.org/doc/05-repositories.md#path) repository. Do not
point a Composer `vcs` repository at the monorepo root: this package's
`composer.json` is in `sdks/php`, not at that root.

In the consuming PHP application's `composer.json`, add:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../Integration-Runtime-Engine/sdks/php",
            "options": {
                "symlink": false,
                "versions": {
                    "ezee/integration-runtime-sdk": "dev-local"
                }
            }
        }
    ]
}
```

The path is relative to the consuming application's `composer.json`; use the
actual approved checkout location. `dev-local` is only a version label for that
checkout, not a published release. `symlink: false` makes Composer mirror the
package into the consumer's `vendor/` directory, so the installed copy cannot
silently depend on `sdks/php/vendor` or another monorepo autoloader.

The checkout itself is part of the dependency pin. Use a dedicated, clean
checkout at an explicitly approved full commit SHA, record that SHA in the
consumer's build/deployment configuration, and verify it before Composer runs.
A path repository's `dist.reference` in `composer.lock` identifies what was
resolved; it does **not** snapshot the files at `url`. If that directory moves to
a different commit, a later `composer install` can mirror different SDK bytes
under the old lock entry.

Add and lock the dependencies from the consuming application. `composer require`
works whether or not the application already has a lockfile:

```bash
composer require \
  'ezee/integration-runtime-sdk:dev-local' \
  'guzzlehttp/guzzle:^7.15'
```

Commit the resulting consumer `composer.json` and `composer.lock`. Subsequent
builds use `composer install`, but only after checking out the recorded SDK
commit cleanly at the configured path.

The SDK requires the PSR-18, PSR-17, and PSR-7 interfaces but deliberately does
not choose their implementations. The explicit Guzzle dependency above supplies
the PSR-18 client and PSR-17/PSR-7 factory used in the example below. If your
application already supplies different compliant implementations, require those
instead.

The SDK declares **PHP >= 8.2**. CI installs it from a separate clean project on
PHP 8.2 with the lowest allowed dependency versions, then verifies autoloading
and a synthetic signed request over a mocked transport. The SDK's own
`composer.lock` makes its development and CI toolchain reproducible; Composer
does not apply that lockfile or the SDK's `require-dev` packages to consumers.
The consuming application's lockfile is authoritative for registry packages;
the separately recorded, clean SDK checkout commit is authoritative for this
path package.

PHP 8.2 is the declared and tested floor, not a claim about the ERP application's
current runtime. Confirm the consuming application's PHP version separately;
do not silently lower or raise this package requirement to match an unverified
environment.

Provide `IR_SERVICE_KEY_SECRET` to the backend process through its secret
manager or deployment environment. Never commit a real secret in
`composer.json`, source, an example `.env`, or a shell script. The clean-consumer
CI smoke uses a public synthetic test key and a mocked PSR-18 transport; it makes
no runtime or provider request.

## Usage

For a full, credential-free connect-session → OAuth handoff → native QuickBooks
Invoice CREATE → job-polling walkthrough, see the
[`examples/quickbooks-invoice`](examples/quickbooks-invoice/) example. It runs
against an in-process mock transport by default and performs no network or
provider write.

For the provider-agnostic bounded result returned by job polling, see
[`examples/job-result`](examples/job-result/). It demonstrates tolerant
`none`/`available` handling with an in-process mock and leaves the generic
signed transport unchanged.

For tenant-scoped connection discovery and authoritative status reconciliation,
including `connected`, `reconnect_required`, and `disconnected`, see the
[`examples/connection-status`](examples/connection-status/) example. Its local
fixture demonstrates why a product backend must verify a browser callback hint
with `GET /v1/connections/{id}` before changing product behavior.

For Invoice creates, derive `idempotency_key` from the stable business event and
operation—not `recipe_version`. Once a key has been issued, preserve it exactly,
including a historical `:v1`, `:v2`, or `:v3` suffix. Never move a queued or
historical v1/v2 attempt to v3 by changing its key, payload, or recipe version;
an ambiguous attempt requires operator reconciliation.

With Guzzle (which implements PSR-18 and PSR-17):

```php
use Ezee\IntegrationRuntime\Client;

$client = new Client(
    new \GuzzleHttp\Client(),
    new \GuzzleHttp\Psr7\HttpFactory(),   // PSR-17 request factory
    new \GuzzleHttp\Psr7\HttpFactory(),   // PSR-17 stream factory
    'https://staging.integrationengine.ca',
    'app_erp',                            // X-IR-App-Id
    'key_primary',                        // X-IR-Key-Id
    // The base64url secret shown ONCE when the key was created (see Security).
    getenv('IR_SERVICE_KEY_SECRET') ?: throw new \RuntimeException('missing secret'),
);

// Sign + send. The five X-IR-* headers are added automatically.
$response = $client->send('POST', '/v1/operations/execute', [
    'tenant_id' => 'tenant_runtime_123',
    'provider_key' => 'quickbooks',
    'connection_id' => 'conn_quickbooks_123',
    'recipe_key' => 'erp.quickbooks.invoice.created',
    'recipe_version' => 3,
    'trigger_type' => 'erp.invoice.created',
    'object_type' => 'invoice',
    'internal_object_id' => 'erp_invoice_00042',
    'idempotency_key' => 'erp_invoice_00042:quickbooks:create',
    'payload' => [
        'invoice' => [
            'CustomerRef' => ['value' => '123'],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line' => [[
                'Amount' => 100,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => '456'],
                    'TaxCodeRef' => ['value' => 'TAX'],
                    'Qty' => 1,
                    'UnitPrice' => 100,
                ],
            ]],
        ],
        'tax_declarations' => [['line_index' => 0, 'treatment' => 'taxable']],
    ],
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

### Responses are returned unparsed

The SDK hands you the transport's response object untouched — it never decodes a
byte of the body. That is deliberate: the runtime's public enums are declared
**extensible** (`docs/API-STABILITY.md` §4.2), so any of them may gain a member
in a minor release, and a validating client would start throwing on a response
the server considers valid.

The obligation therefore transfers to you: **parse tolerantly.** Treat an
unrecognised `status`, `mapping_source` or warning `code` as an unknown value to
pass through or ignore, never as an error. Do not exhaustively `switch` on a
member set without a default branch.

### Values the SDK refuses to sign

These are rejected LOCALLY, at the call site, with an error naming the actual
problem — because the verifier answers a uniform 401 for every one of them and
can never tell you which:

| value | requirement |
| --- | --- |
| nonce | no leading or trailing space, tab, CR or LF; visible ASCII; 22–64 UTF-16 code units |
| timestamp | 1–15 ASCII digits (unix seconds) |
| app id / key id | visible ASCII |

The nonce whitespace rule is the one that bites in practice. A nonce is part of
the signed payload AND travels as an HTTP header value, and header encoding
**strips** surrounding whitespace *after* the signature is computed — so a nonce
read out of a file or an env var (`$(cat nonce.txt)`, a `.env` line, a mounted
secret) is signed with its trailing newline and sent without it. Every such
request 401s, permanently, with nothing in any log naming the cause.

You only hit these if you supply your own nonce or timestamp; the generated ones
are always valid.

### Retrying 429s

`send()` retries a `429` for you, and **re-signs every attempt**. That is not an
optimization — it is the contract.

The runtime rate-limits on two layers:

| layer | problem `code` | when | nonce |
| --- | --- | --- | --- |
| 1 | `rate_limit.shield_shed` | before HMAC auth runs | untouched |
| 2 | `rate_limit.quota_exceeded` | **after** HMAC auth | **already consumed** |

The discriminator is **`code`**, not `type`. `problemResponse` emits the raw
value in `body.code` and sets `body.type` to `urn:ir:error:${code}`
(`src/http/problem.ts`), so a caller comparing `body.type` against
`rate_limit.quota_exceeded` never matches and silently cannot tell the two
layers apart. Match on `body.code`, or on the full URN
`urn:ir:error:rate_limit.quota_exceeded`.


A Layer 2 `429` is returned *after* the runtime has inserted your request's
nonce. Replaying the identical signed bytes therefore answers **`401
hmac.nonce_reused`** — not a retry, not a success. So each attempt builds and
signs a completely fresh request: **new `X-IR-Nonce`, new `X-IR-Timestamp`, new
`X-IR-Signature`**, over byte-identical body bytes (the body is serialized once,
before the first attempt — so a `\JsonSerializable` whose `jsonSerialize()` is
not pure cannot make attempt 2 send a different document). One code path covers
both layers, because re-signing is a strict superset of what Layer 1 needs — a
fresh nonce is always valid.

```php
use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\RetryPolicy;

$client = new Client(
    $httpClient, $requestFactory, $streamFactory,
    'https://staging.integrationengine.ca',
    'app_erp', 'key_primary', $secret,
    // Defaults shown; pass null (or omit) to get exactly this.
    new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 1.0, maxDelaySeconds: 60.0, jitterFraction: 0.25),
);

// Opt out: `maxAttempts: 1` is the canonical opt-out — one attempt, no sleep.
$noRetry = new Client(/* … */, RetryPolicy::disabled());
```

| field | default | meaning |
| --- | --- | --- |
| `maxAttempts` | `3` | **total** attempts including the first; `1` disables retry |
| `baseDelaySeconds` | `1.0` | backoff base when `Retry-After` is absent/unparseable |
| `maxDelaySeconds` | `60.0` | cap on the delay BEFORE jitter; the longest single sleep is `maxDelaySeconds * (1 + jitterFraction)` (75s at the defaults) |
| `jitterFraction` | `0.25` | additive jitter fraction |

On a `429` at attempt *n* (1-based): take `Retry-After` if it matches
`^\d{1,9}$`, else `baseDelaySeconds * 2^(n-1)`; cap it at `maxDelaySeconds`;
then sleep that value **plus** `random() * jitterFraction * delay`.

- **Jitter is added on top of `Retry-After`, never a fraction of it.** The
  contract is "wait *at least* `Retry-After`, plus jitter" — full jitter would
  retry before the window resets and burn another nonce for a guaranteed second
  `429`.
- **Only the status `429` is retried** — never a `ClientExceptionInterface`
  (those propagate), never a `5xx`. A `429` is rejected before any handler runs,
  so it carries no business side effect and is safe to re-issue even for a
  non-idempotent `POST`. A `5xx` gives no such guarantee.
- **`Retry-After` is read as delay-seconds only.** The RFC 9110 HTTP-date form
  falls back to exponential backoff rather than adding three per-language date
  parsers to the cross-SDK contract; the runtime only ever emits delay-seconds.
- **When attempts are exhausted the final `429` is returned, not thrown** — so
  you can read its `problem+json` body and `Retry-After`.
- `createSignedRequest()` never retries (it sends nothing). Call it again to get
  a fresh nonce.

The constructor also accepts `?\Closure $sleep` (seconds → void) and
`?\Closure $random` (→ float in `[0, 1)`) as testing seams, appended after
`$retry` so existing positional callers are unaffected; production leaves both
null. An out-of-range `RetryPolicy` field throws `\InvalidArgumentException` at
construction, not mid-incident.

### Signing without sending

If you drive your own PSR-18 client, build the fully signed PSR-7 request
without sending it:

```php
$request = $client->createSignedRequest(
    'GET',
    '/v1/sync-jobs?tenant_id=tenant_runtime_123',
);
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

### The `$path` argument

`$path` is a **path + query string, never a URL**. It must start with `/`; it
has no fragment, no scheme and no host.

The SDK derives ONE string from it, writes that back onto the PSR-7 request, and
then signs it — so the bytes hashed and the bytes on the wire cannot drift:

| input | what happens | why |
| --- | --- | --- |
| `\` before the first `?` | **throws** | the runtime's URL parser rewrites it to `/`, so the request would succeed against a *different* resource than you wrote. A `\` inside a query (`?filter=C:\logs`) is fine. |
| a `.` / `..` segment (any `%2e` spelling) | **throws** | the parser collapses it — the same silent re-targeting. |
| a raw `#` | encoded to `%23` (path and query) | a `#` acting as a fragment delimiter can only *delete* your data: everything after it, `?tenant_id=…` included, never reaches the server. An already-written `%23` is left as-is. |
| `%xx` escapes | hex upper-cased (`%7e` → `%7E`) | **this is issue #194.** ext-curl upper-cases path escapes *after* the SDK has signed them, so signing your spelling is a permanent, unrecoverable 401 — on curl only, which is why it can pass in dev and fail in production. |

`%7E` is **not** decoded to `~` and a lone `%` is left for the PSR-7 factory to
spell — both were measured to travel unchanged on curl *and* the stream handler,
so normalizing them here would create the very mismatch the belt prevents.

**[`../PATH-CONTRACT.md`](../PATH-CONTRACT.md) has the full measured table**
across all three SDKs. One caveat specific to PHP: with the default Guzzle stack
the bytes that reach the server for `%`, `[`, `]`, `|` and a query `\` are
percent-encoded — but by `guzzlehttp/psr7`'s `Uri`, **not** by this SDK, so a
different PSR-7 factory may spell them differently. `signed == sent` holds
either way, because the normalization belt runs *after* the factory.

### Supported HTTP methods

The method is matched case-insensitively against a closed allowlist — the nine
standard methods `CONNECT`, `DELETE`, `GET`, `HEAD`, `OPTIONS`, `PATCH`,
`POST`, `PUT`, `TRACE` (RFC 9110 §9.3 plus PATCH, RFC 5789), exposed as
`Signer::HTTP_METHODS` — and the matched constant is what goes into the signed
payload. Anything else throws `InvalidMethodException` (an
`\InvalidArgumentException`) locally instead of being signed.

The allowlist exists because the canonical payload embeds the method as text
and each SDK's native upper-casing disagrees with the others on non-ASCII
input: `strtoupper` is ASCII-only while the JS verifier and the Python SDK
apply the Unicode case mapping — and those two disagree with *each other* on
~95 code points depending on each runtime's Unicode version. Looking up a
constant removes case mapping from the signing contract entirely. Restricting
the set costs nothing in practice: the runtime answers 404/405 for a method it
does not route, which is diagnosable, unlike the uniform 401 a
mis-canonicalized method produces.

## Security

- **The secret is the base64url string shown exactly once at key creation** in
  the service-keys console (or via the create/rotate service-key API). It is
  the only time the plaintext is exposed — store it in your secret manager,
  not in source. If you lose it, rotate the key.
- **An empty, whitespace-only, or non-base64url secret is rejected locally**
  (`InvalidSecretException`), at the call site, with a message naming the
  problem. An unset `IR_SERVICE_KEY_SECRET` is the most likely way to
  misconfigure the SDK, and the runtime cannot tell you what went wrong — it
  answers a uniform 401 by design.
- The `Client` holds the secret only as a private readonly property: **no
  getter, masked in `var_dump()`/`print_r()` output (`__debugInfo`), and
  `serialize($client)` throws** — a serialized copy would persist the
  plaintext outside the process. It is never logged and never placed in a URL.
- Requests are signed with a per-request random nonce and a unix-seconds
  timestamp. The runtime enforces a ±5-minute timestamp window and rejects
  nonce reuse, so keep client clocks roughly in sync and never resend a
  request with the same nonce — retry by calling `send()` /
  `createSignedRequest()` again to re-sign with a fresh nonce. (`send()`'s
  built-in `429` retry already re-signs every attempt; see
  [Retrying 429s](#retrying-429s).)

## What this SDK does NOT do (yet)

- No typed per-endpoint methods — this is the signing core + a thin PSR-18
  client. A generated per-endpoint client is a follow-up.
- Retries cover `429` only — no retry of transport exceptions or `5xx`,
  deliberately (see [Retrying 429s](#retrying-429s)).

## Development

```bash
composer test        # phpunit
composer analyse     # phpstan (level max)
composer lint        # pint --test
composer format      # pint
composer fixtures    # regenerate wire fixtures (tools/generate-wire-fixtures.php)
```

### Why `composer analyse` passes `--memory-limit=512M`

`composer.json` cannot carry a comment, so the measurement lives here.

PHPStan at `level: max` over `src` + `tests` + `tools` needs **more than 128 MB
on a cold cache**. Measured on this package:

| `memory_limit` | result |
| --- | --- |
| `128M` | exit 1, 3/3 runs: `Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M` |
| `192M` | exit 0 |

**This only reproduces with a genuinely cold cache** — which is why it looks
like a phantom if you try to confirm it casually. `phpstan clear-result-cache`
is *not* enough: it clears the result cache and leaves the rest behind, so the
next run stays under 128 MB and the crash appears to have been imaginary. Clear
the whole directory instead:

```bash
rm -rf "$(php -r 'echo sys_get_temp_dir();')/phpstan"
```

CI hits exactly that state on every cache miss, so the cold path is the one the
gate has to survive.

`512M` rather than the measured `192M` floor: the floor is a measurement of
*today's* file set at *today's* PHPStan version, and both grow. A limit sitting
a few megabytes above the crash point converts an ordinary "we added a file"
into a red gate with a misleading error, so the flag buys headroom (~2.6×)
rather than tracking the floor.

The tradeoff, stated rather than stumbled into: if the runner's CLI ini already
sets `memory_limit=-1` (unlimited), this flag **lowers** the ceiling to 512 MB.
That is deliberate — an unbounded analyser on a shared runner degrades into
swap instead of failing — and 512 MB is still ~2.6× the measured need, but it
does mean the flag is a cap in that environment, not only a raise.
