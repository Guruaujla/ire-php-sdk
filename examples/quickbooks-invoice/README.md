# QuickBooks Invoice CREATE — safe PHP journey

This runnable, mock-only example preserves the historical marker-free
QuickBooks Invoice v3 contract. Fresh live submissions require v4 with approved
`invoice_verification` totals, which this frozen mock does not supply. Use the
[current v4 request](../../README.md#usage) for new work; never upgrade an
uncertain historical request, payload or key. This mock demonstrates:

1. creating a connect session;
2. handing the returned authorization URL to a human;
3. validating the product callback result and obtaining `connection_id`;
4. submitting one native-reference Invoice CREATE;
5. polling its tenant-scoped job URL to a terminal result;
6. replaying the identical body/key and refusing a changed-payload conflict.

It is **mock-only by design**. The default transport is in-process: it makes no
network request, opens no browser, performs no OAuth exchange, and creates no
invoice. The script refuses any `IR_EXAMPLE_MODE` other than `mock`.

```bash
cd sdks/php
composer install
php examples/quickbooks-invoice/run.php
```

The script generates an ephemeral HMAC secret in memory and never prints it.
`IR_APP_ID`, `IR_KEY_ID`, and `IR_TENANT_ID` may override the visibly fake mock
identifiers; no secret environment variable is needed or read in mock mode.

## Identity and provisioning boundaries

These identifiers are intentionally distinct:

| Value | Meaning | Source in a real deployment |
| --- | --- | --- |
| `app_id` | Product application identity (`X-IR-App-Id`) | Runtime operator onboarding; the operator also configures the callback-origin allowlist and enables QuickBooks for the app. |
| `key_id` | One service-key identifier inside that app (`X-IR-Key-Id`) | Runtime operator service-key issuance. It is not the secret. |
| service-key secret | Base64url HMAC key material | Shown once during key issuance; inject from a secret manager, never source control or a browser. |
| `tenant_id` | Product tenant scope | Runtime operator tenant onboarding for the selected app. |
| `connection_id` | One projected native QuickBooks company connection | Returned by the runtime to the registered product `return_url` after human OAuth consent. Do not invent it or substitute a tenant id. |

See [`docs/runbooks/e2e-onboarding.md`](../../../../docs/runbooks/e2e-onboarding.md)
for the operator-owned app/tenant/key chain. This example does not call those
internal endpoints and does not invent production or Assist-specific values.

## Authorization is a human handoff

`POST /v1/connections/connect-session` returns an `authorize_url`. Your backend
must return or redirect that URL to the human operator; it must not fetch it as a
server-side HTTP request. After Intuit consent, the runtime processes the OAuth
callback and redirects to the registered product `return_url` with:

- `status=connected` or `status=already_connected`;
- `connection_id=<runtime connection id>`;
- the product's opaque `state`, echoed unchanged.

The current runtime failure redirect is asymmetric: it reports
`status=failed&reason=...` but does not echo the product's opaque `state`.
Accordingly, the example returns `UnverifiedAuthorizationFailure` for that
shape without consuming the pending attempt. The `reason` is only an untrusted
browser-reported hint: it cannot identify a connection, app, tenant, or write
authority. Reconcile the pending attempt or restart consent through an explicit
product flow; do not mark a connection failed from that query alone.

The example correlates the opaque state to one product-side server session,
records that session's `expires_at`, checks the established tenant context,
optionally checks the expected connection for reconnect, rejects an expired
return, and consumes the attempt once. The callback query is still a handoff
result—not an independent authoritative connection read. Never derive
app/tenant scope or financial enablement from it; the signed execute request uses
the product's established scope, and the runtime re-checks connection ownership
and connected/write-gate state. This historical walkthrough omits the signed
authoritative connection read. The current API provides
`GET /v1/connections/{id}?tenant_id=...`; use the
[connection-status example](../connection-status/) before enabling product
behavior. Do not substitute the unsupported `/v1/connections/{id}/status`
shape. A failed or ambiguous connect-session
request is surfaced to the caller; the example never creates another session
implicitly.

## Frozen Invoice contract

The request is deliberately limited to native QuickBooks Invoice CREATE:

- `recipe_key=erp.quickbooks.invoice.created`, `recipe_version=3`;
- an existing native `CustomerRef.value` from the selected company;
- an existing native `ItemRef.value` and `TaxCodeRef.value` for every top-level
  `SalesItemLineDetail`;
- `GlobalTaxCalculation=TaxExcluded`;
- exactly one explicit `tax_declarations` entry per provider sales line (there
  is no default tax treatment);
- no root `customer`/`items`, runtime marker, `Id`, `SyncToken`, `sparse`,
  `CurrencyRef`, or `ExchangeRate`.

The mock strings are labels, not defaults for a real company. Before adapting
this code, the operator must supply references read from the exact connected
QuickBooks company and review that each tax-code reference matches its declared
treatment. The runtime then performs the live sales-tax preflight and refuses
companies outside the currently qualified single-currency CAD profile. See
[`quickbooks-invoice-create-reliability.md`](../../../../docs/runbooks/quickbooks-invoice-create-reliability.md)
and
[`quickbooks-invoice-production-cutover.md`](../../../../docs/runbooks/quickbooks-invoice-production-cutover.md).

This example does not enable the financial operation, qualify a company, file a
policy attestation, deploy anything, or call QuickBooks.

## Idempotency and unknown outcomes

The idempotency key is derived from the stable ERP invoice identity and the
create operation. A permitted replay reuses the **identical body and key** while
the SDK creates a fresh signing nonce. A `409 idempotency_key_conflict` is not a
prompt to change the key: preserve the original request and reconcile it.
The same preservation rule applies if a `409` body is malformed or not a JSON
object: retain the HTTP status and original request/key, and reconcile before
any resubmission.

The SDK automatically retries only runtime `429` responses. A quota `429` can
consume the HMAC nonce but occurs before the business handler, so the SDK signs
the byte-identical body again with a fresh nonce. It does not retry transport
exceptions or `5xx` responses, because the Invoice may already have been
created. The example turns those cases into `SubmissionOutcomeUnknownException`
and explicitly forbids automatic resubmission or a new key. The same rule
applies when the runtime returns `202` but its acknowledgement is truncated,
incomplete, or carries an unsafe polling URL: the exception retains the exact
original request identity so the product can reconcile without inventing one.

Polling is a bounded GET loop with exponential backoff. A polling transport or
HTTP error, attempt-bound timeout, terminal non-success, or future unknown status
preserves the original job identity and requires continued polling or operator
reconciliation—not another Invoice submission. Every successful poll body is
also matched to the accepted `job_id` and configured app/tenant before its
status is trusted.
The accepted polling URL must contain exactly one decoded `tenant_id`; duplicate
or PHP-style array query forms are rejected as an unknown submission outcome.

## Persistence responsibility

The mock transport keeps state in memory solely to make the walkthrough
executable. A product integration must durably store, before and after the POST:

- its stable business-event/idempotency key;
- the exact submitted payload and selected `connection_id`;
- the accepted `job_id` and `polling_url`;
- the last observed status and reconciliation decision.

Runtime reservations and provider request IDs protect the runtime path, but
process memory is not a durable product-side duplicate-prevention strategy.

## Focused verification

```bash
cd sdks/php
vendor/bin/phpunit --filter QuickBooksInvoiceExample
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test examples/quickbooks-invoice tests/QuickBooksInvoiceExampleTest.php tests/QuickBooksInvoiceExampleContractTest.php
```
