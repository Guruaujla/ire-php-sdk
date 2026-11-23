<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * The provider-neutral Meetings API (`/v1/meetings/*`): create a video
 * meeting on a tenant's meeting connection (Google Meet first, then Teams and
 * Zoom) and get its join link, with the same code for every provider.
 *
 * 1. {@see providers()} lists the meeting providers with their capabilities
 *    and the tenant's active meeting connections. Connect a provider with
 *    {@see Connections::start()} first.
 * 2. {@see create()} names the connection and your own meeting id
 *    (`external_ref`). It answers the operation status: `status` is
 *    `completed` with `meeting.join_url` when the provider answered in time,
 *    otherwise poll {@see operation()} with `job_id`. Retrying with the same
 *    idempotency key and details replays; a create naming an `external_ref`
 *    that already has a meeting returns it (`existing_meeting` true).
 * 3. {@see update()} changes an active meeting's title, times or access by
 *    its `meeting_ref` (only the fields in `updatable_fields`; `timezone` is
 *    reported in `update_result.fields_ignored`). While one update is in
 *    flight, another update or an end gets 409 `meetings.update_in_progress`.
 *    An update the provider did not confirm ends `meetings.update_uncertain`:
 *    send it again with a new idempotency key.
 * 4. {@see end()} ends a meeting by its `meeting_ref`. The completed
 *    operation's `end_result.link_restricted` says whether the provider
 *    locked the link; `warnings` lists `link_still_open` when it did not, and
 *    `access_not_applied` when a requested access level was not applied.
 *    An end takes priority over an update that has not been sent yet.
 * 5. {@see list()} pages a connection's meetings, newest first; filter by
 *    `external_ref` to find the meeting for one of your records.
 * 6. {@see artifacts()} reads a meeting's recording and transcript links
 *    after it: `status` is `available`, `pending` (check again later),
 *    `none` (see `reason`) or `unsupported`; stop once `final` is true.
 *
 * Optional details: `title`, `start` + `end` (ISO 8601 with offset),
 * `timezone` (IANA name) and `access` (`open`, `trusted`, `restricted`).
 * On create, a detail the provider does not store comes back in
 * `fields_ignored` (Google Meet stores no title or time; send `start` and
 * `end` anyway: they schedule the after-meeting check). Only an `access`
 * level outside `access_levels` is refused, with 422
 * `meetings.capability_unsupported`. On update, a field outside
 * `updatable_fields` is refused the same way. `operations_enabled` from
 * {@see providers()} lists the operations switched on.
 */
final class Meetings
{
    public function __construct(private readonly Client $client) {}

    /**
     * Meeting providers with capabilities, and the tenant's active meeting connections.
     *
     * @return array<string, mixed>
     */
    public function providers(string $tenantId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/meetings/providers', ['tenant_id' => $tenantId]),
        ));
    }

    /**
     * Create a meeting. `$meeting` holds `external_ref` and the optional details.
     *
     * @param array<string, mixed> $meeting
     * @return array<string, mixed> the operation status
     */
    public function create(string $tenantId, string $connectionId, string $idempotencyKey, array $meeting): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('idempotencyKey', $idempotencyKey);
        if (!is_string($meeting['external_ref'] ?? null) || $meeting['external_ref'] === '') {
            throw new \InvalidArgumentException('meeting.external_ref must be a non-empty string');
        }

        return $this->submit([
            'operation' => 'meeting.create',
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'idempotency_key' => $idempotencyKey,
            'meeting' => $meeting,
        ]);
    }

    /**
     * Update an active meeting. `$changes` holds the fields to set (absolute
     * values): `title`, `start` + `end`, `timezone`, `access`.
     *
     * @param array<mixed> $changes field => value; a list is refused
     * @return array<string, mixed> the operation status; `update_result` once it finished
     */
    public function update(
        string $tenantId,
        string $connectionId,
        string $idempotencyKey,
        string $meetingRef,
        array $changes,
    ): array {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('idempotencyKey', $idempotencyKey);
        ResponseReader::requireNonEmpty('meetingRef', $meetingRef);
        if ($changes === [] || array_is_list($changes)) {
            throw new \InvalidArgumentException('changes must name at least one field');
        }

        return $this->submit([
            'operation' => 'meeting.update',
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'idempotency_key' => $idempotencyKey,
            'meeting_ref' => $meetingRef,
            'changes' => $changes,
        ]);
    }

    /**
     * End a meeting by the `meeting_ref` returned when it was created.
     *
     * @return array<string, mixed> the operation status
     */
    public function end(string $tenantId, string $connectionId, string $idempotencyKey, string $meetingRef): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('idempotencyKey', $idempotencyKey);
        ResponseReader::requireNonEmpty('meetingRef', $meetingRef);

        return $this->submit([
            'operation' => 'meeting.end',
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'idempotency_key' => $idempotencyKey,
            'meeting_ref' => $meetingRef,
        ]);
    }

    /**
     * Poll an operation by the `job_id` from {@see create()}, {@see update()} or {@see end()}:
     * `200` once it finished, `202` while it is still running.
     *
     * @return array<string, mixed>
     */
    public function operation(string $tenantId, string $jobId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('jobId', $jobId);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/meetings/operations/' . rawurlencode($jobId), ['tenant_id' => $tenantId]),
        ), [200, 202]);
    }

    /**
     * Read a meeting: status, join link, meeting code, dial-in numbers, and the
     * title, times and access as the provider last confirmed them.
     *
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $connectionId, string $meetingRef): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('meetingRef', $meetingRef);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query(
                '/v1/meetings/connections/' . rawurlencode($connectionId) . '/meetings/' . rawurlencode($meetingRef),
                ['tenant_id' => $tenantId],
            ),
        ));
    }

    /**
     * A connection's meetings, newest first, as stored (no provider call).
     * `$filters` may hold `external_ref`, `status` (`creating`, `active`,
     * `ending`, `ended`, `failed`), `limit` (1-100, default 50) and `cursor`
     * (the previous page's `next_cursor`).
     *
     * @param array{external_ref?: string, status?: string, limit?: int, cursor?: string} $filters
     * @return array<string, mixed> `meetings` and `next_cursor` (null on the last page)
     */
    public function list(string $tenantId, string $connectionId, array $filters = []): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        $query = ['tenant_id' => $tenantId];
        foreach (['external_ref', 'status', 'cursor'] as $key) {
            if (isset($filters[$key])) {
                ResponseReader::requireNonEmpty($key, (string) $filters[$key]);
                $query[$key] = (string) $filters[$key];
            }
        }
        if (isset($filters['limit'])) {
            $limit = (int) $filters['limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \InvalidArgumentException('limit must be 1 to 100');
            }
            $query['limit'] = (string) $limit;
        }

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/meetings/connections/' . rawurlencode($connectionId) . '/meetings', $query),
        ));
    }

    /**
     * The meeting for one of your own records, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function findByExternalRef(string $tenantId, string $connectionId, string $externalRef): ?array
    {
        ResponseReader::requireNonEmpty('externalRef', $externalRef);
        $page = $this->list($tenantId, $connectionId, ['external_ref' => $externalRef, 'limit' => 1]);
        $meetings = $page['meetings'] ?? [];

        if (! is_array($meetings) || ! isset($meetings[0]) || ! is_array($meetings[0])) {
            return null;
        }
        /** @var array<string, mixed> $meeting */
        $meeting = $meetings[0];

        return $meeting;
    }

    /**
     * A meeting's recording and transcript links and call times, after the
     * meeting. Links only: open them in a browser; the organizer's own
     * sharing rules decide who can. `status` is `available`, `pending`,
     * `none` (with `reason`) or `unsupported`; `final` true means it will not
     * change, and `stale` true means the provider could not be asked just now.
     *
     * @return array<string, mixed>
     */
    public function artifacts(string $tenantId, string $connectionId, string $meetingRef): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('meetingRef', $meetingRef);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query(
                '/v1/meetings/connections/' . rawurlencode($connectionId) . '/meetings/' . rawurlencode($meetingRef) . '/artifacts',
                ['tenant_id' => $tenantId],
            ),
        ));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function submit(array $request): array
    {
        return ResponseReader::json($this->client->send('POST', '/v1/meetings/operations', $request), [200, 202]);
    }
}
