<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\GoogleCalendar;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionStatusJourney;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/** Product-held identifiers. The provider credential is never part of this object. */
final readonly class CalendarRuntimeIdentity
{
    public function __construct(
        public string $appId,
        public string $keyId,
        public string $tenantId,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("{$name} must not be empty");
            }
        }
    }
}

final readonly class CalendarGrant
{
    public function __construct(
        public string $grantId,
        public string $connectionId,
        public string $status,
        public bool $canDiscoverCalendars,
        public bool $canReadEvents,
        public int $createdAt,
        public int $updatedAt,
    ) {}
}

final readonly class CalendarResource
{
    public function __construct(
        public string $resourceId,
        public string $grantId,
        public string $calendarId,
        public string $status,
        public int $createdAt,
        public int $updatedAt,
    ) {}
}

/** Persist this intent with the accepted job; it contains no provider continuation or token. */
final readonly class CalendarOperationIntent
{
    public function __construct(
        public string $kind,
        public string $grantId,
        public ?string $resourceId,
        public ?string $continuationHandle,
        public string $idempotencyKey,
    ) {}
}

final readonly class CalendarJobSubmission
{
    /** @param array<string, mixed> $request */
    public function __construct(
        public string $jobId,
        public string $pollingUrl,
        public string $status,
        public ?string $correlationId,
        public CalendarOperationIntent $intent,
        public array $request,
    ) {}
}

final readonly class CalendarPageManifest
{
    public function __construct(
        public string $pageId,
        public string $pageUrl,
        public int $expiresAt,
    ) {}
}

/**
 * The consumer retains only the public page DTO and the opaque runtime handle.
 * It never receives or constructs a provider page token or adapter continuation.
 *
 * @phpstan-type PublicItem array<string, mixed>
 */
final readonly class CalendarPublicPage
{
    /** @param list<PublicItem> $items */
    public function __construct(
        public string $pageId,
        public string $grantId,
        public ?string $resourceId,
        public string $representation,
        public array $items,
        public ?string $nextPageHandle,
        public int $createdAt,
        public int $expiresAt,
    ) {}
}

class CalendarRuntimeException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $problemCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class CalendarReconnectRequiredException extends CalendarRuntimeException {}

final class CalendarResultExpiredException extends CalendarRuntimeException {}

/** Keep this submission and poll the same job again; never infer that it is safe to resubmit. */
class CalendarPollingPendingException extends \RuntimeException
{
    public function __construct(
        public readonly CalendarJobSubmission $submission,
        public readonly string $lastStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Calendar job is still open or has a future status; poll the original job again', 0, $previous);
    }
}

/** A child poll is pending; retain prior pages and retry this same submission. */
final class CalendarTraversalPendingException extends CalendarPollingPendingException
{
    /** @param list<CalendarPublicPage> $pages */
    public function __construct(
        public readonly string $kind,
        public readonly array $pages,
        CalendarPollingPendingException $pending,
    ) {
        parent::__construct($pending->submission, $pending->lastStatus, $pending);
    }
}

/** The fetched pages are usable; resume from nextPageHandle without starting a new root. */
final class CalendarTraversalLimitException extends \RuntimeException
{
    /** @param list<CalendarPublicPage> $pages */
    public function __construct(
        public readonly string $kind,
        public readonly array $pages,
        public readonly string $nextPageHandle,
        public readonly CalendarJobSubmission $lastSubmission,
    ) {
        parent::__construct("Calendar {$kind} traversal reached its local page bound; resume from the saved handle");
    }
}

/**
 * Credential-free consumer example for the frozen C1–C5 Calendar contract.
 *
 * This offline contract fixture begins with an already-authorized grant; the
 * public connect-session endpoint does not accept Google Calendar. It does not
 * make a provider request or contain an OAuth client secret. Client::send()
 * owns fresh HMAC material on every request, including each polling request.
 */
final class CalendarReadJourney
{
    private const PROVIDER_KEY = 'google-calendar';

    private const EXPIRY_CODES = [
        'calendar.result_expired',
        'calendar.continuation_expired',
        'calendar.discovery_expired',
        'calendar.page_expired',
    ];

    private const DISCOVERY_RECIPE = 'erp.google-calendar.calendars.list';

    private const EVENTS_RECIPE = 'erp.google-calendar.events.read';

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep
     */
    public function __construct(
        private readonly Client $client,
        private readonly CalendarRuntimeIdentity $identity,
        private readonly ConnectionStatusJourney $connections,
        ?\Closure $sleep = null,
        private readonly int $maxPollAttempts = 15,
        private readonly int $maxTraversalPages = 20,
    ) {
        if ($maxPollAttempts < 1 || $maxTraversalPages < 1) {
            throw new \InvalidArgumentException('Calendar poll and traversal bounds must be positive');
        }
        $this->sleep = $sleep ?? static function (float $seconds): void {
            [$whole, $micro] = self::sleepComponents($seconds);
            if ($whole > 0) {
                sleep($whole);
            }
            if ($micro > 0) {
                usleep($micro);
            }
        };
    }

    /** @return array{int, int} */
    private static function sleepComponents(float $seconds): array
    {
        if ($seconds <= 0) {
            return [0, 0];
        }
        $whole = (int) floor($seconds);
        $micro = (int) round(($seconds - $whole) * 1_000_000);
        if ($micro >= 1_000_000) {
            $whole++;
            $micro -= 1_000_000;
        }

        return [$whole, $micro];
    }

    /** Reads an already-authorized grant; consent provisioning is outside this public C5 example. */
    public function getConnectedGrant(string $grantId): CalendarGrant
    {
        $this->requireNonEmpty($grantId, 'grantId');
        $connection = $this->connections->getConnection($grantId);
        if ($connection->providerKey !== self::PROVIDER_KEY) {
            throw new \UnexpectedValueException('connection is not a Google Calendar grant');
        }
        if ($connection->runtimeStatus === 'reconnect_required') {
            throw new CalendarReconnectRequiredException(409, 'calendar.reconnect_required', 'Calendar grant requires reconnect');
        }
        if ($connection->runtimeStatus !== 'connected') {
            throw new CalendarRuntimeException(403, 'calendar.grant_disabled', 'Calendar grant is not connected');
        }

        $grant = $this->getGrant($grantId, $connection->id);
        if ($grant->status === 'reconnect_required') {
            throw new CalendarReconnectRequiredException(409, 'calendar.reconnect_required', 'Calendar grant requires reconnect');
        }
        if ($grant->status !== 'connected') {
            throw new CalendarRuntimeException(403, 'calendar.grant_disabled', 'Calendar grant is not connected');
        }

        return $grant;
    }

    public function getGrant(string $grantId, ?string $expectedConnectionId = null): CalendarGrant
    {
        $this->requireNonEmpty($grantId, 'grantId');
        $response = $this->send('GET', $this->tenantPath('/v1/calendar/grants/' . rawurlencode($grantId)));
        if ($response->getStatusCode() !== 200) {
            throw self::responseException($response, 'Calendar grant could not be read');
        }
        $body = self::jsonObject($response);
        $grant = new CalendarGrant(
            self::requiredString($body, 'grant_id'),
            self::requiredString($body, 'connection_id'),
            self::requiredString($body, 'status'),
            self::requiredBool(self::requiredArray($body, 'capabilities'), 'discover_calendars'),
            self::requiredBool(self::requiredArray($body, 'capabilities'), 'read_events'),
            self::requiredInt($body, 'created_at'),
            self::requiredInt($body, 'updated_at'),
        );
        if (! hash_equals($grantId, $grant->grantId)) {
            throw new \UnexpectedValueException('Calendar grant response did not identify the requested grant');
        }
        if ($expectedConnectionId !== null && ! hash_equals($expectedConnectionId, $grant->connectionId)) {
            throw new \UnexpectedValueException('Calendar grant connection does not match the requested connection');
        }

        return $grant;
    }

    public function submitDiscovery(CalendarGrant $grant, string $idempotencyKey, int $pageSize = 10): CalendarJobSubmission
    {
        $this->requireCapability($grant, 'discover');
        $this->validatePageSize($pageSize);
        $intent = new CalendarOperationIntent('discover', $grant->grantId, null, null, $this->initialIdempotencyKey($idempotencyKey));

        return $this->submitOperation($intent, [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => self::PROVIDER_KEY,
            'connection_id' => $grant->connectionId,
            'recipe_key' => self::DISCOVERY_RECIPE,
            'recipe_version' => 1,
            'trigger_type' => 'on_demand',
            'object_type' => 'calendar',
            'internal_object_id' => $grant->grantId,
            'idempotency_key' => $intent->idempotencyKey,
            'payload' => ['grant_id' => $grant->grantId, 'page_size' => $pageSize],
        ]);
    }

    public function resumeDiscovery(CalendarGrant $grant, string $handle): CalendarJobSubmission
    {
        $this->requireCapability($grant, 'discover');
        $this->validateHandle($handle);
        $intent = new CalendarOperationIntent('resume-discovery', $grant->grantId, null, $handle, 'calendar-page:' . $handle);

        return $this->submitOperation($intent, [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => self::PROVIDER_KEY,
            'connection_id' => $grant->connectionId,
            'recipe_key' => self::DISCOVERY_RECIPE,
            'recipe_version' => 1,
            'trigger_type' => 'on_demand',
            'object_type' => 'calendar',
            'internal_object_id' => $grant->grantId,
            'idempotency_key' => $intent->idempotencyKey,
            'payload' => [
                'grant_id' => $grant->grantId,
                'continuation_handle' => $handle,
            ],
        ]);
    }

    public function selectResource(
        CalendarGrant $grant,
        string $discoveryPageId,
        string $calendarId,
        string $idempotencyKey,
    ): CalendarResource {
        $this->requireCapability($grant, 'read-events');
        $this->requireNonEmpty($discoveryPageId, 'discoveryPageId');
        $this->requireNonEmpty($calendarId, 'calendarId');
        $response = $this->send(
            'POST',
            '/v1/calendar/grants/' . rawurlencode($grant->grantId) . '/resources',
            [
                'tenant_id' => $this->identity->tenantId,
                'discovery_page_id' => $discoveryPageId,
                'calendar_id' => $calendarId,
                'idempotency_key' => $this->idempotencyKey($idempotencyKey),
            ],
        );
        if ($response->getStatusCode() !== 200) {
            throw self::responseException($response, 'Calendar resource could not be selected');
        }
        $body = self::jsonObject($response);
        $resource = new CalendarResource(
            self::requiredString($body, 'resource_id'),
            self::requiredString($body, 'grant_id'),
            self::requiredString($body, 'calendar_id'),
            self::requiredString($body, 'status'),
            self::requiredInt($body, 'created_at'),
            self::requiredInt($body, 'updated_at'),
        );
        if (
            ! hash_equals($grant->grantId, $resource->grantId)
            || ! hash_equals($calendarId, $resource->calendarId)
            || $resource->status !== 'selected'
        ) {
            throw new \UnexpectedValueException('Calendar selection response is not a selected binding for this grant and calendar');
        }

        return $resource;
    }

    public function submitEventRead(
        CalendarGrant $grant,
        CalendarResource $resource,
        string $timeMin,
        string $timeMax,
        string $idempotencyKey,
        int $pageSize = 10,
    ): CalendarJobSubmission {
        $this->requireCapability($grant, 'read-events');
        $this->assertSelectedResource($grant, $resource);
        $this->validateEventWindow($timeMin, $timeMax);
        $this->validatePageSize($pageSize);
        $intent = new CalendarOperationIntent(
            'read-events',
            $grant->grantId,
            $resource->resourceId,
            null,
            $this->initialIdempotencyKey($idempotencyKey),
        );

        return $this->submitOperation($intent, [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => self::PROVIDER_KEY,
            'connection_id' => $grant->connectionId,
            'recipe_key' => self::EVENTS_RECIPE,
            'recipe_version' => 1,
            'trigger_type' => 'on_demand',
            'object_type' => 'calendar_event',
            'internal_object_id' => $resource->resourceId,
            'idempotency_key' => $intent->idempotencyKey,
            'payload' => [
                'grant_id' => $grant->grantId,
                'resource_id' => $resource->resourceId,
                'representation' => 'series-and-exceptions',
                'time_min' => $timeMin,
                'time_max' => $timeMax,
                'page_size' => $pageSize,
            ],
        ]);
    }

    public function resumeEventRead(CalendarGrant $grant, CalendarResource $resource, string $handle): CalendarJobSubmission
    {
        $this->requireCapability($grant, 'read-events');
        $this->assertSelectedResource($grant, $resource);
        $this->validateHandle($handle);
        $intent = new CalendarOperationIntent(
            'resume-events',
            $grant->grantId,
            $resource->resourceId,
            $handle,
            'calendar-page:' . $handle,
        );

        return $this->submitOperation($intent, [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => self::PROVIDER_KEY,
            'connection_id' => $grant->connectionId,
            'recipe_key' => self::EVENTS_RECIPE,
            'recipe_version' => 1,
            'trigger_type' => 'on_demand',
            'object_type' => 'calendar_event',
            'internal_object_id' => $resource->resourceId,
            'idempotency_key' => $intent->idempotencyKey,
            'payload' => [
                'grant_id' => $grant->grantId,
                'resource_id' => $resource->resourceId,
                'continuation_handle' => $handle,
            ],
        ]);
    }

    public function pollForManifest(CalendarJobSubmission $submission): CalendarPageManifest
    {
        $lastStatus = $submission->status;
        for ($attempt = 1; $attempt <= $this->maxPollAttempts; $attempt++) {
            $response = $this->send('GET', $submission->pollingUrl);
            if ($response->getStatusCode() !== 200) {
                throw self::responseException($response, 'Calendar job polling failed');
            }
            $body = self::jsonObject($response);
            $this->assertJobIdentity($body, $submission);
            $status = self::requiredString($body, 'status');
            $lastStatus = $status;
            if ($status === 'completed') {
                return $this->manifest($body);
            }
            if (in_array($status, ['failed', 'dead_lettered', 'cancelled', 'manually_resolved'], true)) {
                $code = self::nullableString($body, 'last_error_code') ?? 'unknown';
                $message = "Calendar job ended as {$status} ({$code})";
                if ($code === 'calendar.reconnect_required') {
                    throw new CalendarReconnectRequiredException(409, $code, $message);
                }
                if (in_array($code, self::EXPIRY_CODES, true)) {
                    throw new CalendarResultExpiredException(410, $code, $message);
                }
                throw new CalendarRuntimeException(409, $code, $message);
            }
            if (! in_array($status, ['queued', 'processing'], true)) {
                throw new CalendarPollingPendingException($submission, $status);
            }
            if ($attempt < $this->maxPollAttempts) {
                ($this->sleep)(min(0.5 * (2 ** min($attempt - 1, 2)), 2.0));
            }
        }

        throw new CalendarPollingPendingException($submission, $lastStatus);
    }

    public function fetchPage(CalendarPageManifest $manifest): CalendarPublicPage
    {
        $response = $this->send('GET', $manifest->pageUrl);
        if ($response->getStatusCode() !== 200) {
            throw self::responseException($response, 'Calendar page could not be read');
        }
        $body = self::jsonObject($response);
        $items = $body['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new \UnexpectedValueException('Calendar page response items must be a list');
        }
        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new \UnexpectedValueException('Calendar page response contains a non-object item');
            }
        }
        $page = new CalendarPublicPage(
            self::requiredString($body, 'page_id'),
            self::requiredString($body, 'grant_id'),
            self::nullableString($body, 'resource_id'),
            self::requiredString($body, 'representation'),
            $items,
            self::nullableString($body, 'next_page_handle'),
            self::requiredInt($body, 'created_at'),
            self::requiredInt($body, 'expires_at'),
        );
        if (! hash_equals($manifest->pageId, $page->pageId) || $page->expiresAt !== $manifest->expiresAt) {
            throw new \UnexpectedValueException('Calendar page does not match its polling manifest');
        }
        if ($page->nextPageHandle !== null && ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $page->nextPageHandle)) {
            throw new \UnexpectedValueException('Calendar page next_page_handle is not a safe runtime handle');
        }

        return $page;
    }

    /** @return list<CalendarPublicPage> */
    public function collectEventPages(
        CalendarGrant $grant,
        CalendarResource $resource,
        CalendarJobSubmission $rootSubmission,
    ): array {
        if (
            ! in_array($rootSubmission->intent->kind, ['read-events', 'resume-events'], true)
            || ! hash_equals($grant->grantId, $rootSubmission->intent->grantId)
            || $rootSubmission->intent->resourceId === null
            || ! hash_equals($resource->resourceId, $rootSubmission->intent->resourceId)
        ) {
            throw new \InvalidArgumentException('event submission does not belong to this selected resource');
        }
        $pages = [];
        $submission = $rootSubmission;
        for ($pageNumber = 1; $pageNumber <= $this->maxTraversalPages; $pageNumber++) {
            try {
                $manifest = $this->pollForManifest($submission);
            } catch (CalendarPollingPendingException $pending) {
                throw new CalendarTraversalPendingException('events', $pages, $pending);
            }
            $page = $this->fetchPage($manifest);
            if (
                ! hash_equals($grant->grantId, $page->grantId)
                || $page->resourceId === null
                || ! hash_equals($resource->resourceId, $page->resourceId)
                || $page->representation !== 'series-and-exceptions'
            ) {
                throw new \UnexpectedValueException('Calendar event page does not match its persisted request intent');
            }
            $pages[] = $page;
            // An empty page can still have a continuation. Completion is only a null handle.
            if ($page->nextPageHandle === null) {
                return $pages;
            }
            if ($pageNumber === $this->maxTraversalPages) {
                throw new CalendarTraversalLimitException('events', $pages, $page->nextPageHandle, $submission);
            }
            $submission = $this->resumeEventRead($grant, $resource, $page->nextPageHandle);
        }

        throw new \RuntimeException('Calendar traversal exceeded its local page bound');
    }

    /** @return list<CalendarPublicPage> */
    public function collectDiscoveryPages(CalendarGrant $grant, CalendarJobSubmission $rootSubmission): array
    {
        if (
            ! in_array($rootSubmission->intent->kind, ['discover', 'resume-discovery'], true)
            || ! hash_equals($grant->grantId, $rootSubmission->intent->grantId)
        ) {
            throw new \InvalidArgumentException('discovery submission does not belong to this grant');
        }
        $pages = [];
        $submission = $rootSubmission;
        for ($pageNumber = 1; $pageNumber <= $this->maxTraversalPages; $pageNumber++) {
            try {
                $manifest = $this->pollForManifest($submission);
            } catch (CalendarPollingPendingException $pending) {
                throw new CalendarTraversalPendingException('discovery', $pages, $pending);
            }
            $page = $this->fetchPage($manifest);
            if (! hash_equals($grant->grantId, $page->grantId) || $page->resourceId !== null || $page->representation !== 'calendar-list') {
                throw new \UnexpectedValueException('Calendar discovery page does not match its persisted request intent');
            }
            $pages[] = $page;
            if ($page->nextPageHandle === null) {
                return $pages;
            }
            if ($pageNumber === $this->maxTraversalPages) {
                throw new CalendarTraversalLimitException('discovery', $pages, $page->nextPageHandle, $submission);
            }
            $submission = $this->resumeDiscovery($grant, $page->nextPageHandle);
        }

        throw new \RuntimeException('Calendar discovery traversal exceeded its local page bound');
    }

    /** @param array<string, mixed> $body */
    private function assertJobIdentity(array $body, CalendarJobSubmission $submission): void
    {
        $discovery = in_array($submission->intent->kind, ['discover', 'resume-discovery'], true);
        if (! $discovery && ! in_array($submission->intent->kind, ['read-events', 'resume-events'], true)) {
            throw new \UnexpectedValueException('saved Calendar job has an unknown operation intent');
        }
        $internalObjectId = $discovery ? $submission->intent->grantId : $submission->intent->resourceId;
        if ($internalObjectId === null) {
            throw new \UnexpectedValueException('saved Calendar job has no internal object ID');
        }
        $expected = [
            'id' => $submission->jobId,
            'app_id' => $this->identity->appId,
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => self::PROVIDER_KEY,
            'connection_id' => self::requiredString($submission->request, 'connection_id'),
            'operation_key' => $discovery ? 'google-calendar.calendars.list' : 'google-calendar.events.read',
            'recipe_key' => $discovery ? self::DISCOVERY_RECIPE : self::EVENTS_RECIPE,
            'recipe_version' => 1,
            'object_type' => $discovery ? 'calendar' : 'calendar_event',
            'internal_object_id' => $internalObjectId,
        ];
        foreach ($expected as $field => $value) {
            if (($body[$field] ?? null) !== $value) {
                throw new \UnexpectedValueException("Calendar job {$field} does not match the saved submission");
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function submitOperation(CalendarOperationIntent $intent, array $request): CalendarJobSubmission
    {
        $response = $this->send('POST', '/v1/operations/execute', $request);
        if ($response->getStatusCode() !== 202) {
            throw self::responseException($response, 'Calendar operation was not accepted');
        }
        $body = self::jsonObject($response);
        $jobId = self::requiredString($body, 'job_id');
        $pollingUrl = self::requiredString($body, 'polling_url');
        $this->assertTenantScopedRelativeUrl($pollingUrl, '/v1/sync-jobs/' . rawurlencode($jobId));

        return new CalendarJobSubmission(
            $jobId,
            $pollingUrl,
            self::requiredString($body, 'status'),
            self::nullableString($body, 'correlation_id'),
            $intent,
            $request,
        );
    }

    /** @param array<string, mixed> $body */
    private function manifest(array $body): CalendarPageManifest
    {
        $resultStatus = $body['result_status'] ?? null;
        if (! is_string($resultStatus) || $resultStatus === '' || $resultStatus === 'none') {
            throw new \UnexpectedValueException('completed Calendar job did not expose a page manifest');
        }
        $result = self::requiredArray($body, 'result');
        if (($result['kind'] ?? null) !== 'calendar-page' || ($result['version'] ?? null) !== 1) {
            throw new \UnexpectedValueException('completed Calendar job exposed another result kind');
        }
        $pageId = self::requiredString($result, 'page_id');
        $pageUrl = self::requiredString($result, 'page_url');
        $this->assertTenantScopedRelativeUrl($pageUrl, '/v1/calendar/pages/' . rawurlencode($pageId));

        return new CalendarPageManifest($pageId, $pageUrl, self::requiredInt($result, 'expires_at'));
    }

    private function requireCapability(CalendarGrant $grant, string $capability): void
    {
        if ($grant->status === 'reconnect_required') {
            throw new CalendarReconnectRequiredException(409, 'calendar.reconnect_required', 'Calendar grant requires reconnect');
        }
        if ($grant->status !== 'connected') {
            throw new CalendarRuntimeException(403, 'calendar.grant_disabled', 'Calendar grant is not connected');
        }
        $allowed = $capability === 'discover' ? $grant->canDiscoverCalendars : $grant->canReadEvents;
        if (! $allowed) {
            throw new CalendarRuntimeException(403, 'calendar.insufficient_scopes', 'Calendar grant lacks the required read capability');
        }
    }

    private function assertSelectedResource(CalendarGrant $grant, CalendarResource $resource): void
    {
        if (! hash_equals($grant->grantId, $resource->grantId) || $resource->status !== 'selected') {
            throw new CalendarRuntimeException(403, 'calendar.resource_denied', 'Calendar resource is not selected for this grant');
        }
    }

    private function validatePageSize(int $pageSize): void
    {
        if ($pageSize < 1 || $pageSize > 10) {
            throw new \InvalidArgumentException('Calendar pageSize must be between 1 and 10');
        }
    }

    private function validateEventWindow(string $timeMin, string $timeMax): void
    {
        $start = $this->strictCalendarTime($timeMin);
        $end = $this->strictCalendarTime($timeMax);
        if ($start === false || $end === false || $end <= $start || $end - $start > 366 * 86_400) {
            throw new \InvalidArgumentException('Calendar event window must be positive and at most 366 elapsed days');
        }
    }

    private function strictCalendarTime(string $value): int|false
    {
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|[+-](\d{2}):(\d{2}))\z/D', $value, $parts)) {
            return false;
        }
        if (
            (int) $parts[1] < 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23
            || (int) $parts[5] > 59
            || (int) $parts[6] > 59
            || ($parts[7] !== 'Z' && ((int) $parts[8] > 23 || (int) $parts[9] > 59))
        ) {
            return false;
        }

        return strtotime($value);
    }

    private function validateHandle(string $handle): void
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $handle)) {
            throw new \InvalidArgumentException('continuation handle is not a safe runtime page handle');
        }
    }

    private function idempotencyKey(string $value): string
    {
        // Zod's z.string().max(128) counts UTF-16 code units, not UTF-8 bytes.
        $codePoints = preg_match_all('/./us', $value);
        $astralPoints = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($value === '' || $codePoints === false || $astralPoints === false || $codePoints + $astralPoints > 128) {
            throw new \InvalidArgumentException('Calendar idempotency key must be 1 to 128 UTF-16 code units');
        }

        return $value;
    }

    private function initialIdempotencyKey(string $value): string
    {
        $key = $this->idempotencyKey($value);
        if (str_starts_with($key, 'calendar-page:')) {
            throw new \InvalidArgumentException('calendar-page: is reserved for Calendar resume idempotency keys');
        }

        return $key;
    }

    private function tenantPath(string $path): string
    {
        return $path . '?tenant_id=' . rawurlencode($this->identity->tenantId);
    }

    private function assertTenantScopedRelativeUrl(string $url, string $expectedPath): void
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? null) !== $expectedPath
            || ! isset($parts['query'])
        ) {
            throw new \UnexpectedValueException('runtime URL is not the expected tenant-scoped relative path');
        }
        $tenantIds = self::queryValues($parts['query'], 'tenant_id');
        if (count($tenantIds) !== 1 || ! hash_equals($this->identity->tenantId, $tenantIds[0])) {
            throw new \UnexpectedValueException('runtime URL is not scoped to the configured tenant');
        }
    }

    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        try {
            return $this->client->send($method, $path, $body);
        } catch (ClientExceptionInterface $exception) {
            throw new \RuntimeException('Calendar runtime transport failed; retain the existing intent and reconcile.', previous: $exception);
        }
    }

    private function requireNonEmpty(string $value, string $name): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$name} must not be empty");
        }
    }

    /** @return array<string, mixed> */
    private static function jsonObject(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('runtime returned invalid JSON', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('runtime response must be a JSON object');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $body */
    private static function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("runtime response has no usable {$key}");
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("runtime response {$key} must be a non-empty string or null");
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function requiredInt(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (! is_int($value)) {
            throw new \UnexpectedValueException("runtime response {$key} must be an integer");
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function requiredBool(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        if (! is_bool($value)) {
            throw new \UnexpectedValueException("runtime response {$key} must be a boolean");
        }

        return $value;
    }

    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    private static function requiredArray(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (! is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("runtime response {$key} must be a JSON object");
        }

        return $value;
    }

    /** @return list<string> */
    private static function queryValues(string $query, string $name): array
    {
        $values = [];
        foreach (explode('&', $query) as $field) {
            [$rawName, $rawValue] = array_pad(explode('=', $field, 2), 2, '');
            if (urldecode($rawName) === $name) {
                $values[] = urldecode($rawValue);
            }
        }

        return $values;
    }

    private static function responseException(ResponseInterface $response, string $fallback): CalendarRuntimeException
    {
        $code = null;
        $detail = $fallback;
        try {
            $body = self::jsonObject($response);
            $code = is_string($body['code'] ?? null) ? $body['code'] : null;
            $detail = is_string($body['detail'] ?? null) ? $body['detail'] : $fallback;
        } catch (\UnexpectedValueException) {
            // Preserve the safe HTTP status even when an intermediary did not return problem+json.
        }
        if ($response->getStatusCode() === 409 && $code === 'calendar.reconnect_required') {
            return new CalendarReconnectRequiredException(409, $code, $detail);
        }
        if ($response->getStatusCode() === 410 && in_array($code, self::EXPIRY_CODES, true)) {
            return new CalendarResultExpiredException(410, $code, $detail);
        }

        return new CalendarRuntimeException($response->getStatusCode(), $code, $detail);
    }
}
