# Poll a bounded job result

This mock-only example uses the existing signed `Client` to poll
`GET /v1/sync-jobs/{id}?tenant_id=...` and return a recipe-declared JSON result.
It needs no provider account, API key, Cloudflare resource, or network access.

```bash
cd sdks/php
composer install
php examples/job-result/run.php
```

The polling response has two additive fields:

- `result_status: none` with `result: null` for jobs whose recipe does not
  declare a result, including existing QuickBooks Invoice jobs.
- `result_status: available` with an object in `result` after a result-bearing
  job reaches `completed`.

`result_status` is extensible. Consumers must treat unknown non-`none` values
as potentially usable and validate the `result` object instead of rejecting an
otherwise valid completed response.

The runtime accepts at most 32 KiB of UTF-8 JSON, rejects values that JSON would
silently coerce or omit, and commits the result in the same guarded database
update as `status: completed`. Results have no independent expiry: they share
the retained `sync_jobs` row's current lifecycle. An absent, deleted, or
out-of-scope job uses the existing scoped `404 sync_job.not_found` response.

Only explicitly published, non-secret business data belongs in a result. Recipe
scratch state, credentials, step outputs, and raw provider responses are not
automatically exposed.
