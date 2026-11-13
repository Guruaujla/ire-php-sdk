# Changelog: ezee/integration-runtime-sdk

Releases are published to `Guruaujla/ire-php-sdk` as `v` tags.

## 1.7.0 (unreleased)

- `Connections::disconnect($tenantId, $connectionId, $idempotencyKey,
  $providerAccountId = null)` calls `POST /v1/connections/{id}/disconnect`. It
  reads the connection for its `provider_key`, sends the documented body, and
  returns `['provider_cleanup' => ?string]` (`customer_action_required` for a
  Xero disconnect that was local only). A refusal throws
  `RuntimeProblemException` (for example `409 oauth.lifecycle_busy` or
  `503 oauth.disconnect_retryable`: retry with the same key). Pass the
  `provider_account_id` you stored from `Accounting::providers()`; connection
  reads do not return it. When it is left out the SDK reads `providers()` and
  uses its `connection` if it is this one, and otherwise throws
  `\UnexpectedValueException` before sending a disconnect. Invalid arguments
  throw `\InvalidArgumentException` before any request.
- README and `Accounting::connectionId()` point at `disconnect()` instead of a
  hand-built signed call.
- `RuntimeProblemException::decision()` maps `oauth.disconnect_retryable` (503)
  and `oauth.lifecycle_busy` (409) to `retry_same_request` (retry once with the
  same key, then escalate; they were `stop_and_reconcile`) and
  `connection.recovery_pending` (409) to `ask_operator` (was
  `stop_and_reconcile`). The table is shared with the TypeScript and Python
  SDKs through `sdks/problem-decisions.json`
  (`tools/generate-problem-decisions.php`).

## 1.6.1

- `Jobs::get()` and `Jobs::wait()` accept the `polling_url` that
  `Accounting::submit()` (and every `Accounting` write) returns,
  `/v1/accounting/operations/{job_id}?tenant_id=…`, and read the sync job with
  the same ID in the same tenant. In 1.6.0 and earlier they threw
  `\InvalidArgumentException` for it. Any other URL (including a `.` or `..`
  job id) is still refused before a request is sent. When the job is
  terminal, call `Accounting::status()` once: that read returns the neutral
  result and links the created document for later updates.
- `Jobs::wait()` polls with the client's 429 retries off
  (`Client::withoutRetry()`), so a long `Retry-After` is waited out against
  the deadline instead of sleeping inside one request past it. A PSR-18
  network failure (`NetworkExceptionInterface`) on a read is now waited out
  like a 429 or 5xx until the deadline; when there is no successful read to
  return by then, it is rethrown. Other PSR-18 errors (a malformed request)
  are thrown at once.
- `OperationReceipt` has a new nullable `$idempotentReplay` (from
  `idempotent_replay`; `null` when the response does not carry a boolean). The
  constructor argument is optional, so existing calls are unchanged.
- README: the `Accounting` example waits with `Jobs::wait()`, and the Guzzle
  example sets `timeout` 15, `connect_timeout` 5 and `allow_redirects` false.

## 1.6.0

- Bulk onboarding for large customer and item lists (customers and items;
  QuickBooks and Xero), all on `Accounting`:
  - `startSnapshot($tenantId, $connectionId, $entity, $refresh = false)` and
    `latestSnapshot(...)`: read every provider customer or item once in a
    background job (`customer` or `item`, `Accounting::SNAPSHOT_ENTITIES`;
    other values throw `\InvalidArgumentException` before sending).
  - `matchRecords(..., $source = null)`: `auto` (server default), `snapshot`
    or `live`. Against a ready snapshot a call takes up to 1,000 records.
  - `listRecords(..., $source = null, $q = null)`: page a snapshot, or search
    it by exact email or name prefix.
  - `startBindJob($tenantId, $connectionId, $entity, $bindings)` binds up to
    5,000 pairs in a background job; `bindJobResults(..., $jobId, $cursor)`
    pages its per-row results.
- The `listRecords()` docblock notes that records read from a snapshot carry
  no email or SKU.
- Snapshots and bind jobs are off until the runtime operator enables them
  for the app.

## 1.5.1

- No code changes. The `Accounting::bindRecords()` docblock notes that a row
  refused with `accounting.mapping_conflict` because the ERP id is already
  bound to another record carries that record as `bound_provider_ref`, and
  that after a reconnect an ERP id can be bound again to a different record.
  Both are runtime behaviours; any SDK version returns the field.

## 1.5.0

- `WebhookEvent` parses the `connection.connected`,
  `connection.reconnect_required` and `connection.disconnected` events: their
  `data.connection` (`id`, `provider_key`, `status`, `previous_status`,
  `reason`) is on the new `$connection` property, a delivery without it is
  rejected as `payload_shape_invalid`, and `connectionId()` returns the
  connection id for these events too. New helpers `isConnectionEvent()`,
  `connectionStatus()` and `connectionProviderKey()`, and constants
  `CONNECTION_CONNECTED`, `CONNECTION_RECONNECT_REQUIRED`,
  `CONNECTION_DISCONNECTED`, `CONNECTION_EVENTS` and `WEBHOOK_TEST`. In 1.4.0
  and earlier (from 1.3.3) these events are accepted as unknown types, with the
  body on `$payload` only.
- `Connections::reconnect($tenantId, $connectionId, $returnUrl, $state)` reads
  the connection (one signed GET) and starts a connect session for the same
  tenant and its `provider_key`: the answer to `connection.reconnect_required`.
  A connection whose provider this SDK cannot connect throws
  `\UnexpectedValueException` before a session is started.

## 1.4.0

- `Connections::start()` takes an optional fourth argument, `$providerKey`:
  `'quickbooks'` (the default) or `'xero'`. Existing calls send the same
  request body as before. `Connections::PROVIDER_KEYS` lists the accepted
  values; any other value throws `\InvalidArgumentException` before a request
  is sent. A Xero connect needs the operator to add the app's Xero provider
  configuration first (otherwise `403 authz.provider_not_enabled`).

## 1.3.4

- `examples/signed-read/run.php` also runs from an application that installed
  the SDK: `php vendor/ezee/integration-runtime-sdk/examples/signed-read/run.php`
  uses the application's `vendor/autoload.php`. It needs Guzzle, which every
  Laravel app has, and reports `starter.dependencies_missing` without it.
  In 1.3.3 and earlier it looks only in the SDK's own `vendor/`.
- No changes to `src/`.

## 1.3.3

- `WebhookEvent` accepts `quickbooks.transaction.status_changed` (its
  `data.transaction` is on the new `$transaction` property) and any event type
  this version does not know yet, instead of rejecting the delivery as
  `payload_shape_invalid`. Before this, an endpoint subscribed to all events
  answered 401 to every QuickBooks transaction delivery.
- `RuntimeProblemException::decision()` returns `stop_and_reconcile` for
  `accounting.refund_incomplete` (a Xero refund whose credit note is written
  and whose cash refund is not): resume it with the same `erp_id`, a new key
  and the identical body, or ask the operator; never resubmit it under a new
  `erp_id`.

## 1.3.2

- `RuntimeProblemException::decision()` maps `accounting.write_uncertain`
  explicitly to `stop_and_reconcile` (the write may have reached the provider
  unconfirmed; never resend it). 1.3.1 already returned the same action
  through the unknown-`accounting.*` default, so behavior is unchanged.

## 1.3.1

- `RuntimeProblemException::decision()` now returns the documented action
  for neutral `accounting.*` codes instead of a status-only default. New
  values: `fix_setup` (for example `accounting.mapping_missing`: map it, then
  resend the same request), `review_and_resubmit` (revision, amount, tax,
  number or closed-period conflicts: refresh, review, new business event) and
  `do_not_sync` (before the sync start date, or unsupported by the provider).
  `accounting.provider_unavailable` is now `retry_same_request`. An unknown
  `accounting.*` code is `stop_and_reconcile` whatever its HTTP status
  (`accounting.provider_refused` still follows its status). Codes outside
  `accounting.*` are unchanged.
- `Accounting::referenceOptions()`: one page of the provider records a mapping
  kind can point at, for a mapping dropdown
  (`GET …/reference-data/{kind}/options`, runtime 2026-10-29 and later).
- Docblock and README fixes: `Accounting::listRecords()` does not return
  bindings; the README lists `Accounting` and its decisions.

## 1.3.0

- `Accounting`: the provider-neutral accounting API (writes, status, refresh,
  link, reference data, mappings, settings, record onboarding).
- `WebhookVerifier` accepts `accounting.document.status_changed`.
- `QuickBooks` is deprecated; see `accounting-migration.md`.

## 1.2.0 and earlier

Typed QuickBooks operations, jobs, connections, webhook verification,
`Client::withoutRetry()` and customer mapping. See the git history.
