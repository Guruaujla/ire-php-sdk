<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\RetryPolicy;
use Ezee\IntegrationRuntime\SyncJob;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\RequestFailure;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use Ezee\IntegrationRuntime\Tests\Support\TransportFailure;
use GuzzleHttp\Psr7\HttpFactory;
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

    public function testGetAndWaitAcceptTheAccountingPollingUrl(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::job('processing')),
            Clients::json(200, self::job('queued')),
            Clients::json(200, self::job('completed', '1042')),
        ]);
        $jobs = new Jobs(Clients::client($transport), static function (float $s): void {});
        $accounting = '/v1/accounting/operations/job_1?tenant_id=t1';

        self::assertFalse($jobs->get($accounting)->isTerminal());
        self::assertTrue($jobs->wait($accounting, 600)->isCompleted());
        self::assertCount(3, $transport->requests);
        foreach (array_keys($transport->requests) as $i) {
            self::assertSame('GET', $transport->requests[$i]->getMethod());
            self::assertSame(self::URL, $transport->target($i));
        }
    }

    public function testAccountingPollingUrlKeepsTheJobIdEncoded(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::job('completed'))]);
        (new Jobs(Clients::client($transport)))->get('/v1/accounting/operations/job%201?tenant_id=t%2F1');
        self::assertSame('/v1/sync-jobs/job%201?tenant_id=t%2F1', $transport->target(0));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAccountingUrls(): iterable
    {
        yield 'no tenant' => ['/v1/accounting/operations/job_1'];
        yield 'empty tenant' => ['/v1/accounting/operations/job_1?tenant_id='];
        yield 'no job id' => ['/v1/accounting/operations/?tenant_id=t1'];
        yield 'nested path' => ['/v1/accounting/operations/job_1/x?tenant_id=t1'];
        yield 'other route' => ['/v1/accounting/providers?tenant_id=t1'];
        yield 'dot segment' => ['/v1/accounting/operations/.?tenant_id=t1'];
        yield 'encoded dot-dot segment' => ['/v1/accounting/operations/%2e%2e?tenant_id=t1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAccountingUrls')]
    public function testMalformedAccountingPollingUrlsAreRefusedBeforeSending(string $url): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Jobs(Clients::client($transport)))->wait($url);
            self::fail('expected the URL to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $transport->requests);
    }

    public function testWaitKeepsPollingThroughTransportFailures(): void
    {
        $transport = new ScriptedTransport([
            new TransportFailure('connection reset'),
            Clients::json(200, self::job('processing')),
            new TransportFailure('timeout'),
            Clients::json(200, self::job('completed', '1042')),
        ]);
        $slept = [];
        $jobs = new Jobs(Clients::client($transport), static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });

        self::assertTrue($jobs->wait(self::URL, 600)->isCompleted());
        self::assertCount(4, $transport->requests);
        self::assertCount(3, $slept);
    }

    public function testWaitRethrowsADeterministicRequestFailureAtOnce(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::job('processing')),
            new RequestFailure('malformed request'),
        ]);
        $jobs = new Jobs(Clients::client($transport), static function (float $s): void {});
        $this->expectException(RequestFailure::class);
        $jobs->wait(self::URL, 600);
    }

    public function testWaitSurfacesATransportFailureWhenItCannotWaitAndHasNoRead(): void
    {
        $transport = new ScriptedTransport([new TransportFailure('connection refused')]);
        $this->expectException(TransportFailure::class);
        (new Jobs(Clients::client($transport), static function (float $s): void {}))->wait(self::URL, 0);
    }

    public function testWaitReturnsTheLastReadWhenATransportFailureHitsTheDeadline(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::job('processing')),
            new TransportFailure('timeout'),
        ]);
        $jobs = new Jobs(Clients::client($transport), static function (float $s): void {});
        $job = $jobs->wait(self::URL, 3, 2.0);
        self::assertFalse($job->isTerminal());
        self::assertCount(2, $transport->requests);
    }

    public function testWaitDoesNotSleepInsideTheClientOnA429(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(429, ['code' => 'rate_limit.quota_exceeded'], ['Retry-After' => '60']),
        ]);
        $clientSlept = [];
        $factory = new HttpFactory();
        // A client with 429 retries ON: wait() must not let it sleep 60s
        // inside one request past a 1s deadline.
        $client = new Client($transport, $factory, $factory, 'https://runtime.example.invalid', 'app_erp', 'key_test', Clients::SECRET, new RetryPolicy(), static function (float $s) use (&$clientSlept): void {
            $clientSlept[] = $s;
        });
        $waitSlept = [];
        $jobs = new Jobs($client, static function (float $s) use (&$waitSlept): void {
            $waitSlept[] = $s;
        });
        try {
            $jobs->wait(self::URL, 1);
            self::fail('expected the 429 to surface');
        } catch (RuntimeProblemException $e) {
            self::assertSame(429, $e->httpStatus);
        }
        self::assertSame([], $clientSlept);
        self::assertSame([], $waitSlept);
        self::assertCount(1, $transport->requests);
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

    public function testConnectSendsXeroWhenAskedAndTheSameBodyOtherwise(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(201, ['session_id' => '0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d08', 'authorize_url' => 'https://login.xero.com/identity/connect/authorize?x=1', 'expires_at' => 1, 'connection_id' => null]),
            Clients::json(201, ['session_id' => '0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d09', 'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?x=1', 'expires_at' => 1, 'connection_id' => null]),
        ]);
        $connections = new Connections(Clients::client($transport));
        $session = $connections->start('t1', 'https://erp.example.com/xero/return', 'state-456', 'xero');
        self::assertSame('https://login.xero.com/identity/connect/authorize?x=1', $session['authorize_url']);
        self::assertSame(['tenant_id' => 't1', 'provider_key' => 'xero', 'return_url' => 'https://erp.example.com/xero/return', 'state' => 'state-456'], $transport->body(0));
        self::assertSame('/v1/connections/connect-session', $transport->target(0));
        // Passing the default explicitly sends exactly the default's body.
        $connections->start('t1', 'https://erp.example.com/xero/return', 'state-456', 'quickbooks');
        self::assertSame(['tenant_id' => 't1', 'provider_key' => 'quickbooks', 'return_url' => 'https://erp.example.com/xero/return', 'state' => 'state-456'], $transport->body(1));
    }

    public function testReconnectReadsTheProviderAndStartsASessionForTheSameTenant(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['id' => 'conn_x', 'provider_key' => 'xero', 'status' => 'reconnect_required', 'connected_at' => 1, 'created_at' => 1, 'updated_at' => 2]),
            Clients::json(201, ['session_id' => '0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d0a', 'authorize_url' => 'https://login.xero.com/identity/connect/authorize?x=2', 'expires_at' => 1, 'connection_id' => 'conn_x']),
        ]);
        $session = (new Connections(Clients::client($transport)))->reconnect('t1', 'conn_x', 'https://erp.example.com/reconnect/return', 'state-789');
        self::assertSame('/v1/connections/conn_x?tenant_id=t1', $transport->target(0));
        self::assertSame('/v1/connections/connect-session', $transport->target(1));
        self::assertSame(['tenant_id' => 't1', 'provider_key' => 'xero', 'return_url' => 'https://erp.example.com/reconnect/return', 'state' => 'state-789'], $transport->body(1));
        self::assertSame('conn_x', $session['connection_id']);
    }

    public function testReconnectRefusesAProviderItCannotConnect(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['id' => 'g1', 'provider_key' => 'google-calendar', 'status' => 'reconnect_required']),
        ]);
        try {
            (new Connections(Clients::client($transport)))->reconnect('t1', 'g1', 'https://erp.example.com/r', 's');
            self::fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('provider_key', $e->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    public function testConnectRefusesAnUnknownProviderBeforeSending(): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Connections(Clients::client($transport)))->start('t1', 'https://erp.example.com/r', 's', 'sage');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('quickbooks, xero', $e->getMessage());
        }
        self::assertSame([], $transport->requests);
    }

    /** @return array<string, mixed> */
    private static function connection(string $id, string $providerKey, string $status = 'connected'): array
    {
        return ['id' => $id, 'provider_key' => $providerKey, 'status' => $status, 'connected_at' => 1, 'created_at' => 1, 'updated_at' => 2];
    }

    public function testDisconnectWithAStoredCompanyIdReadsTheProviderAndPostsTheDocumentedBody(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::connection('conn_q', 'quickbooks', 'reconnect_required')),
            Clients::json(204, null),
        ]);
        $result = (new Connections(Clients::client($transport)))->disconnect('t1', 'conn_q', 'disconnect:k1', '9130350000000000');

        self::assertSame(['provider_cleanup' => null], $result);
        self::assertCount(2, $transport->requests);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/connections/conn_q?tenant_id=t1', $transport->target(0));
        self::assertSame('POST', $transport->requests[1]->getMethod());
        self::assertSame('/v1/connections/conn_q/disconnect', $transport->target(1));
        self::assertSame([
            'tenant_id' => 't1',
            'provider_key' => 'quickbooks',
            'provider_account_id' => '9130350000000000',
            'idempotency_key' => 'disconnect:k1',
        ], $transport->body(1));
    }

    public function testDisconnectWithoutACompanyIdReadsItFromProviders(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::connection('conn_x', 'xero')),
            Clients::json(200, ['providers' => [], 'connection_state' => 'connected', 'connection' => ['connection_id' => 'conn_x', 'provider_key' => 'xero', 'provider_account_id' => 'xero-tenant-1']]),
            Clients::json(204, null, ['X-IR-Provider-Cleanup' => 'customer_action_required']),
        ]);
        $result = (new Connections(Clients::client($transport)))->disconnect('t1', 'conn_x', 'disconnect:k2');

        self::assertSame(['provider_cleanup' => 'customer_action_required'], $result);
        self::assertSame('/v1/accounting/providers?tenant_id=t1', $transport->target(1));
        self::assertSame('/v1/connections/conn_x/disconnect', $transport->target(2));
        self::assertSame([
            'tenant_id' => 't1',
            'provider_key' => 'xero',
            'provider_account_id' => 'xero-tenant-1',
            'idempotency_key' => 'disconnect:k2',
        ], $transport->body(2));
    }

    public function testDisconnectRefusesWhenProvidersNamesAnotherConnectionAndNothingWasStored(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::connection('conn_old', 'quickbooks')),
            Clients::json(200, ['providers' => [], 'connection_state' => 'multiple', 'connection' => null]),
        ]);
        try {
            (new Connections(Clients::client($transport)))->disconnect('t1', 'conn_old', 'disconnect:k3');
            self::fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('provider_account_id', $e->getMessage());
        }
        // No disconnect was sent.
        self::assertCount(2, $transport->requests);

        $other = new ScriptedTransport([
            Clients::json(200, self::connection('conn_old', 'quickbooks')),
            Clients::json(200, ['connection_state' => 'connected', 'connection' => ['connection_id' => 'conn_new', 'provider_key' => 'quickbooks', 'provider_account_id' => '1']]),
        ]);
        $this->expectException(\UnexpectedValueException::class);
        try {
            (new Connections(Clients::client($other)))->disconnect('t1', 'conn_old', 'disconnect:k3');
        } finally {
            self::assertCount(2, $other->requests);
        }
    }

    public function testDisconnectMapsARefusalToARuntimeProblem(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::connection('conn_q', 'quickbooks')),
            Clients::json(503, ['code' => 'oauth.disconnect_retryable', 'request_id' => 'req_1']),
        ]);
        try {
            (new Connections(Clients::client($transport)))->disconnect('t1', 'conn_q', 'disconnect:k4', 'realm-1');
            self::fail('expected RuntimeProblemException');
        } catch (RuntimeProblemException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertSame('oauth.disconnect_retryable', $e->problemCode);
            self::assertSame('req_1', $e->requestId);
            self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, $e->decision());
        }

        $busy = new ScriptedTransport([
            Clients::json(200, self::connection('conn_q', 'quickbooks')),
            Clients::json(409, ['code' => 'oauth.lifecycle_busy']),
        ]);
        try {
            (new Connections(Clients::client($busy)))->disconnect('t1', 'conn_q', 'disconnect:k4', 'realm-1');
            self::fail('expected RuntimeProblemException');
        } catch (RuntimeProblemException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('oauth.lifecycle_busy', $e->problemCode);
            self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, $e->decision());
        }
    }

    public function testDisconnectValidatesBeforeSending(): void
    {
        $connections = new Connections(Clients::client($transport = new ScriptedTransport([])));
        foreach ([['', 'c', 'k', null], ['t', '', 'k', null], ['t', 'c', ' ', null], ['t', 'c', str_repeat('k', 129), null], ['t', 'c', 'k', '']] as [$tenant, $connection, $key, $account]) {
            try {
                $connections->disconnect($tenant, $connection, $key, $account);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $transport->requests);
    }

    public function testDisconnectRefusesAProviderItCannotDisconnect(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::connection('g1', 'google-calendar'))]);
        try {
            (new Connections(Clients::client($transport)))->disconnect('t1', 'g1', 'disconnect:k5', 'acct');
            self::fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('provider_key', $e->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }
}
