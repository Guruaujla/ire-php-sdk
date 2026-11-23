<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Meetings;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Meetings::class)]
final class MeetingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function operationStatus(string $status): array
    {
        return [
            'job_id' => 'job_1',
            'operation' => 'meeting.create',
            'meeting_ref' => 'mtg_1',
            'external_ref' => 'appt-42',
            'provider_key' => 'google-meet',
            'status' => $status,
            'idempotent_replay' => false,
            'existing_meeting' => false,
            'polling_url' => '/v1/meetings/operations/job_1?tenant_id=t1',
            'error' => null,
            'meeting' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function body(ScriptedTransport $transport, int $i): array
    {
        $decoded = json_decode((string) $transport->requests[$i]->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testProvidersSendsTenant(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['providers' => [], 'connections' => []])]);
        $out = (new Meetings(Clients::client($transport)))->providers('t1');

        self::assertSame([], $out['providers']);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/meetings/providers?tenant_id=t1', $transport->target(0));
    }

    public function testCreatePostsNeutralBodyAndAcceptsAccepted(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('queued'))]);
        $out = (new Meetings(Clients::client($transport)))->create('t1', 'conn_1', 'appt-42:create', [
            'external_ref' => 'appt-42',
            'title' => 'Site visit',
        ]);

        self::assertSame('queued', $out['status']);
        self::assertSame('POST', $transport->requests[0]->getMethod());
        self::assertSame('/v1/meetings/operations', $transport->target(0));
        self::assertSame([
            'operation' => 'meeting.create',
            'tenant_id' => 't1',
            'connection_id' => 'conn_1',
            'idempotency_key' => 'appt-42:create',
            'meeting' => ['external_ref' => 'appt-42', 'title' => 'Site visit'],
        ], self::body($transport, 0));
    }

    public function testCreateAcceptsCompleted(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::operationStatus('completed'))]);
        $out = (new Meetings(Clients::client($transport)))->create('t1', 'conn_1', 'k', ['external_ref' => 'appt-42']);

        self::assertSame('completed', $out['status']);
    }

    public function testCreateRequiresExternalRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Meetings(Clients::client(new ScriptedTransport([]))))->create('t1', 'conn_1', 'k', ['title' => 'x']);
    }

    public function testEndPostsMeetingRef(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('queued'))]);
        (new Meetings(Clients::client($transport)))->end('t1', 'conn_1', 'appt-42:end', 'mtg_1');

        self::assertSame([
            'operation' => 'meeting.end',
            'tenant_id' => 't1',
            'connection_id' => 'conn_1',
            'idempotency_key' => 'appt-42:end',
            'meeting_ref' => 'mtg_1',
        ], self::body($transport, 0));
    }

    public function testUpdatePostsMeetingRefAndChanges(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('queued'))]);
        (new Meetings(Clients::client($transport)))->update(
            't1',
            'conn_1',
            'appt-42:update-1',
            'mtg_1',
            ['title' => 'Moved', 'access' => 'restricted'],
        );

        self::assertSame([
            'operation' => 'meeting.update',
            'tenant_id' => 't1',
            'connection_id' => 'conn_1',
            'idempotency_key' => 'appt-42:update-1',
            'meeting_ref' => 'mtg_1',
            'changes' => ['title' => 'Moved', 'access' => 'restricted'],
        ], self::body($transport, 0));
    }

    public function testUpdateRequiresChanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Meetings(Clients::client(new ScriptedTransport([]))))->update('t1', 'conn_1', 'k', 'mtg_1', []);
    }

    public function testOperationAndGetPaths(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::operationStatus('completed')),
            Clients::json(200, ['meeting_ref' => 'mtg 1', 'status' => 'active']),
        ]);
        $meetings = new Meetings(Clients::client($transport));
        $meetings->operation('t1', 'job_1');
        $meetings->get('t1', 'conn/1', 'mtg 1');

        self::assertSame('/v1/meetings/operations/job_1?tenant_id=t1', $transport->target(0));
        self::assertSame('/v1/meetings/connections/conn%2F1/meetings/mtg%201?tenant_id=t1', $transport->target(1));
    }

    public function testArtifactsPath(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['meeting_ref' => 'mtg 1', 'status' => 'available', 'final' => true]),
        ]);
        $body = (new Meetings(Clients::client($transport)))->artifacts('t1', 'conn/1', 'mtg 1');

        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/meetings/connections/conn%2F1/meetings/mtg%201/artifacts?tenant_id=t1', $transport->target(0));
        self::assertSame('available', $body['status']);
    }

    public function testArtifactsRequiresIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Meetings(Clients::client(new ScriptedTransport([]))))->artifacts('t1', 'conn_1', '');
    }

    public function testOperationAcceptsStillRunning(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('processing'))]);
        $out = (new Meetings(Clients::client($transport)))->operation('t1', 'job_1');

        self::assertSame('processing', $out['status']);
    }

    public function testProblemResponseThrows(): void
    {
        $transport = new ScriptedTransport([Clients::json(422, [
            'code' => 'meetings.capability_unsupported',
            'title' => 'The provider cannot apply this field',
            'field' => 'access',
        ])]);
        $this->expectException(RuntimeProblemException::class);
        (new Meetings(Clients::client($transport)))->create('t1', 'conn_1', 'k', ['external_ref' => 'a', 'access' => 'open']);
    }

    public function testListSendsFiltersAndCursor(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['meetings' => [['meeting_ref' => 'm1']], 'next_cursor' => 'c2']),
        ]);
        $page = (new Meetings(Clients::client($transport)))->list('t1', 'conn/1', [
            'external_ref' => 'appt 42',
            'status' => 'active',
            'limit' => 10,
            'cursor' => 'c1',
        ]);

        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame(
            '/v1/meetings/connections/conn%2F1/meetings?tenant_id=t1&external_ref=appt%2042&status=active&cursor=c1&limit=10',
            $transport->target(0),
        );
        self::assertSame('c2', $page['next_cursor']);
    }

    public function testListRefusesAnOutOfRangeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Meetings(Clients::client(new ScriptedTransport([]))))->list('t1', 'conn_1', ['limit' => 101]);
    }

    public function testFindByExternalRefReturnsTheMeetingOrNull(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['meetings' => [['meeting_ref' => 'm1', 'external_ref' => 'appt-42']], 'next_cursor' => null]),
            Clients::json(200, ['meetings' => [], 'next_cursor' => null]),
        ]);
        $meetings = new Meetings(Clients::client($transport));

        self::assertSame('m1', $meetings->findByExternalRef('t1', 'conn_1', 'appt-42')['meeting_ref'] ?? null);
        self::assertNull($meetings->findByExternalRef('t1', 'conn_1', 'appt-43'));
        self::assertSame('/v1/meetings/connections/conn_1/meetings?tenant_id=t1&external_ref=appt-42&limit=1', $transport->target(0));
    }

    public function testDecisionKnowsMeetingsCodes(): void
    {
        $d = static fn(int $s, string $c): string => (new RuntimeProblemException($s, $c))->decision();
        self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, $d(409, 'meetings.update_in_progress'));
        self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, $d(409, 'meetings.create_in_progress'));
        self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, $d(503, 'meetings.state_unavailable'));
        self::assertSame(RuntimeProblemException::FIX_SETUP, $d(409, 'meetings.connection_not_active'));
        self::assertSame(RuntimeProblemException::FIX_SETUP, $d(409, 'meetings.account_changed'));
        self::assertSame(RuntimeProblemException::FIX_REQUEST, $d(409, 'meetings.meeting_ended'));
        self::assertSame(RuntimeProblemException::REVIEW_AND_RESUBMIT, $d(409, 'meetings.update_superseded'));
        // An unknown meetings code follows the generic status rules.
        self::assertSame(RuntimeProblemException::STOP_AND_RECONCILE, $d(409, 'meetings.some_future_code'));
    }
}
