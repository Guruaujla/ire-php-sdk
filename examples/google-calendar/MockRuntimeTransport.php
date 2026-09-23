<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\GoogleCalendar;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-process C5 contract fixture. It is not a runtime emulator and cannot
 * perform OAuth, provider, database, queue, or network work.
 */
final class MockRuntimeTransport implements ClientInterface
{
    private const GRANT_ID = 'grant_google_calendar_mock_001';

    private const CALENDAR_ID = 'team-calendar@example.invalid';

    /** Exactly 43 URL-safe characters: a runtime continuation handle, never a provider token. */
    private const NEXT_HANDLE = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const NEXT_DISCOVERY_HANDLE = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var array<string, int> */
    private array $polls = [];

    /** @var array<string, list<string>> */
    private array $pollingPlans = [];

    /** @var array<string, string> */
    private array $completedResultStatuses = [];

    /** @var array<string, array<string, mixed>> */
    private array $polledJobFields = [];

    private string $connectionStatus = 'connected';

    private ?string $grantReadStatus = null;

    private ?bool $grantReadCanDiscover = null;

    private ?bool $grantReadCanRead = null;

    private ?string $selectedCalendarIdResponse = null;

    private int $epoch = 1;

    private bool $expireEventPage = false;

    private bool $reconnectPending = false;

    private readonly int $createdAtMs;

    private readonly int $pageExpiresAt;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $appId = 'app_erp_mock',
    ) {
        $this->createdAtMs = (int) floor(microtime(true) * 1_000);
        $this->pageExpiresAt = $this->createdAtMs + 86_400_000;
    }

    public function requireReconnect(): void
    {
        $this->connectionStatus = 'reconnect_required';
        $this->reconnectPending = false;
    }

    /** Fixture-only consent transition; requesting consent never changes the grant by itself. */
    public function beginMockReconnect(): void
    {
        if ($this->connectionStatus !== 'reconnect_required' || $this->reconnectPending) {
            throw new \LogicException('Mock reconnect can only begin for a reconnect-required grant');
        }
        $this->reconnectPending = true;
    }

    /** Denial or abandonment leaves the grant reconnect-required. */
    public function completeMockReconnect(bool $approved): void
    {
        if (! $this->reconnectPending) {
            throw new \LogicException('Mock reconnect has no pending simulated consent');
        }
        $this->reconnectPending = false;
        if ($approved) {
            $this->connectionStatus = 'connected';
            $this->epoch++;
        }
    }

    public function abandonMockReconnect(): void
    {
        $this->completeMockReconnect(false);
    }

    /** @param list<string> $statuses */
    public function setPollingPlan(string $jobId, array $statuses): void
    {
        $this->pollingPlans[$jobId] = $statuses;
        $this->polls[$jobId] = 0;
    }

    /** @param list<string> $statuses */
    public function setNextDiscoveryResumePollingPlan(array $statuses): void
    {
        $this->setPollingPlan($this->discoverySecondJobId(), $statuses);
    }

    /** @param list<string> $statuses */
    public function setNextEventResumePollingPlan(array $statuses): void
    {
        $this->setPollingPlan($this->eventSecondJobId(), $statuses);
    }

    public function setSelectedCalendarIdResponse(string $calendarId): void
    {
        $this->selectedCalendarIdResponse = $calendarId;
    }

    public function setCompletedResultStatus(string $jobId, string $status): void
    {
        $this->completedResultStatuses[$jobId] = $status;
    }

    /** @param array<string, mixed> $fields */
    public function setPolledJobFields(string $jobId, array $fields): void
    {
        $this->polledJobFields[$jobId] = $fields;
    }

    /** Simulates a grant changing after the authoritative connection GET. */
    public function setGrantReadState(string $status, ?bool $canDiscover = null, ?bool $canRead = null): void
    {
        $this->grantReadStatus = $status;
        $this->grantReadCanDiscover = $canDiscover;
        $this->grantReadCanRead = $canRead;
    }

    public function expireNextEventPage(): void
    {
        $this->expireEventPage = true;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'GET' && $path === '/v1/connections/' . self::GRANT_ID) {
            return $this->connection($request);
        }
        if ($request->getMethod() === 'GET' && $path === '/v1/calendar/grants/' . self::GRANT_ID) {
            return $this->grant($request);
        }
        if ($request->getMethod() === 'POST' && $path === '/v1/calendar/grants/' . self::GRANT_ID . '/resources') {
            return $this->selectResource($request);
        }
        if ($request->getMethod() === 'POST' && $path === '/v1/operations/execute') {
            return $this->execute($request);
        }
        if ($request->getMethod() === 'GET' && str_starts_with($path, '/v1/sync-jobs/')) {
            return $this->poll($request);
        }
        if ($request->getMethod() === 'GET' && str_starts_with($path, '/v1/calendar/pages/')) {
            return $this->page($request);
        }

        return $this->problem(404, 'request.route_not_found', 'No mock route matched.');
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function grantId(): string
    {
        return self::GRANT_ID;
    }

    public function calendarId(): string
    {
        return self::CALENDAR_ID;
    }

    public function currentResourceId(): string
    {
        return sprintf('resource_google_calendar_mock_%03d', $this->epoch);
    }

    private function connection(RequestInterface $request): ResponseInterface
    {
        if (! $this->queryHasTenant($request)) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }

        return $this->json(200, [
            'id' => self::GRANT_ID,
            'provider_key' => 'google-calendar',
            'status' => $this->connectionStatus,
            'connected_at' => $this->connectionStatus === 'connected' ? $this->createdAtMs : null,
            'created_at' => $this->createdAtMs,
            'updated_at' => $this->createdAtMs + $this->epoch,
        ]);
    }

    private function grant(RequestInterface $request): ResponseInterface
    {
        if (! $this->queryHasTenant($request)) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }

        $status = $this->grantReadStatus ?? $this->connectionStatus;

        return $this->json(200, [
            'grant_id' => self::GRANT_ID,
            'connection_id' => self::GRANT_ID,
            'provider_key' => 'google-calendar',
            'principal_id' => 'principal_google_calendar_mock',
            'status' => $status,
            'capabilities' => [
                'discover_calendars' => $this->grantReadCanDiscover ?? ($status === 'connected'),
                'read_events' => $this->grantReadCanRead ?? ($status === 'connected'),
            ],
            'created_at' => $this->createdAtMs,
            'updated_at' => $this->createdAtMs + $this->epoch,
        ]);
    }

    private function selectResource(RequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        if (($body['tenant_id'] ?? null) !== $this->tenantId) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }
        if ($this->connectionStatus !== 'connected') {
            return $this->problem(409, 'calendar.reconnect_required', 'Reconnect before selecting a Calendar resource.');
        }
        if (
            ($body['discovery_page_id'] ?? null) !== $this->discoverySecondPageId()
            || ($body['calendar_id'] ?? null) !== self::CALENDAR_ID
            || ! is_string($body['idempotency_key'] ?? null)
        ) {
            return $this->problem(403, 'calendar.resource_denied', 'The selected calendar was not proven by this grant discovery.');
        }

        return $this->json(200, [
            'resource_id' => $this->currentResourceId(),
            'grant_id' => self::GRANT_ID,
            'calendar_id' => $this->selectedCalendarIdResponse ?? self::CALENDAR_ID,
            'status' => 'selected',
            'created_at' => $this->createdAtMs + 200 + $this->epoch,
            'updated_at' => $this->createdAtMs + 200 + $this->epoch,
        ]);
    }

    private function execute(RequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        if (($body['tenant_id'] ?? null) !== $this->tenantId || ($body['provider_key'] ?? null) !== 'google-calendar') {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong Calendar operation scope.');
        }
        if (($body['connection_id'] ?? null) !== self::GRANT_ID) {
            return $this->problem(403, 'calendar.grant_not_found', 'Unknown Calendar grant.');
        }
        if ($this->connectionStatus !== 'connected') {
            return $this->problem(409, 'calendar.reconnect_required', 'Reconnect before reading Calendar data.');
        }
        $payload = $body['payload'] ?? null;
        if (! is_array($payload)) {
            return $this->problem(400, 'calendar.invalid_input', 'Calendar payload must be an object.');
        }
        if (($body['recipe_key'] ?? null) === 'erp.google-calendar.calendars.list') {
            if (
                ($body['recipe_version'] ?? null) !== 1
                || ($body['trigger_type'] ?? null) !== 'on_demand'
                || ($body['object_type'] ?? null) !== 'calendar'
                || ($body['internal_object_id'] ?? null) !== self::GRANT_ID
                || ($payload['grant_id'] ?? null) !== self::GRANT_ID
            ) {
                return $this->problem(400, 'calendar.invalid_input', 'Discovery request did not match the frozen Calendar contract.');
            }

            if (isset($payload['continuation_handle'])) {
                if (
                    array_keys($payload) !== ['grant_id', 'continuation_handle']
                    || $payload['continuation_handle'] !== self::NEXT_DISCOVERY_HANDLE
                    || ($body['idempotency_key'] ?? null) !== 'calendar-page:' . self::NEXT_DISCOVERY_HANDLE
                ) {
                    return $this->problem(400, 'calendar.invalid_input', 'Discovery resume must use its exact handle and idempotency key.');
                }

                return $this->accepted($this->discoverySecondJobId());
            }
            if (! is_int($payload['page_size'] ?? null) || count($payload) !== 2) {
                return $this->problem(400, 'calendar.invalid_input', 'Discovery root requires only grant_id and page_size.');
            }

            return $this->accepted($this->discoveryJobId());
        }
        if (($body['recipe_key'] ?? null) !== 'erp.google-calendar.events.read') {
            return $this->problem(400, 'calendar.invalid_input', 'Unknown Calendar recipe.');
        }
        if (($body['recipe_version'] ?? null) !== 1 || ($body['trigger_type'] ?? null) !== 'on_demand' || ($body['object_type'] ?? null) !== 'calendar_event') {
            return $this->problem(400, 'calendar.invalid_input', 'Event request did not match the frozen Calendar contract.');
        }
        if (($payload['grant_id'] ?? null) !== self::GRANT_ID) {
            return $this->problem(404, 'calendar.resource_not_found', 'Calendar resource is not available to this grant.');
        }
        if (($payload['resource_id'] ?? null) !== $this->currentResourceId()) {
            if (is_string($payload['resource_id'] ?? null) && preg_match('/\Aresource_google_calendar_mock_\d{3}\z/', $payload['resource_id']) === 1) {
                return $this->problem(409, 'calendar.generation_changed', 'Calendar resource belongs to a prior grant generation.');
            }

            return $this->problem(404, 'calendar.resource_not_found', 'Calendar resource is not available to this grant.');
        }
        if (isset($payload['continuation_handle'])) {
            if (
                ($payload['continuation_handle'] ?? null) !== self::NEXT_HANDLE
                || ($body['idempotency_key'] ?? null) !== 'calendar-page:' . self::NEXT_HANDLE
            ) {
                return $this->problem(400, 'calendar.invalid_input', 'Calendar resume must use its exact handle and idempotency key.');
            }

            return $this->accepted($this->eventSecondJobId());
        }
        if (
            ($payload['representation'] ?? null) !== 'series-and-exceptions'
            || ! is_string($payload['time_min'] ?? null)
            || ! is_string($payload['time_max'] ?? null)
            || ! is_int($payload['page_size'] ?? null)
        ) {
            return $this->problem(400, 'calendar.invalid_input', 'Calendar event root request did not match the frozen contract.');
        }

        return $this->accepted($this->eventFirstJobId());
    }

    private function poll(RequestInterface $request): ResponseInterface
    {
        if (! $this->queryHasTenant($request)) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }
        $jobId = substr($request->getUri()->getPath(), strlen('/v1/sync-jobs/'));
        if (! in_array($jobId, [$this->discoveryJobId(), $this->discoverySecondJobId(), $this->eventFirstJobId(), $this->eventSecondJobId()], true)) {
            return $this->problem(404, 'sync_job.not_found', 'Unknown mock job.');
        }
        $this->polls[$jobId] = ($this->polls[$jobId] ?? 0) + 1;
        if (isset($this->pollingPlans[$jobId]) && $this->pollingPlans[$jobId] !== []) {
            $status = array_shift($this->pollingPlans[$jobId]);
        } else {
            $status = $this->polls[$jobId] === 1 ? 'processing' : 'completed';
        }
        $manifest = $status === 'completed' ? $this->manifestFor($jobId) : null;

        return $this->json(200, array_replace($this->job($jobId, $status, $manifest), $this->polledJobFields[$jobId] ?? []));
    }

    private function page(RequestInterface $request): ResponseInterface
    {
        if (! $this->queryHasTenant($request)) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }
        $pageId = substr($request->getUri()->getPath(), strlen('/v1/calendar/pages/'));
        if ($this->expireEventPage && $pageId === $this->eventFirstPageId()) {
            return $this->problem(410, 'calendar.page_expired', 'This synthetic Calendar traversal expired; start a new root read.');
        }
        if ($pageId === $this->discoveryPageId()) {
            return $this->json(200, [
                'page_id' => $pageId,
                'grant_id' => self::GRANT_ID,
                'resource_id' => null,
                'representation' => 'calendar-list',
                'items' => [],
                'next_page_handle' => self::NEXT_DISCOVERY_HANDLE,
                'created_at' => $this->createdAtMs + 300 + $this->epoch,
                'expires_at' => $this->expiresAt(),
            ]);
        }
        if ($pageId === $this->discoverySecondPageId()) {
            return $this->json(200, [
                'page_id' => $pageId,
                'grant_id' => self::GRANT_ID,
                'resource_id' => null,
                'representation' => 'calendar-list',
                'items' => [[
                    'identity' => [
                        'provider' => 'google-calendar',
                        'appId' => $this->appId,
                        'tenantId' => $this->tenantId,
                        'grantId' => self::GRANT_ID,
                        'principalId' => 'principal_google_calendar_mock',
                        'calendarId' => self::CALENDAR_ID,
                    ],
                    'title' => 'Synthetic team calendar',
                    'accessRole' => 'reader',
                ]],
                'next_page_handle' => null,
                'created_at' => $this->createdAtMs + 350 + $this->epoch,
                'expires_at' => $this->expiresAt(),
            ]);
        }
        if ($pageId === $this->eventFirstPageId()) {
            return $this->json(200, [
                'page_id' => $pageId,
                'grant_id' => self::GRANT_ID,
                'resource_id' => $this->currentResourceId(),
                'representation' => 'series-and-exceptions',
                'items' => [],
                'next_page_handle' => self::NEXT_HANDLE,
                'created_at' => $this->createdAtMs + 400 + $this->epoch,
                'expires_at' => $this->expiresAt(),
            ]);
        }
        if ($pageId === $this->eventSecondPageId()) {
            return $this->json(200, [
                'page_id' => $pageId,
                'grant_id' => self::GRANT_ID,
                'resource_id' => $this->currentResourceId(),
                'representation' => 'series-and-exceptions',
                'items' => [[
                    'identity' => [
                        'provider' => 'google-calendar',
                        'appId' => $this->appId,
                        'tenantId' => $this->tenantId,
                        'grantId' => self::GRANT_ID,
                        'principalId' => 'principal_google_calendar_mock',
                        'calendarId' => self::CALENDAR_ID,
                        'eventId' => 'event_synthetic_001',
                    ],
                    'status' => 'confirmed',
                    'title' => 'Synthetic series master',
                    'start' => ['kind' => 'instant', 'dateTime' => '2026-03-10T09:00:00-07:00'],
                    'end' => ['kind' => 'instant', 'dateTime' => '2026-03-10T10:00:00-07:00'],
                    'recurrence' => ['kind' => 'series', 'rules' => ['RRULE:FREQ=WEEKLY']],
                ]],
                'next_page_handle' => null,
                'created_at' => $this->createdAtMs + 500 + $this->epoch,
                'expires_at' => $this->expiresAt(),
            ]);
        }

        return $this->problem(404, 'calendar.page_not_found', 'Unknown mock page.');
    }

    private function discoveryJobId(): string
    {
        return sprintf('job_calendar_discovery_%03d', $this->epoch);
    }

    private function discoverySecondJobId(): string
    {
        return sprintf('job_calendar_discovery_second_%03d', $this->epoch);
    }

    private function eventFirstJobId(): string
    {
        return sprintf('job_calendar_events_first_%03d', $this->epoch);
    }

    private function eventSecondJobId(): string
    {
        return sprintf('job_calendar_events_second_%03d', $this->epoch);
    }

    private function discoveryPageId(): string
    {
        return $this->pageId('discovery-first');
    }

    private function discoverySecondPageId(): string
    {
        return $this->pageId('discovery-second');
    }

    private function eventFirstPageId(): string
    {
        return $this->pageId('events-first');
    }

    private function eventSecondPageId(): string
    {
        return $this->pageId('events-second');
    }

    private function pageId(string $kind): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', "calendar-mock:{$kind}:{$this->epoch}", true)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function manifestFor(string $jobId): array
    {
        $pageId = match ($jobId) {
            $this->discoveryJobId() => $this->discoveryPageId(),
            $this->discoverySecondJobId() => $this->discoverySecondPageId(),
            $this->eventFirstJobId() => $this->eventFirstPageId(),
            $this->eventSecondJobId() => $this->eventSecondPageId(),
        };

        return [
            'kind' => 'calendar-page',
            'version' => 1,
            'page_id' => $pageId,
            'page_url' => '/v1/calendar/pages/' . rawurlencode($pageId) . '?tenant_id=' . rawurlencode($this->tenantId),
            'expires_at' => $this->expiresAt(),
        ];
    }

    private function expiresAt(): int
    {
        return $this->pageExpiresAt;
    }

    private function accepted(string $jobId): ResponseInterface
    {
        return $this->json(202, [
            'job_id' => $jobId,
            'correlation_id' => 'cor_calendar_mock',
            'status' => 'queued',
            'polling_url' => '/v1/sync-jobs/' . rawurlencode($jobId) . '?tenant_id=' . rawurlencode($this->tenantId),
        ]);
    }

    /** @return array<string, mixed> */
    private function job(string $jobId, string $status, ?array $manifest): array
    {
        return [
            'id' => $jobId,
            'app_id' => $this->appId,
            'tenant_id' => $this->tenantId,
            'provider_key' => 'google-calendar',
            'connection_id' => self::GRANT_ID,
            'operation_key' => str_contains($jobId, 'discovery') ? 'google-calendar.calendars.list' : 'google-calendar.events.read',
            'recipe_key' => str_contains($jobId, 'discovery') ? 'erp.google-calendar.calendars.list' : 'erp.google-calendar.events.read',
            'recipe_version' => 1,
            'object_type' => str_contains($jobId, 'discovery') ? 'calendar' : 'calendar_event',
            'internal_object_id' => str_contains($jobId, 'discovery') ? self::GRANT_ID : $this->currentResourceId(),
            'external_object_id' => null,
            'status' => $status,
            'attempt_count' => $status === 'completed' ? 1 : 0,
            'last_error_code' => null,
            'created_at' => $this->createdAtMs + 250,
            'completed_at' => $status === 'completed' ? $this->createdAtMs + 251 : null,
            'result_status' => $manifest === null ? 'none' : ($this->completedResultStatuses[$jobId] ?? 'available'),
            'result' => $manifest,
            'steps' => [],
        ];
    }

    private function queryHasTenant(RequestInterface $request): bool
    {
        parse_str($request->getUri()->getQuery(), $query);

        return ($query['tenant_id'] ?? null) === $this->tenantId;
    }

    /** @return array<string, mixed> */
    private function body(RequestInterface $request): array
    {
        try {
            $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($body) && ! array_is_list($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function problem(int $status, string $code, string $detail): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode([
                'type' => "urn:ir:error:{$code}",
                'title' => 'Mock runtime problem',
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
                'request_id' => 'req_calendar_mock',
                'correlation_id' => null,
                'instance' => null,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
