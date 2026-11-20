# Changelog: ezee/integration-runtime-sdk

Releases are published to `Guruaujla/ire-php-sdk` as `v` tags.

## Unreleased

- `WebhookEvent` knows `meeting.artifacts_ready` (`WebhookEvent::MEETING_ARTIFACTS_READY`):
  `data.meeting` is on the new `$meeting` property, with `meetingRef()` and
  `meetingArtifactsStage()` (`available` or `final`); `connectionId()` also
  reads it. The event is opt-in: list it on the webhook. At most two per
  meeting, with the same links `Meetings::artifacts()` returns.
- `RuntimeProblemException::decision()` decides `fix_setup` for the new
  `accounting.reconnect_required` (409): the connection's grant lacks a scope
  the operation needs (a Xero connection consented before prepayments needs
  `accounting.banktransactions` for `Accounting::createPrepayment`).
  Reconnect, then resend the same request. `missing_scopes` names the scopes.

- New `Meetings::artifacts($tenantId, $connectionId, $meetingRef)`: a
  meeting's recording and transcript links and call times after it
  (`GET /v1/meetings/connections/{id}/meetings/{meeting_ref}/artifacts`).
  `status` is `available`, `pending`, `none` (with `reason`) or
  `unsupported`; stop checking once `final` is true. Links only; who can
  open them follows the organizer's own sharing rules. Google Meet only for
  now (Teams answers `unsupported`). Meeting capabilities gain `artifacts`.

- New `Meetings::update($tenantId, $connectionId, $idempotencyKey, $meetingRef, $changes)`
  for `meeting.update`: change an active meeting's `title`, `start` + `end`
  or `access` (Microsoft Teams: all four; Google Meet: `access` only, see
  `updatable_fields` in `providers()`). The finished operation carries
  `update_result` (`fields_applied`, `fields_ignored`), and a meeting now
  shows its `title`, `start`, `end`, `access` and `update_in_progress`.
  Ships disabled until the staging run.

- Xero now offers `Accounting::createPrepayment`, `Accounting::applyPrepayment`
  and `Accounting::unapplyPrepayment` (an overpayment, its allocation and the
  allocation's delete); they ship disabled until qualified and enabled.

- New `QuickBooks::applyPrepayment` and `QuickBooks::unapplyPrepayment` for
  the native `quickbooks.prepayment.apply` and `.unapply` (a full-line update
  of the prepayment's Payment). QuickBooks now offers
  `Accounting::applyPrepayment` and `Accounting::unapplyPrepayment`; both ship
  disabled until qualified and enabled.

- New `QuickBooks::createPrepayment` for the native
  `quickbooks.prepayment.create` (a Payment with no Line, held as unapplied
  customer credit). QuickBooks now offers `Accounting::createPrepayment`; it
  ships disabled until it is qualified and enabled.

- New `Accounting` methods for customer prepayments (retainers and wallet
  top-ups): `createPrepayment`, `applyPrepayment`, `unapplyPrepayment`,
  `refundPrepayment`, `voidPrepayment` (`createPrepayment` and
  `refundPrepayment` take the optional `$erpCreatedAt`). No provider offers them yet: the
  runtime answers `422 accounting.capability_unsupported`.
- `Connections` accepts `google-meet` and `microsoft-teams` for connect,
  reconnect and disconnect (Meetings API). For a meeting connection, pass `provider_account_id` to
  disconnect (from `GET /v1/meetings/providers`); the accounting lookup does
  not find it.

- New `Meetings` (`/v1/meetings/*`), the provider-neutral Meetings API:
  `providers($tenantId)` (capabilities and the tenant's active meeting
  connections), `create($tenantId, $connectionId, $idempotencyKey, $meeting)`
  (`external_ref` plus optional `title`, `start`, `end`, `timezone`,
  `access`), `end(..., $meetingRef)`, `operation($tenantId, $jobId)` and
  `get($tenantId, $connectionId, $meetingRef)`. Create and end return the
  operation status (`200` finished or `202` running). Google Meet and
  Microsoft Teams are the providers (no SDK change; capabilities come from
  `providers()`).

- `Accounting::buildRequest()`, `execute()`, `createInvoice()`,
  `createPayment()`, `createRefund()`, `createCreditMemo()` and
  `applyCreditMemo()` take an optional `?DateTimeInterface $erpCreatedAt`
  (last argument): when the ERP created the document. Sent as
  `erp_created_at` in the value's own timezone, it makes the runtime compare
  the sync start date with the creation date instead of the document date.
  Refused on non-create operations. New `Accounting::CREATED_AT_OPERATIONS`.

- New `OutboxDecision`: `for($e)`, `forJobError($code)` and
  `fromDecision($decision)` map a send failure or a failed job onto an ERP
  outbox row's next state (`pending`, `failed`, `skipped`, `needs_person`).
  See accounting-operations.md "Reliable delivery: the outbox pattern".

- New `Providers` (`GET /v1/providers`): `list($tenantId)` returns every
  provider your app can connect (`provider_key`, `display_name`, `category`,
  `enabled`); `inCategory($tenantId, $category)` filters by unified API
  (`accounting`, later `meetings`). New providers appear without an SDK change.

- New `Activity` (`GET /v1/activity`): `list($tenantId, $filters)` returns
  one page of the tenant's integration activity, newest first (operations
  with their outcome and a customer-safe error message, document changes the
  provider reported, connection changes); `each()` follows `next_cursor`.
  Filters: `connection_id`, `erp_id`, `types` (string or list), `since`,
  `until`, `limit`, `cursor`.

## 1.9.0

- Docblocks of `Accounting::voidPayment()`, `deletePayment()`,
  `updatePayment()` and `deleteInvoice()` say what each provider does
  (Xero deletes on a void, and refuses a payment update and an invoice
  delete with 422 `accounting.capability_unsupported`), the refusals
  as the job's error (`accounting.unsupported_change` for a payment named by
  a runtime refund, a deposited QuickBooks payment, a reconciled or batch Xero
  payment, or a QuickBooks delete of a voided payment;
  `accounting.period_closed`), that repeating the same reversal completes with
  no write, that a lower payment amount lowers its application with it, that a
  deleted `erp_id` is never reused, and that each operation is disabled until
  qualified and enabled for the app. Tests cover all four helpers.
- `Accounting::deleteInvoice()` sends `accounting.invoice.delete`
  (QuickBooks only; Xero answers 422 `accounting.capability_unsupported`).
  `Accounting::OPERATIONS` and `QuickBooks::OPERATIONS` list the new
  operation (`quickbooks.invoice.delete`, recipe
  `erp.quickbooks.invoice.deleted` v1).
- No code changes for the matching and binding fields: reads and results
  are pass-through arrays, and `matchRecords()` sends records as given.
  Docblocks now describe them: match records take `tax_number` and
  `account_number` (customers) and `rate_percent` (tax codes); candidates
  carry `match_reasons`, `usable` and `unusable_reason`; results carry
  `not_matched_reason` and `unsearchable_keys`; options carry `account_type`,
  `account_code` and `recommended`; tax codes carry `rate_percent` and
  `suggested_treatment`. A bind re-binds an ERP id already bound to another
  record on every provider (`record_already_bound` is no longer returned),
  a refused row carries `reason`, `pending_change` is a retryable 503, and a
  batch or bind job naming one `provider_ref` twice is a 400.
  `getMapping()` checks payment method and account mappings live (`state`
  `inactive` or `drifted`). `upsertCustomer()` and `upsertItem()` note the
  QuickBooks name pre-check (409 `accounting.document_exists`) and the item
  type change refusal (409 `accounting.unsupported_change`), both now at
  submit; their decisions are unchanged.
- `decision()` maps the new `accounting.document_deleted` (410, the provider
  no longer has the document) to `do_not_sync`.
- `Accounting::pdf()` downloads the provider's PDF of an invoice or credit
  memo the runtime wrote, as raw bytes.
- `Accounting::onlineLink()` reads the customer's online view-and-pay link
  of an invoice the runtime wrote (QuickBooks `InvoiceLink`, Xero
  `OnlineInvoiceUrl`); `url` is null when the provider has none.
- `Accounting::updateSettings()` takes `?array $recordTypes` (null keeps
  them) and a new optional `?string $syncStartDate` to move the connection's
  sync start date. It throws `InvalidArgumentException` when both are null.
- `Accounting::voidPayment()` and `Accounting::deletePayment()` now also
  reach Xero, where both delete the payment (`xero.payment.delete`, disabled
  until qualified); a deleted Xero payment's `revision` is `deleted`. There is
  no Xero operations map, so no constant changed.
- `decision()` maps the new `accounting.invoice_has_payment` (a void of an
  invoice with a payment or credit applied) to `review_and_resubmit`.
- `Accounting::voidPayment()` and `Accounting::deletePayment()` for the new
  `accounting.payment.void` and `accounting.payment.delete` operations, and
  `quickbooks.payment.void` / `quickbooks.payment.delete` in
  `QuickBooks::OPERATIONS`. Both ship disabled until qualified.
- `Accounting::updatePayment()` for the new `accounting.payment.update`
  operation, and `quickbooks.payment.update` in `QuickBooks::OPERATIONS`.
  Ships disabled until qualified. A payment update result carries
  `unapplied_amount` and `invoice.revision`.

## 1.8.0

- No code changes; results and reads are pass-through arrays, so the new
  fields arrive with no upgrade. Invoice create and update results
  (`Accounting::status()` `result`) now carry `tax_lines`: the tax the
  provider stored, in the `expected_totals.tax_lines` shape. Store them with
  `net_amount`, `total_tax`, `total_amount` and `revision`, and send them
  back unchanged as the next update's `before.totals`.
- `Accounting::refresh()` for an invoice also returns `net_amount`,
  `total_tax` and `tax_lines` from the live read, to backfill `before.totals`
  for invoices created before results carried `tax_lines`.
- Runtime behaviour documented in `accounting-operations.md`: a QuickBooks
  invoice with payments can now be updated when every link is a Payment and
  the new total is at least the amount paid; a paid, part-paid or credited
  Xero invoice takes only due date and line description changes. Other
  changes are `accounting.unsupported_change` (decision `fix_request`). A
  payment made through the runtime no longer blocks a later update once it
  has completed or failed. A QuickBooks update that leaves the invoice fully paid
  returns `balance` `0.00` and no `status`.

## 1.7.0

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
