<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * The provider-neutral Calendar API (`/v1/calendar/*`): read a person's
 * calendars and events (Google Calendar first, then Outlook) with the same
 * code for every provider.
 *
 * 1. Connect one person's calendar with {@see Connections::start()},
 *    `providerKey` `google-calendar` and `principalRef` (your id for the
 *    person). Store the returned `connection_id` against the person; it
 *    works once they finish consent. Connecting the same `principalRef`
 *    again reconnects that same connection.
 * 2. {@see providers()} lists the calendar providers with their
 *    capabilities and the tenant's calendar connections in every state (with
 *    `principal_ref` and `status`).
 * 3. {@see calendars()} lists the calendars the person can see; any of them
 *    may be read.
 * 4. {@see events()} reads one calendar's events over a window
 *    (`from`/`to`, RFC 3339 with offset). `mode` `occurrences` (default)
 *    expands repeating events; `series` returns each series once. Cancelled
 *    items are included so a sync can remove them.
 *
 * Pages: pass the previous page's `next_cursor` as `cursor` with the same
 * query. Reads are live and never stored by the runtime.
 */
final class Calendar
{
    public function __construct(private readonly Client $client) {}

    /**
     * Start connecting one person's calendar. Redirect the person to
     * `authorize_url`; store `connection_id` against the person. Calling it
     * again with the same `$principalRef` reconnects that same connection.
     *
     * @param string $principalRef your id for the person (1-255 characters), for example your user id
     * @param string $state one-time, unpredictable value you stored server-side for this attempt
     * @return array{session_id: string, authorize_url: string, expires_at: int, connection_id: string|null}
     */
    public function connect(string $tenantId, string $principalRef, string $returnUrl, string $state, string $providerKey = 'google-calendar'): array
    {
        return (new Connections($this->client))->start($tenantId, $returnUrl, $state, $providerKey, $principalRef);
    }

    /**
     * Disconnect one person's calendar. The runtime deletes its tokens; the
     * person's own consent at the provider is left alone. Reuse
     * `$idempotencyKey` only to retry the same attempt. Without
     * `$providerAccountId` the SDK reads it from {@see providers()}, which
     * lists calendar connections in every state.
     *
     * @return array{provider_cleanup: string|null}
     */
    public function disconnect(string $tenantId, string $connectionId, string $idempotencyKey, ?string $providerAccountId = null): array
    {
        return (new Connections($this->client))->disconnect(
            $tenantId,
            $connectionId,
            $idempotencyKey,
            $providerAccountId ?? $this->providerAccountId($tenantId, $connectionId),
        );
    }

    /** The provider account id {@see providers()} reports for one connected calendar. */
    public function providerAccountId(string $tenantId, string $connectionId): string
    {
        $connections = $this->providers($tenantId)['connections'] ?? [];
        foreach (is_array($connections) ? $connections : [] as $connection) {
            if (is_array($connection) && ($connection['connection_id'] ?? null) === $connectionId) {
                $accountId = $connection['provider_account_id'] ?? null;
                if (is_string($accountId) && $accountId !== '') {
                    return $accountId;
                }
            }
        }
        throw new \UnexpectedValueException('Connection ' . $connectionId . ' is not a calendar connection of this tenant; pass the provider_account_id you stored.');
    }

    /**
     * Calendar providers with capabilities, and the tenant's calendar connections (every state, with `status`).
     *
     * @return array<string, mixed>
     */
    public function providers(string $tenantId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/calendar/providers', ['tenant_id' => $tenantId]),
        ));
    }

    /**
     * The calendars the connected person can see. `$options` may hold
     * `page_size` (1 to the provider's `max_page_size`, default 100) and
     * `cursor` (the previous page's `next_cursor`).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> `calendars` and `next_cursor` (null on the last page)
     */
    public function calendars(string $tenantId, string $connectionId, array $options = []): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        $query = ['tenant_id' => $tenantId] + self::paging($options);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/calendar/connections/' . rawurlencode($connectionId) . '/calendars', $query),
        ));
    }

    /**
     * One calendar's events over a window. `$query` holds `from` and `to`
     * (RFC 3339 with offset, at most 366 days apart) and may hold
     * `calendar_id` (default `primary`), `mode` (`occurrences` or `series`),
     * `page_size` and `cursor`.
     *
     * @param array<string, mixed> $query `from`, `to`, and optionally `calendar_id`, `mode`, `page_size`, `cursor`
     * @return array<string, mixed> `events` and `next_cursor` (null on the last page)
     */
    public function events(string $tenantId, string $connectionId, array $query): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        $params = ['tenant_id' => $tenantId];
        foreach (['from', 'to'] as $key) {
            $value = $query[$key] ?? null;
            if (! is_string($value) || $value === '') {
                throw new \InvalidArgumentException($key . ' must be an RFC 3339 date-time with offset');
            }
            $params[$key] = $value;
        }
        $calendarId = self::stringOption($query, 'calendar_id');
        if ($calendarId !== null) {
            $params['calendar_id'] = $calendarId;
        }
        $mode = self::stringOption($query, 'mode');
        if ($mode !== null) {
            if (! in_array($mode, ['occurrences', 'series'], true)) {
                throw new \InvalidArgumentException('mode must be occurrences or series');
            }
            $params['mode'] = $mode;
        }
        $params += self::paging($query);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/calendar/connections/' . rawurlencode($connectionId) . '/events', $params),
        ));
    }

    /**
     * Every event of one window, following `next_cursor` page by page.
     * A `cursor` in `$query` is ignored: the walk starts at the first page.
     *
     * @param array<string, mixed> $query as {@see events()}
     * @return \Generator<int, array<string, mixed>>
     */
    public function eachEvent(string $tenantId, string $connectionId, array $query): \Generator
    {
        unset($query['cursor']);
        do {
            $page = $this->events($tenantId, $connectionId, $query);
            $events = $page['events'] ?? [];
            if (is_array($events)) {
                foreach ($events as $event) {
                    if (is_array($event)) {
                        /** @var array<string, mixed> $event */
                        yield $event;
                    }
                }
            }
            $next = $page['next_cursor'] ?? null;
            $query['cursor'] = is_string($next) && $next !== '' ? $next : null;
        } while ($query['cursor'] !== null);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException($key . ' must be a non-empty string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function paging(array $options): array
    {
        $out = [];
        $size = $options['page_size'] ?? null;
        if ($size !== null) {
            if (! is_int($size) || $size < 1 || $size > 250) {
                throw new \InvalidArgumentException('page_size must be an integer from 1 to 250');
            }
            $out['page_size'] = (string) $size;
        }
        $cursor = self::stringOption($options, 'cursor');
        if ($cursor !== null) {
            $out['cursor'] = $cursor;
        }

        return $out;
    }
}
