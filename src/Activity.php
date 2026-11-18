<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * A tenant's integration activity, newest first (`GET /v1/activity`), for
 * showing your own customer an integration log: operations you sent with their
 * current outcome, document changes the provider reported (for example an
 * invoice paid in QuickBooks), and connection status changes.
 *
 * Each event carries `id`, `type` (`operation`, `document.changed`,
 * `connection.connected`, `connection.reconnect_required`,
 * `connection.disconnected`; more may be added), `occurred_at`,
 * `connection_id`, `provider_key`, a customer-safe `summary` sentence, and the
 * nullable objects `operation`, `document`, `connection` and `error`
 * (`{code, message, reason}`; the message is customer-safe, `reason` is the
 * capabilities reason for a disabled or unsupported operation, else null). Events are returned as
 * decoded arrays.
 *
 * @phpstan-type ActivityFilters array{connection_id?: string, erp_id?: string, types?: string|list<string>, since?: int, until?: int, limit?: int, cursor?: string}
 */
final class Activity
{
    public function __construct(private readonly Client $client) {}

    /**
     * One page: `['events' => list<array>, 'next_cursor' => ?string]`.
     *
     * `since`/`until` are Unix epoch milliseconds (default: the last 90 days;
     * at most 366 days apart). A page may hold fewer events than `limit`, even
     * none, while `next_cursor` is set: keep paging until it is null, or use
     * {@see each()}.
     *
     * @param ActivityFilters $filters
     * @return array<string, mixed>
     */
    public function list(string $tenantId, array $filters = []): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        $query = [];
        foreach (['connection_id', 'erp_id', 'cursor'] as $key) {
            if (isset($filters[$key])) {
                $query[$key] = (string) $filters[$key];
            }
        }
        if (isset($filters['types'])) {
            $query['types'] = is_array($filters['types']) ? implode(',', $filters['types']) : $filters['types'];
        }
        foreach (['since', 'until', 'limit'] as $key) {
            if (isset($filters[$key])) {
                $query[$key] = (int) $filters[$key];
            }
        }
        $query['tenant_id'] = $tenantId;

        return ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/activity', $query)));
    }

    /**
     * Every event matching the filters, newest first, following `next_cursor`.
     * Stops after `$maxPages` requests.
     *
     * @param ActivityFilters $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function each(string $tenantId, array $filters = [], int $maxPages = 100): \Generator
    {
        $cursor = $filters['cursor'] ?? null;
        for ($page = 0; $page < $maxPages; $page++) {
            $body = $this->list($tenantId, $cursor === null ? $filters : [...$filters, 'cursor' => $cursor]);
            $events = $body['events'] ?? [];
            if (is_array($events)) {
                foreach ($events as $event) {
                    if (is_array($event)) {
                        /** @var array<string, mixed> $event */
                        yield $event;
                    }
                }
            }
            $next = $body['next_cursor'] ?? null;
            if (! is_string($next) || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }
}
