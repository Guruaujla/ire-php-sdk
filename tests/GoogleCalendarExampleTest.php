<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionStatusJourney;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarGrant;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarPublicPage;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarPollingPendingException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarReadJourney;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarReconnectRequiredException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarResource;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarResultExpiredException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarRuntimeException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarRuntimeIdentity;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarTraversalLimitException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarTraversalPendingException;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../examples/connection-status/ConnectionStatusJourney.php';
require_once __DIR__ . '/../examples/google-calendar/CalendarReadJourney.php';
require_once __DIR__ . '/../examples/google-calendar/MockRuntimeTransport.php';

final class GoogleCalendarExampleTest extends TestCase
{
    /** @return array{CalendarReadJourney, MockRuntimeTransport, CalendarRuntimeIdentity} */
    private function journey(?MockRuntimeTransport $transport = null, int $maxTraversalPages = 20, ?\Closure $sleep = null): array
    {
        $identity = new CalendarRuntimeIdentity('app_erp_calendar_test', 'key_calendar_test', 'tenant_calendar_test');
        $transport ??= new MockRuntimeTransport($identity->tenantId, $identity->appId);
        $factory = new HttpFactory();
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.example.test',
            $identity->appId,
            $identity->keyId,
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
            RetryPolicy::disabled(),
        );
        $connections = new ConnectionStatusJourney($client, $identity->tenantId);

        return [
            new CalendarReadJourney(
                $client,
                $identity,
                $connections,
                sleep: $sleep ?? static function (float $seconds): void {},
                maxTraversalPages: $maxTraversalPages,
            ),
            $transport,
            $identity,
        ];
    }

    /** @return array{CalendarReadJourney, MockRuntimeTransport, CalendarRuntimeIdentity, CalendarGrant} */
    private function verifiedGrant(?MockRuntimeTransport $transport = null): array
    {
        [$journey, $mock, $identity] = $this->journey($transport);
        return [$journey, $mock, $identity, $journey->getConnectedGrant($mock->grantId())];
    }

    public function testSyntheticConsumerDiscoversSelectsReadsPollsAndTraversesEmptyContinuedPages(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.discovery.001');
        $discoveryPages = $journey->collectDiscoveryPages($grant, $discovery);
        self::assertCount(2, $discoveryPages);
        $discoveryPage = $discoveryPages[1];
        $calendarId = $this->discoveredCalendarId($discoveryPage);
        self::assertSame($transport->calendarId(), $calendarId);

        $resource = $journey->selectResource(
            $grant,
            $discoveryPage->pageId,
            $transport->calendarId(),
            'erp.calendar.selection.001',
        );
        $root = $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            'erp.calendar.read.001',
        );
        $pages = $journey->collectEventPages($grant, $resource, $root);

        self::assertCount(2, $pages);
        self::assertSame([], $pages[0]->items, 'An empty page with a handle must resume, not terminate.');
        self::assertNotNull($pages[0]->nextPageHandle);
        self::assertCount(1, $pages[1]->items);
        self::assertNull($pages[1]->nextPageHandle);
        foreach ($pages as $page) {
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $page->pageId);
            self::assertGreaterThan(1_000_000_000_000, $page->createdAt);
            self::assertGreaterThan($page->createdAt, $page->expiresAt);
        }

        $requests = $transport->requests();
        $nonces = array_map(static fn($request): string => $request->getHeaderLine('X-IR-Nonce'), $requests);
        self::assertCount(count($nonces), array_unique($nonces), 'Every product call, including polls, gets fresh HMAC material.');

        $resume = $this->onlyResumeRequest($requests, 'erp.google-calendar.events.read');
        self::assertSame('calendar-page:' . $pages[0]->nextPageHandle, $resume['idempotency_key']);
        self::assertSame([
            'grant_id' => $grant->grantId,
            'resource_id' => $resource->resourceId,
            'continuation_handle' => $pages[0]->nextPageHandle,
        ], $resume['payload']);
        $discoveryResume = $this->onlyResumeRequest($requests, 'erp.google-calendar.calendars.list');
        self::assertSame([
            'grant_id' => $grant->grantId,
            'continuation_handle' => $discoveryPages[0]->nextPageHandle,
        ], $discoveryResume['payload']);
        self::assertSame('calendar-page:' . $discoveryPages[0]->nextPageHandle, $discoveryResume['idempotency_key']);
        self::assertStringNotContainsString('pageToken', json_encode($pages, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('providerState', json_encode($pages, JSON_THROW_ON_ERROR));
    }

    public function testUnknownResourceIsRejectedWithoutDisclosingScope(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $foreignResource = new CalendarResource(
            'resource_not_selected',
            $grant->grantId,
            $transport->calendarId(),
            'selected',
            1,
            1,
        );

        try {
            $journey->submitEventRead(
                $grant,
                $foreignResource,
                '2026-03-01T00:00:00Z',
                '2026-04-01T00:00:00Z',
                'erp.calendar.foreign-resource.001',
            );
            self::fail('An unselected resource unexpectedly submitted a Calendar read.');
        } catch (CalendarRuntimeException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('calendar.resource_not_found', $exception->problemCode);
        }
    }

    public function testResourceSelectionRejectsAnotherCalendarInTheSameGrant(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.selection-identity.discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $valid = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.selection-identity.valid.001');
        self::assertSame($transport->calendarId(), $valid->calendarId);

        $transport->setSelectedCalendarIdResponse('another-calendar@example.invalid');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('calendar');
        $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.selection-identity.mismatch.001');
    }

    public function testDiscoveryContinuationAndPublicPageShapes(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $root = $journey->submitDiscovery($grant, 'erp.calendar.shape.discovery.001');
        $pages = $journey->collectDiscoveryPages($grant, $root);

        self::assertCount(2, $pages);
        self::assertSame([], $pages[0]->items);
        self::assertNotNull($pages[0]->nextPageHandle);
        self::assertSame($transport->calendarId(), $this->discoveredCalendarId($pages[1]));
        foreach ($pages as $page) {
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $page->pageId);
            self::assertGreaterThan(1_000_000_000_000, $page->createdAt);
            self::assertGreaterThan($page->createdAt, $page->expiresAt);
        }
    }

    public function testDiscoveryPageBoundDoesNotSubmitAnUntrackedResume(): void
    {
        [$journey, $transport] = $this->journey(maxTraversalPages: 1);
        $grant = $journey->getConnectedGrant($transport->grantId());
        $root = $journey->submitDiscovery($grant, 'erp.calendar.bounded.discovery.001');
        $partial = null;
        try {
            $journey->collectDiscoveryPages($grant, $root);
            self::fail('Discovery ignored its page bound.');
        } catch (CalendarTraversalLimitException $exception) {
            $partial = $exception;
            self::assertSame('discovery', $exception->kind);
            self::assertSame($root, $exception->lastSubmission);
            self::assertCount(1, $exception->pages);
            self::assertSame([], $exception->pages[0]->items);
            self::assertSame($exception->pages[0]->nextPageHandle, $exception->nextPageHandle);
        }
        $executeCalls = array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        );
        self::assertCount(1, $executeCalls);

        $resume = $journey->resumeDiscovery($grant, $partial->nextPageHandle);
        $remaining = $journey->collectDiscoveryPages($grant, $resume);
        self::assertCount(1, $remaining);
        self::assertSame($transport->calendarId(), $this->discoveredCalendarId($remaining[0]));
    }

    public function testEventPageBoundRetainsPartialPagesAndExactResumeHandle(): void
    {
        [$setup, $transport] = $this->journey();
        $grant = $setup->getConnectedGrant($transport->grantId());
        $discovery = $setup->submitDiscovery($grant, 'erp.calendar.event-bound.discovery.001');
        $page = $setup->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $setup->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.event-bound.selection.001');
        [$journey] = $this->journey($transport, maxTraversalPages: 1);
        $root = $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            'erp.calendar.event-bound.read.001',
        );
        $before = count(array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
        $partial = null;
        try {
            $journey->collectEventPages($grant, $resource, $root);
            self::fail('Event read ignored its page bound.');
        } catch (CalendarTraversalLimitException $exception) {
            $partial = $exception;
            self::assertSame('events', $exception->kind);
            self::assertSame($root, $exception->lastSubmission);
            self::assertCount(1, $exception->pages);
            self::assertSame([], $exception->pages[0]->items);
            self::assertSame($exception->pages[0]->nextPageHandle, $exception->nextPageHandle);
        }
        $after = count(array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
        self::assertSame($before, $after, 'The bound must not submit another child job.');

        $resume = $journey->resumeEventRead($grant, $resource, $partial->nextPageHandle);
        $remaining = $journey->collectEventPages($grant, $resource, $resume);
        self::assertCount(1, $remaining);
        self::assertCount(1, $remaining[0]->items);
    }

    public function testDiscoveryKeepsFetchedPagesWhenChildHasFutureOpenStatus(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $transport->setNextDiscoveryResumePollingPlan(['awaiting_capacity', 'completed']);
        $root = $journey->submitDiscovery($grant, 'erp.calendar.pending-child.discovery.001');
        $partial = null;
        try {
            $journey->collectDiscoveryPages($grant, $root);
            self::fail('Future open child status unexpectedly completed discovery.');
        } catch (CalendarTraversalPendingException $exception) {
            $partial = $exception;
            self::assertSame('discovery', $exception->kind);
            self::assertSame('awaiting_capacity', $exception->lastStatus);
            self::assertCount(1, $exception->pages);
            self::assertSame([], $exception->pages[0]->items);
            self::assertSame('resume-discovery', $exception->submission->intent->kind);
            self::assertSame($exception->pages[0]->nextPageHandle, $exception->submission->intent->continuationHandle);
        }
        $before = count(array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
        self::assertSame(2, $before);
        $remaining = $journey->collectDiscoveryPages($grant, $partial->submission);
        self::assertCount(1, $remaining);
        self::assertSame($transport->calendarId(), $this->discoveredCalendarId($remaining[0]));
        self::assertCount($before, array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
    }

    public function testEventsKeepFetchedPagesWhenChildPollExhaustsItsAttempts(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.pending-child.events-discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.pending-child.selection.001');
        $transport->setNextEventResumePollingPlan(array_fill(0, 20, 'processing'));
        $root = $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            'erp.calendar.pending-child.events.001',
        );
        $partial = null;
        try {
            $journey->collectEventPages($grant, $resource, $root);
            self::fail('Long-running child unexpectedly completed event traversal.');
        } catch (CalendarTraversalPendingException $exception) {
            $partial = $exception;
            self::assertSame('events', $exception->kind);
            self::assertSame('processing', $exception->lastStatus);
            self::assertCount(1, $exception->pages);
            self::assertSame([], $exception->pages[0]->items);
            self::assertSame('resume-events', $exception->submission->intent->kind);
            self::assertSame($exception->pages[0]->nextPageHandle, $exception->submission->intent->continuationHandle);
        }
        $before = count(array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
        $transport->setPollingPlan($partial->submission->jobId, ['completed']);
        $remaining = $journey->collectEventPages($grant, $resource, $partial->submission);
        self::assertCount(1, $remaining);
        self::assertCount(1, $remaining[0]->items);
        self::assertCount($before, array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/operations/execute',
        ));
    }

    public function testInvalidCalendarDateTimesAreRejectedBeforeSubmitting(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $root = $journey->submitDiscovery($grant, 'erp.calendar.invalid.discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $root)[1];
        $resource = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.invalid.selection.001');
        $count = count($transport->requests());

        foreach ([
            '2026-02-30T00:00:00Z',
            '2026-13-01T00:00:00Z',
            '0000-01-01T00:00:00Z',
            '2026-03-01T24:00:00Z',
            '2026-03-01T00:60:00Z',
            '2026-03-01T00:00:60Z',
            '2026-03-01T00:00:00+24:00',
            '2026-03-01T00:00:00+10:60',
            '2026-03-01T00:00:00',
            '2026-03-01T00:00:00.100Z',
            '2026-05-01T00:00:00Z',
            '2024-01-01T00:00:00Z',
        ] as $invalid) {
            try {
                $journey->submitEventRead($grant, $resource, $invalid, '2026-04-01T00:00:00Z', 'invalid:' . $invalid);
                self::fail("Invalid Calendar date-time {$invalid} was accepted");
            } catch (\InvalidArgumentException) {
                self::assertCount($count, $transport->requests(), 'Invalid time reached the runtime.');
            }
        }
        try {
            $journey->submitEventRead($grant, $resource, '2026-03-01T00:00:00Z', '2026-02-30T00:00:00Z', 'invalid:max');
            self::fail('Invalid Calendar time_max was accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount($count, $transport->requests());
        }
    }

    public function testOpenJobStatusAndPollTimeoutPreserveTheOriginalSubmission(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $root = $journey->submitDiscovery($grant, 'erp.calendar.pending.discovery.001');
        $transport->setPollingPlan($root->jobId, array_fill(0, 20, 'processing'));
        try {
            $journey->pollForManifest($root);
            self::fail('Long-running job unexpectedly completed.');
        } catch (CalendarPollingPendingException $exception) {
            self::assertSame($root, $exception->submission);
            self::assertSame('processing', $exception->lastStatus);
        }
        $jobPolls = array_filter(
            $transport->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/v1/sync-jobs/' . $root->jobId,
        );
        self::assertCount(15, $jobPolls);

        $transport->setPollingPlan($root->jobId, ['waiting_for_capacity', 'completed']);
        try {
            $journey->pollForManifest($root);
            self::fail('Unknown open job status unexpectedly completed.');
        } catch (CalendarPollingPendingException $exception) {
            self::assertSame($root, $exception->submission);
            self::assertSame('waiting_for_capacity', $exception->lastStatus);
        }
        self::assertNotSame('', $journey->pollForManifest($root)->pageId);
    }

    public function testDefaultSleeperUsesSafeWholeSecondsAndFractionalMicroseconds(): void
    {
        $split = new \ReflectionMethod(CalendarReadJourney::class, 'sleepComponents');
        self::assertSame([0, 0], $split->invoke(null, 0.0));
        self::assertSame([0, 500_000], $split->invoke(null, 0.5));
        self::assertSame([2, 0], $split->invoke(null, 2.0));
        self::assertSame([2, 0], $split->invoke(null, 1.9999996));

        $delays = [];
        [$journey, $transport] = $this->journey(sleep: static function (float $seconds) use (&$delays): void {
            $delays[] = $seconds;
        });
        $grant = $journey->getConnectedGrant($transport->grantId());
        $root = $journey->submitDiscovery($grant, 'erp.calendar.sleep.discovery.001');
        $transport->setPollingPlan($root->jobId, array_fill(0, 20, 'processing'));
        try {
            $journey->pollForManifest($root);
            self::fail('Synthetic job unexpectedly completed.');
        } catch (CalendarPollingPendingException) {
            self::assertSame([0.5, 1.0, ...array_fill(0, 12, 2.0)], $delays);
        }
    }

    public function testPollRejectsEveryMismatchedPublicJobIdentityField(): void
    {
        foreach ([
            'id' => 'job_other_window_same_grant',
            'app_id' => 'app_other',
            'tenant_id' => 'tenant_other',
            'provider_key' => 'quickbooks',
            'connection_id' => 'grant_other',
            'operation_key' => 'google-calendar.events.read',
            'recipe_key' => 'erp.google-calendar.events.read',
            'recipe_version' => 2,
            'object_type' => 'calendar_event',
            'internal_object_id' => 'resource_other',
        ] as $field => $wrong) {
            [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
            $root = $journey->submitDiscovery($grant, 'erp.calendar.identity.discovery.' . $field);
            $transport->setPollingPlan($root->jobId, ['completed']);
            $transport->setPolledJobFields($root->jobId, [$field => $wrong]);
            try {
                $journey->pollForManifest($root);
                self::fail("Calendar poll accepted a wrong {$field}");
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function testFutureResultStatusStillAcceptsAValidCompletedManifest(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $root = $journey->submitDiscovery($grant, 'erp.calendar.future-result.discovery.001');
        $transport->setPollingPlan($root->jobId, ['completed']);
        $transport->setCompletedResultStatus($root->jobId, 'retained');
        self::assertNotSame('', $journey->pollForManifest($root)->pageId);
        $transport->setCompletedResultStatus($root->jobId, 'none');
        $this->expectException(\UnexpectedValueException::class);
        $journey->pollForManifest($root);
    }

    public function testPollRejectsAnotherEventWindowJobWithTheSameGrantAndResource(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.window.discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'erp.calendar.window.selection.001');
        $root = $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            'erp.calendar.window.read.001',
        );
        $transport->setPollingPlan($root->jobId, ['completed']);
        $transport->setPolledJobFields($root->jobId, ['id' => 'job_same_grant_resource_different_window']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('id');
        $journey->pollForManifest($root);
    }

    public function testGrantReadRechecksLifecycleWithoutRequiringBothCapabilities(): void
    {
        foreach (['reconnect_required', 'disabled'] as $status) {
            [$journey, $transport] = $this->journey();
            $transport->setGrantReadState($status);
            try {
                $journey->getConnectedGrant($transport->grantId());
                self::fail("A later {$status} grant state was accepted.");
            } catch (CalendarRuntimeException $exception) {
                self::assertSame($status === 'reconnect_required' ? 409 : 403, $exception->statusCode);
            }
        }

        [$journey, $transport] = $this->journey();
        $transport->setGrantReadState('connected', false, true);
        $grant = $journey->getConnectedGrant($transport->grantId());
        self::assertFalse($grant->canDiscoverCalendars);
        self::assertTrue($grant->canReadEvents);
        try {
            $journey->submitDiscovery($grant, 'erp.calendar.event-only.discovery.001');
            self::fail('An event-only grant discovered calendars.');
        } catch (CalendarRuntimeException $exception) {
            self::assertSame('calendar.insufficient_scopes', $exception->problemCode);
        }
    }

    public function testInitialCalendarKeysRejectReservedResumePrefixBeforeSending(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $count = count($transport->requests());
        try {
            $journey->submitDiscovery($grant, 'calendar-page:product-root');
            self::fail('Reserved root discovery key was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount($count, $transport->requests());
        }

        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.key.discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), 'calendar-page:selection-is-not-a-resume');
        $count = count($transport->requests());
        try {
            $journey->submitEventRead(
                $grant,
                $resource,
                '2026-03-01T00:00:00Z',
                '2026-04-01T00:00:00Z',
                'calendar-page:product-event-root',
            );
            self::fail('Reserved root event key was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount($count, $transport->requests());
        }
    }

    public function testCalendarKeyLengthMatchesUtf16CodeUnitsAtThePublicBoundary(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        foreach ([str_repeat('é', 128), str_repeat('😀', 64), str_repeat('a', 126) . '😀'] as $key) {
            self::assertNotSame('', $journey->submitDiscovery($grant, $key)->jobId);
        }
        foreach (['', str_repeat('é', 129), str_repeat('😀', 65), str_repeat('a', 127) . '😀', "\xC3\x28"] as $key) {
            $before = count($transport->requests());
            try {
                $journey->submitDiscovery($grant, $key);
                self::fail('An invalid UTF-16-length discovery key was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertCount($before, $transport->requests());
            }
        }

        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.utf16.discovery.001');
        $page = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $journey->selectResource($grant, $page->pageId, $transport->calendarId(), str_repeat('😀', 64));
        self::assertSame($transport->calendarId(), $resource->calendarId);
        self::assertNotSame('', $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            str_repeat('é', 128),
        )->jobId);
        $before = count($transport->requests());
        try {
            $journey->submitEventRead(
                $grant,
                $resource,
                '2026-03-01T00:00:00Z',
                '2026-04-01T00:00:00Z',
                str_repeat('😀', 65),
            );
            self::fail('An overlong UTF-16 event key was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount($before, $transport->requests());
        }
    }

    public function testTerminalPollingMapsKnownCalendarRemediesWithoutChangingUnknownCodes(): void
    {
        foreach ([
            ['failed', 'calendar.reconnect_required', CalendarReconnectRequiredException::class, 409],
            ['dead_lettered', 'calendar.page_expired', CalendarResultExpiredException::class, 410],
            ['failed', 'calendar.result_expired', CalendarResultExpiredException::class, 410],
            ['failed', 'calendar.continuation_expired', CalendarResultExpiredException::class, 410],
            ['failed', 'calendar.discovery_expired', CalendarResultExpiredException::class, 410],
            ['failed', 'calendar.future_error', CalendarRuntimeException::class, 409],
        ] as [$status, $code, $type, $httpStatus]) {
            [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
            $root = $journey->submitDiscovery($grant, 'erp.calendar.terminal.' . $code);
            $transport->setPollingPlan($root->jobId, [$status]);
            $transport->setPolledJobFields($root->jobId, ['last_error_code' => $code]);
            try {
                $journey->pollForManifest($root);
                self::fail("Terminal Calendar code {$code} unexpectedly completed.");
            } catch (CalendarRuntimeException $exception) {
                self::assertSame($type, $exception::class);
                self::assertSame($httpStatus, $exception->statusCode);
                self::assertSame($code, $exception->problemCode);
            }
        }
    }

    public function testCleanConsumerFixtureTriggersPhpWorkflowForPullsAndMainPushes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/sdk-php-ci.yml');
        self::assertIsString($source);
        $pullStart = strpos($source, '  pull_request:');
        $pushStart = strpos($source, '  push:');
        $end = strpos($source, "\nconcurrency:");
        self::assertIsInt($pullStart);
        self::assertIsInt($pushStart);
        self::assertIsInt($end);
        $path = '      - ".github/fixtures/sdk-php-clean-consumer-calendar.php"';
        self::assertStringContainsString($path, substr($source, $pullStart, $pushStart - $pullStart));
        self::assertStringContainsString($path, substr($source, $pushStart, $end - $pushStart));
    }

    public function testExpiredPageRequiresAnExplicitNewRootRead(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.expiry.discovery.001');
        $discoveryPage = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $resource = $journey->selectResource($grant, $discoveryPage->pageId, $transport->calendarId(), 'erp.calendar.expiry.selection.001');
        $root = $journey->submitEventRead(
            $grant,
            $resource,
            '2026-03-01T00:00:00Z',
            '2026-04-01T00:00:00Z',
            'erp.calendar.expiry.read.001',
        );
        $transport->expireNextEventPage();

        try {
            $journey->fetchPage($journey->pollForManifest($root));
            self::fail('An expired Calendar page unexpectedly resolved.');
        } catch (CalendarResultExpiredException $exception) {
            self::assertSame(410, $exception->statusCode);
            self::assertSame('calendar.page_expired', $exception->problemCode);
        }
        $paths = array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $transport->requests());
        self::assertMatchesRegularExpression('~\A/v1/calendar/pages/[A-Za-z0-9_-]{43}\z~D', (string) array_pop($paths));
    }

    public function testReconnectRequiresFreshDiscoveryAndAReplacementResourceBinding(): void
    {
        [$journey, $transport, $_identity, $grant] = $this->verifiedGrant();
        $discovery = $journey->submitDiscovery($grant, 'erp.calendar.reconnect.discovery.001');
        $discoveryPage = $journey->collectDiscoveryPages($grant, $discovery)[1];
        $oldResource = $journey->selectResource($grant, $discoveryPage->pageId, $transport->calendarId(), 'erp.calendar.reconnect.selection.001');
        $transport->requireReconnect();

        try {
            $journey->submitEventRead(
                $grant,
                $oldResource,
                '2026-03-01T00:00:00Z',
                '2026-04-01T00:00:00Z',
                'erp.calendar.reconnect.read.001',
            );
            self::fail('A reconnect-required grant unexpectedly read events.');
        } catch (CalendarReconnectRequiredException $exception) {
            self::assertSame('calendar.reconnect_required', $exception->problemCode);
        }

        $transport->beginMockReconnect();
        try {
            $journey->getConnectedGrant($grant->grantId);
            self::fail('Beginning synthetic consent unexpectedly connected the grant.');
        } catch (CalendarReconnectRequiredException) {
            // Starting consent is not completing it.
        }
        $transport->abandonMockReconnect();
        try {
            $journey->getConnectedGrant($grant->grantId);
            self::fail('Abandoned synthetic reconnect unexpectedly connected the grant.');
        } catch (CalendarReconnectRequiredException) {
            // Abandonment keeps the grant reconnect-required.
        }
        $transport->beginMockReconnect();
        $transport->completeMockReconnect(false);
        try {
            $journey->getConnectedGrant($grant->grantId);
            self::fail('Denied synthetic reconnect unexpectedly connected the grant.');
        } catch (CalendarReconnectRequiredException) {
            // Consent denial is not a reconnect.
        }
        $transport->beginMockReconnect();
        $transport->completeMockReconnect(true);
        $reconnectedGrant = $journey->getConnectedGrant($grant->grantId);

        try {
            $journey->submitEventRead(
                $reconnectedGrant,
                $oldResource,
                '2026-03-01T00:00:00Z',
                '2026-04-01T00:00:00Z',
                'erp.calendar.reconnect.old-resource.001',
            );
            self::fail('A pre-reconnect binding unexpectedly remained usable.');
        } catch (CalendarRuntimeException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('calendar.generation_changed', $exception->problemCode);
        }

        $newDiscovery = $journey->submitDiscovery($reconnectedGrant, 'erp.calendar.reconnect.discovery.002');
        $newPage = $journey->collectDiscoveryPages($reconnectedGrant, $newDiscovery)[1];
        $newResource = $journey->selectResource(
            $reconnectedGrant,
            $newPage->pageId,
            $transport->calendarId(),
            'erp.calendar.reconnect.selection.002',
        );
        self::assertNotSame($oldResource->resourceId, $newResource->resourceId);
    }

    private function discoveredCalendarId(CalendarPublicPage $page): string
    {
        $item = $page->items[0] ?? null;
        $identity = is_array($item) ? ($item['identity'] ?? null) : null;
        $calendarId = is_array($identity) ? ($identity['calendarId'] ?? null) : null;
        if (! is_string($calendarId) || $calendarId === '') {
            throw new \UnexpectedValueException('Synthetic discovery did not return a canonical calendar ID.');
        }

        return $calendarId;
    }

    /**
     * @param list<RequestInterface> $requests
     * @return array{
     *     idempotency_key: string,
     *     payload: array<string, string>
     * }
     */
    private function onlyResumeRequest(array $requests, string $recipeKey): array
    {
        $resume = null;
        foreach ($requests as $request) {
            if ($request->getUri()->getPath() !== '/v1/operations/execute') {
                continue;
            }
            $body = $this->jsonObject($request);
            if (($body['recipe_key'] ?? null) !== $recipeKey) {
                continue;
            }
            $payload = $body['payload'] ?? null;
            if (! is_array($payload) || ! array_key_exists('continuation_handle', $payload)) {
                continue;
            }
            $idempotencyKey = $body['idempotency_key'] ?? null;
            $grantId = $payload['grant_id'] ?? null;
            $handle = $payload['continuation_handle'];
            if (! is_string($idempotencyKey) || ! is_string($grantId) || ! is_string($handle)) {
                throw new \UnexpectedValueException('Synthetic Calendar resume request had an invalid public shape.');
            }
            if ($resume !== null) {
                throw new \UnexpectedValueException('Synthetic Calendar journey submitted more than one resume request.');
            }
            $resume = [
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'grant_id' => $grantId,
                    'continuation_handle' => $handle,
                ],
            ];
            if ($recipeKey === 'erp.google-calendar.events.read') {
                $resourceId = $payload['resource_id'] ?? null;
                if (! is_string($resourceId)) {
                    throw new \UnexpectedValueException('Synthetic Calendar event resume omitted its selected resource.');
                }
                $resume['payload'] = [
                    'grant_id' => $grantId,
                    'resource_id' => $resourceId,
                    'continuation_handle' => $handle,
                ];
            }
        }
        if ($resume === null) {
            throw new \UnexpectedValueException('Synthetic Calendar journey did not submit a resume request.');
        }

        return $resume;
    }

    /** @return array<string, mixed> */
    private function jsonObject(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('Synthetic request body must be a JSON object.');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Synthetic request object contained a non-string key.');
            }
            $object[$key] = $value;
        }

        return $object;
    }
}
