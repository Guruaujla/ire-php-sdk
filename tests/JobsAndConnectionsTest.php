<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\SyncJob;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Jobs::class)]
#[CoversClass(SyncJob::class)]
#[CoversClass(Connections::class)]
final class JobsAndConnectionsTest extends TestCase
{
    private const URL = '/v1/sync-jobs/job_1?tenant_id=t1';

    /** @return array<string, mixed> */
    private static function job(string $status, ?string $external = null): array
    {
        return ['id' => 'job_1', 'status' => $status, 'operation_key' => 'quickbooks.invoice.create', 'external_object_id' => $external, 'last_error_code' => null];
    }

    public function testWaitPollsTheSameUrlUntilTerminalWithoutResubmitting(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::job('queued')),
            Clients::json(429, ['code' => 'rate_limit.quota_exceeded'], ['Retry-After' => '3']),
            Clients::json(200, self::job('processing')),
            Clients::json(200, self::job('completed', '1042')),
        ]);
        $slept = [];
        $jobs = new Jobs(Clients::client($transport), static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });
        $job = $jobs->wait(self::URL, 600);

        self::assertTrue($job->isCompleted());
        self::assertSame('1042', $job->externalObjectId);
        self::assertCount(4, $transport->requests);
        foreach ($transport->requests as $i => $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame(self::URL, $transport->target($i));
        }
        self::assertSame(3.0, $slept[1]);
    }

    public function testExternalIdComesFromThePublishedResult(): void
    {
        $published = SyncJob::fromArray(['id' => 'job_1', 'status' => 'completed', 'external_object_id' => null, 'result' => ['external_id' => '1042']]);
        self::assertSame('1042', $published->externalId());
        $legacy = SyncJob::fromArray(['id' => 'job_1', 'status' => 'completed', 'external_object_id' => '77']);
        self::assertSame('77', $legacy->externalId());
        $payment = SyncJob::fromArray(['id' => 'job_1', 'status' => 'completed', 'result' => ['payment' => ['id' => '310']]]);
        self::assertSame('310', $payment->externalId());
        $master = SyncJob::fromArray(['id' => 'job_1', 'status' => 'completed', 'result' => ['record' => ['external_id' => '58']]]);
        self::assertSame('58', $master->externalId());
        $none = SyncJob::fromArray(['id' => 'job_1', 'status' => 'completed']);
        self::assertNull($none->externalId());
    }

    public function testWaitReturnsTheLastReadAtTheDeadline(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::job('processing'))]);
        $job = (new Jobs(Clients::client($transport), static function (float $s): void {}))->wait(self::URL, 0);
        self::assertFalse($job->isTerminal());
    }

    public function testWaitSurfacesATransientErrorWhenItCannotWaitAndHasNoRead(): void
    {
        $transport = new ScriptedTransport([Clients::json(429, ['code' => 'rate_limit.quota_exceeded'], ['Retry-After' => '60'])]);
        $slept = [];
        $jobs = new Jobs(Clients::client($transport), static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });
        try {
            $jobs->wait(self::URL, 1);
            self::fail('expected the 429 to surface');
        } catch (RuntimeProblemException $e) {
            self::assertSame(429, $e->httpStatus);
        }
        self::assertCount(1, $transport->requests);
        self::assertSame([], $slept);
    }

    public function testWaitSurfacesNonTransientReadFailures(): void
    {
        $transport = new ScriptedTransport([Clients::json(404, ['code' => 'sync_job.not_found'])]);
        $this->expectException(RuntimeProblemException::class);
        (new Jobs(Clients::client($transport)))->wait(self::URL);
    }

    public function testGetRefusesAnythingButARuntimePollingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Jobs(Clients::client(new ScriptedTransport([]))))->get('https://elsewhere.example/v1/sync-jobs/x');
    }

    public function testFindAndListScopeByTenant(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::job('failed')), Clients::json(200, ['data' => []])]);
        $jobs = new Jobs(Clients::client($transport));
        self::assertTrue($jobs->find('t1', 'job_1')->isTerminal());
        $jobs->list('t1', ['status' => 'dead_lettered', 'limit' => 5]);
        self::assertSame(self::URL, $transport->target(0));
        self::assertSame('/v1/sync-jobs?tenant_id=t1&status=dead_lettered&limit=5', $transport->target(1));
    }

    public function testConnectStartsANativeQuickBooksSession(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(201, ['session_id' => '0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d07', 'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?x=1', 'expires_at' => 1, 'connection_id' => null]),
            Clients::json(200, ['id' => 'c1', 'status' => 'connected']),
            Clients::json(200, ['data' => [], 'next_cursor' => null]),
        ]);
        $connections = new Connections(Clients::client($transport));
        $session = $connections->start('t1', 'https://erp.example.com/qb/return', 'state-123');
        self::assertNull($session['connection_id']);
        self::assertSame(['tenant_id' => 't1', 'provider_key' => 'quickbooks', 'return_url' => 'https://erp.example.com/qb/return', 'state' => 'state-123'], $transport->body(0));
        self::assertSame('connected', $connections->get('t1', 'c1')['status']);
        $connections->list('t1', limit: 10);
        self::assertSame('/v1/connections/c1?tenant_id=t1', $transport->target(1));
        self::assertSame('/v1/connections?tenant_id=t1&limit=10', $transport->target(2));
    }
}
