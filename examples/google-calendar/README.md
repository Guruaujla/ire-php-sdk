# Google Calendar read consumer — offline contract fixture

This is a **synthetic-only C5 implementation fixture**, not a currently usable
Calendar integration. It makes no provider, OAuth, database, queue, or network
call. The mounted Worker accepts the Calendar read request shapes with a
synthetic provider and publishes their schema in OpenAPI; that is local wire
acceptance, not deployed or live acceptance. The public connect-session route
currently accepts only QuickBooks and rejects `provider_key: google-calendar`.

Calendar remains inactive until a runtime deployment deliberately enables it
and an authorized Google consent flow has completed and been authoritatively
reconciled. This fixture begins with an already-authorized synthetic grant; it
does not show how to provision consent. Do not point it at a runtime or infer
availability from the public schema alone.

It uses only product-held runtime credentials (`app_id`, `key_id`, secret and
runtime `tenant_id`) through the existing signed `Client`. It never receives a
Google token, provider page token, adapter continuation, OAuth client secret or
operator credential.

```bash
cd sdks/php
composer install
php examples/google-calendar/run.php
```

## The product journey

1. Begin from an already-authorized grant ID supplied out of band. Read the
   scoped connection and Calendar grant before enabling Calendar behavior.
2. Submit `erp.google-calendar.calendars.list` through the durable operation
   endpoint. Poll, fetch and resume every public discovery page until
   `next_page_handle` is null. The selectable calendar may appear on a later
   page; an empty page with a handle is not completion.
3. Persist an explicit selected resource binding using the page ID that
   actually contains the canonical calendar ID. Discovery alone does not grant
   event access.
4. Submit `erp.google-calendar.events.read`, poll its manifest and fetch the
   scoped page URL. Resume through every non-null handle, even after an empty
   event page. Polling uses bounded backoff. A direct poll timeout or future
   open status retains its submission; during traversal,
   `CalendarTraversalPendingException` also retains fetched pages. Persist
   both, poll/collect the same pending job again, and append the new pages—do
   not resubmit its operation. If the local page bound is reached instead,
   `CalendarTraversalLimitException` carries fetched pages and the exact next
   handle; resume from that handle without starting a new root read.
5. A `410 calendar.page_expired`/related expiry, including a terminal job with
   that code, requires a new root listing or read. A
   `409 calendar.reconnect_required` from a response or terminal job requires
   a separately authorized consent completion, rediscovery and explicit
   reselection;
   `calendar.generation_changed` requires a new root read against the current
   generation. The mock changes state only after explicit simulated approval;
   denial or abandonment leaves it reconnect-required.

The frozen route names, request fields, response manifest and public page DTO
are exercised by the in-process transport and by the local synthetic Worker
wire test. The downloadable public OpenAPI JSON in the developer docs and
console is an importable schema reference; importing it does not create a
Calendar grant, configure HMAC, enable the feature, or prove an endpoint is
released.

## Boundaries this fixture preserves

- Polling and every follow-up request use the generic client, which signs fresh
  HMAC values per request. Persist the returned job and request intent; do not
  resubmit an ambiguous intent under a new key. Initial Calendar list/read
  idempotency keys cannot start with `calendar-page:`; that prefix is reserved
  for exact handle-derived list/read resumes. Resource selection retains its
  separate idempotency-key rules. The public 128-character limit counts UTF-16
  code units, so an astral character consumes two units.
- Runtime page handles are opaque, 43-character URL-safe references. They are
  safe to persist with the job intent, but they are not authorization and never
  contain a Google cursor. The runtime still applies current grant/resource/
  expiry authorization on every page or resume request.
- Public pages carry only allowlisted Calendar records. The fixture exposes no
  raw provider response, attendee, description, token, continuation or
  presigned URL.
- The existing QuickBooks Invoice example is separate and unchanged. Calendar
  reads do not enable, alter or relax any Invoice company/tenant/request-ID or
  marker-free financial guard.
