# PHP backend signed-read starter

This source example makes one real HTTP GET with the existing SDK signer.
It has no mock mode and does not execute a recipe or call a provider.
Use PHP 8.2+ with cURL and the repository's existing Composer dependencies
(including its Guzzle development transport). From the repository root:

```sh
composer --working-dir=sdks/php install --no-interaction
php sdks/php/examples/signed-read/run.php
```

The example also ships in the installed package. From an application that
requires `ezee/integration-runtime-sdk` (directly or through
`ezee/laravel-integration-runtime`) and has Guzzle (every Laravel app does),
run it against the application's own `vendor/autoload.php`. This needs SDK
1.3.4 or later (earlier versions only look in the SDK's own `vendor/`):

```sh
composer require ezee/integration-runtime-sdk:^1.3.4 -W
php vendor/ezee/integration-runtime-sdk/examples/signed-read/run.php
```

Privately inject `IR_BASE_URL`, `IR_APP_ID`, `IR_KEY_ID`,
`IR_SERVICE_KEY_SECRET` and `IR_TENANT_ID` into the backend environment.
Keep the secret out of browser code, prompts, committed files and logs.
Optional `IR_JOB_ID` chooses one exact retained job; otherwise the request
lists at most one job. No SDK publication is required or implied.

Only the known `https://api.integrationengine.ca` and
`https://staging.integrationengine.ca` origins are accepted. Explicit
`IR_ALLOW_LOCAL=1` permits loopback (`localhost`, `127.0.0.1`, `[::1]`) with a
chosen port. Userinfo, URL query/fragment, path prefixes and rewritten origins
are refused. App IDs allow ASCII letters/digits/dot/underscore/hyphen (128);
tenant/job IDs allow ASCII letters/digits/underscore/hyphen (200); key IDs
allow ASCII letters/digits/underscore/hyphen (128). Dot segments are refused.

The cURL transport has a 10-second transfer timeout, no redirect following,
no automatic retry and no environment proxy lookup. Its sink rejects response
bytes beyond 64 KiB; a published JSON-object result is bounded to 32 KiB.
Rerun explicitly for a fresh signed attempt.

The controlled JSON receipt prints actual HTTP status, elapsed milliseconds,
actual response request/correlation IDs, a bounded code only from valid
ProblemDetails, and selected public job fields. It omits raw errors, signing
headers, key IDs and unrelated fields. An empty list is a valid read with no
job proof. Unknown lifecycle values and null results do not establish failure
or completion; list rows are metadata only. Unknown publication is preserved
with its result hidden. `unknown` means no response was received; `unverified`
means the response cannot establish the expected runtime shape. Exit 0 means
an observed scoped read, 1 means unverified/refused/unknown, and 2 means invalid
configuration or missing repository dependencies.

Focused tests inject an offline PSR-18 transport. They do not validate live
credentials, runtime configuration, readiness or provider behavior. The older
mock journey examples are separate and are not used by this entrypoint.
