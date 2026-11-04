# Changelog: ezee/integration-runtime-sdk

Releases are published to `Guruaujla/ire-php-sdk` as `v` tags.

## Unreleased

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
