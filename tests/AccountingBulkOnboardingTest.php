<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Snapshots, bind jobs and the `source`/`q` record-read options, written from
 * the HTTP contract in openapi/integration-runtime.openapi.yaml
 * (paths /v1/accounting/connections/{id}/records/{entity}/...).
 */
#[CoversClass(Accounting::class)]
final class AccountingBulkOnboardingTest extends TestCase
{
    private const C = '/v1/accounting/connections/c%201';

    /** @return array<string, mixed> AccountingRecordSnapshot */
    private static function snapshot(string $status = 'running'): array
    {
        return [
            'snapshot_id' => 'snap_1',
            'entity' => 'customer',
            'status' => $status,
            'job_id' => 'job_9',
            'record_count' => 0,
            'provider_calls' => 1,
            'waiting_on' => null,
            'as_of' => null,
            'expires_at' => null,
            'created_at' => '2026-11-11T00:00:00Z',
            'error_code' => null,
        ];
    }

    /** @return array<string, mixed> AccountingBindJob */
    private static function bindJob(string $status = 'pending'): array
    {
        return [
            'job_id' => 'job/7',
            'entity' => 'item',
            'status' => $status,
            'row_count' => 2,
            'bound_count' => 0,
            'refused_count' => 0,
            'not_attempted_count' => 2,
            'provider_calls' => 0,
            'waiting_on' => 'slot',
            'error_code' => null,
            'created_at' => '2026-11-11T00:00:00Z',
            'ended_at' => null,
        ];
    }

    private static function method(ScriptedTransport $t, int $i): string
    {
        return $t->requests[$i]->getMethod();
    }

    // ---- POST .../snapshots ------------------------------------------------

    public function testStartSnapshotPostsTenantOnlyAndReturnsTheAcceptedSnapshot(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::snapshot())]);
        $out = (new Accounting(Clients::client($transport)))->startSnapshot('t1', 'c 1', 'customer');

        self::assertSame('POST', self::method($transport, 0));
        self::assertSame(self::C . '/records/customer/snapshots', $transport->target(0));
        // refresh is only sent when true.
        self::assertSame(['tenant_id' => 't1'], $transport->body(0));
        self::assertSame(self::snapshot(), $out);
    }

    public function testStartSnapshotSendsRefreshTrueOnlyWhenAsked(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(202, self::snapshot()),
            Clients::json(200, self::snapshot('ready')),
        ]);
        $a = new Accounting(Clients::client($transport));
        $a->startSnapshot('t1', 'c 1', 'item', true);
        $ready = $a->startSnapshot('t1', 'c 1', 'item', refresh: false);

        self::assertSame(self::C . '/records/item/snapshots', $transport->target(0));
        self::assertSame(['tenant_id' => 't1', 'refresh' => true], $transport->body(0));
        self::assertSame(['tenant_id' => 't1'], $transport->body(1));
        self::assertSame('ready', $ready['status']);
    }

    public function testStartSnapshotEncodesTheConnectionId(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::snapshot())]);
        (new Accounting(Clients::client($transport)))->startSnapshot('t1', 'conn/1?x#y', 'customer');
        self::assertSame('/v1/accounting/connections/conn%2F1%3Fx%23y/records/customer/snapshots', $transport->target(0));
    }

    // ---- GET .../snapshots/latest -------------------------------------------

    public function testLatestSnapshotGetsWithTenantQuery(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::snapshot('ready'))]);
        $out = (new Accounting(Clients::client($transport)))->latestSnapshot('t 1', 'c 1', 'item');

        self::assertSame('GET', self::method($transport, 0));
        self::assertSame(self::C . '/records/item/snapshots/latest?tenant_id=t%201', $transport->target(0));
        self::assertSame('', (string) $transport->requests[0]->getBody());
        self::assertSame(self::snapshot('ready'), $out);
    }

    public function testLatestSnapshotNotFoundIsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(404, ['code' => 'accounting.snapshot_not_found', 'request_id' => 'r'])]);
        try {
            (new Accounting(Clients::client($transport)))->latestSnapshot('t1', 'c1', 'customer');
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(404, $problem->httpStatus);
            self::assertSame('accounting.snapshot_not_found', $problem->problemCode);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function snapshotProblems(): iterable
    {
        yield 'refresh too soon' => [409, 'accounting.snapshot_refresh_too_soon'];
        yield 'capacity' => [503, 'accounting.snapshot_capacity'];
        yield 'not enabled' => [403, 'accounting.snapshot_not_enabled'];
        yield 'unsupported' => [422, 'accounting.snapshot_unsupported'];
        yield 'conflict' => [409, 'accounting.snapshot_conflict'];
        yield 'refused' => [400, 'accounting.snapshot_refused'];
    }

    #[DataProvider('snapshotProblems')]
    public function testStartSnapshotProblemsRaise(int $status, string $code): void
    {
        $transport = new ScriptedTransport([Clients::json($status, ['code' => $code, 'request_id' => 'r'])]);
        try {
            (new Accounting(Clients::client($transport)))->startSnapshot('t1', 'c1', 'customer', true);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame($status, $problem->httpStatus);
            self::assertSame($code, $problem->problemCode);
        }
    }

    // ---- POST .../bind-jobs -------------------------------------------------

    public function testStartBindJobPostsBindingsAndReturnsTheJob(): void
    {
        $bindings = [['erp_id' => 'I1', 'provider_ref' => '58'], ['erp_id' => 'I2', 'provider_ref' => '59']];
        $transport = new ScriptedTransport([Clients::json(202, self::bindJob())]);
        $out = (new Accounting(Clients::client($transport)))->startBindJob('t1', 'c 1', 'item', $bindings);

        self::assertSame('POST', self::method($transport, 0));
        self::assertSame(self::C . '/records/item/bind-jobs', $transport->target(0));
        self::assertSame(['tenant_id' => 't1', 'bindings' => $bindings], $transport->body(0));
        self::assertSame(self::bindJob(), $out);
    }

    public function testBindJobInProgressIsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(409, ['code' => 'accounting.bind_job_in_progress', 'request_id' => 'r', 'job_id' => 'job_1'])]);
        try {
            (new Accounting(Clients::client($transport)))->startBindJob('t1', 'c1', 'customer', [['erp_id' => 'C1', 'provider_ref' => '1']]);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(409, $problem->httpStatus);
            self::assertSame('accounting.bind_job_in_progress', $problem->problemCode);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function bindJobProblems(): iterable
    {
        yield 'too large' => [413, 'accounting.bind_job_too_large'];
        yield 'not enabled' => [403, 'accounting.bind_job_not_enabled'];
        yield 'unsupported' => [422, 'accounting.bind_job_unsupported'];
        yield 'refused' => [400, 'accounting.bind_job_refused'];
    }

    #[DataProvider('bindJobProblems')]
    public function testStartBindJobProblemsRaise(int $status, string $code): void
    {
        $transport = new ScriptedTransport([Clients::json($status, ['code' => $code, 'request_id' => 'r'])]);
        try {
            (new Accounting(Clients::client($transport)))->startBindJob('t1', 'c1', 'customer', [['erp_id' => 'C1', 'provider_ref' => '1']]);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame($status, $problem->httpStatus);
            self::assertSame($code, $problem->problemCode);
        }
    }

    // ---- GET .../bind-jobs/{job_id}/results ---------------------------------

    public function testBindJobResultsEncodesJobIdAndOmitsNullCursor(): void
    {
        $page = self::bindJob('completed') + ['results' => [['erp_id' => 'I1', 'status' => 'bound']], 'next_cursor' => 'n2'];
        $transport = new ScriptedTransport([Clients::json(200, $page), Clients::json(200, $page)]);
        $a = new Accounting(Clients::client($transport));
        $out = $a->bindJobResults('t1', 'c 1', 'item', 'job/7');
        $a->bindJobResults('t1', 'c 1', 'item', 'job/7', 'n 2');

        self::assertSame('GET', self::method($transport, 0));
        self::assertSame(self::C . '/records/item/bind-jobs/job%2F7/results?tenant_id=t1', $transport->target(0));
        self::assertSame(self::C . '/records/item/bind-jobs/job%2F7/results?tenant_id=t1&cursor=n%202', $transport->target(1));
        self::assertSame($page, $out);
    }

    public function testBindJobResultsNotFoundIsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(404, ['code' => 'accounting.bind_job_not_found', 'request_id' => 'r'])]);
        try {
            (new Accounting(Clients::client($transport)))->bindJobResults('t1', 'c1', 'customer', 'job_x');
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(404, $problem->httpStatus);
            self::assertSame('accounting.bind_job_not_found', $problem->problemCode);
        }
    }

    public function testBindJobResultsRejectsAnEmptyJobIdBeforeSending(): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Accounting(Clients::client($transport)))->bindJobResults('t1', 'c1', 'customer', '');
            self::fail('accepted an empty job id');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }

    // ---- client-side entity validation ---------------------------------------

    public function testRejectsUnknownEntitiesBeforeSending(): void
    {
        $transport = new ScriptedTransport([]);
        $a = new Accounting(Clients::client($transport));
        $one = [['erp_id' => 'X', 'provider_ref' => '1']];
        foreach ([
            'snapshot vendor' => fn() => $a->startSnapshot('t', 'c', 'vendor'),
            'latest vendor' => fn() => $a->latestSnapshot('t', 'c', 'vendor'),
            'bind job vendor' => fn() => $a->startBindJob('t', 'c', 'vendor', $one),
            'results vendor' => fn() => $a->bindJobResults('t', 'c', 'vendor', 'job_1'),
            'list vendor' => fn() => $a->listRecords('t', 'c', 'vendor', source: 'live'),
            'match vendor' => fn() => $a->matchRecords('t', 'c', 'vendor', [['erp_id' => 'X', 'name' => 'N']], 'auto'),
        ] as $label => $call) {
            try {
                $call();
                self::fail('accepted ' . $label);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $transport->requests);
    }

    /** The snapshot routes' {entity} enum is customer|item only; tax_code is a 400 there. */
    public function testSnapshotsRejectTaxCodeBeforeSending(): void
    {
        $transport = new ScriptedTransport([]);
        $a = new Accounting(Clients::client($transport));
        foreach ([
            'snapshot tax_code' => fn() => $a->startSnapshot('t', 'c', 'tax_code'),
            'latest tax_code' => fn() => $a->latestSnapshot('t', 'c', 'tax_code'),
        ] as $label => $call) {
            try {
                $call();
                self::fail('accepted ' . $label);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $transport->requests);
    }

    // ---- match: optional `source` -------------------------------------------

    public function testMatchWithoutSourceSendsTheOriginalBody(): void
    {
        $records = [['erp_id' => 'I1', 'name' => 'Consulting', 'sku' => 'CONS']];
        $transport = new ScriptedTransport([Clients::json(200, ['results' => [], 'unsupported_keys' => [], 'source' => 'live', 'as_of' => null, 'snapshot_id' => null])]);
        (new Accounting(Clients::client($transport)))->matchRecords('t1', 'c 1', 'item', $records);

        self::assertSame('POST', self::method($transport, 0));
        self::assertSame(self::C . '/records/item/match', $transport->target(0));
        self::assertSame(['tenant_id' => 't1', 'records' => $records], $transport->body(0));
        self::assertStringNotContainsString('source', (string) $transport->requests[0]->getBody());
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        yield 'auto' => ['auto'];
        yield 'snapshot' => ['snapshot'];
        yield 'live' => ['live'];
    }

    #[DataProvider('sources')]
    public function testMatchSendsTheGivenSource(string $source): void
    {
        $response = ['results' => [['erp_id' => 'C1', 'status' => 'none', 'provider_ref' => null, 'matched_on' => null, 'candidates' => []]], 'unsupported_keys' => [], 'source' => 'snapshot', 'as_of' => '2026-11-11T00:00:00Z', 'snapshot_id' => 'snap_1'];
        $transport = new ScriptedTransport([Clients::json(200, $response)]);
        $out = (new Accounting(Clients::client($transport)))->matchRecords('t1', 'c 1', 'customer', [['erp_id' => 'C1', 'name' => 'Acme']], source: $source);

        $body = $transport->body(0);
        self::assertSame($source, $body['source'] ?? null);
        self::assertSame('t1', $body['tenant_id']);
        self::assertSame([['erp_id' => 'C1', 'name' => 'Acme']], $body['records']);
        self::assertSame($response, $out);
    }

    public function testMatchSnapshotNotReadyIsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(409, ['code' => 'accounting.snapshot_not_ready', 'request_id' => 'r', 'snapshot_id' => 'snap_1'])]);
        try {
            (new Accounting(Clients::client($transport)))->matchRecords('t1', 'c1', 'customer', [['erp_id' => 'C1', 'name' => 'Acme']], 'snapshot');
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(409, $problem->httpStatus);
            self::assertSame('accounting.snapshot_not_ready', $problem->problemCode);
        }
    }

    // ---- list: optional `source` and `q` ------------------------------------

    public function testListRecordsQueryOmitsNullsAndKeepsTheOldShape(): void
    {
        $page = ['records' => [], 'next_cursor' => null];
        $transport = new ScriptedTransport(array_fill(0, 5, Clients::json(200, $page)));
        $a = new Accounting(Clients::client($transport));
        $out = $a->listRecords('t1', 'c 1', 'customer');
        $a->listRecords('t1', 'c 1', 'customer', 'n2', 50);
        $a->listRecords('t1', 'c 1', 'customer', source: 'snapshot');
        $a->listRecords('t1', 'c 1', 'item', q: 'acme co');
        $a->listRecords('t1', 'c 1', 'customer', 'n2', 10, 'live', 'a@b.example');

        $base = self::C . '/records/';
        self::assertSame([
            $base . 'customer?tenant_id=t1',
            $base . 'customer?tenant_id=t1&cursor=n2&limit=50',
            $base . 'customer?tenant_id=t1&source=snapshot',
            $base . 'item?tenant_id=t1&q=acme%20co',
            $base . 'customer?tenant_id=t1&cursor=n2&limit=10&source=live&q=a%40b.example',
        ], array_map(fn(int $i) => $transport->target($i), array_keys($transport->requests)));
        foreach (array_keys($transport->requests) as $i) {
            self::assertSame('GET', self::method($transport, $i));
        }
        self::assertSame($page, $out);
    }

    public function testListRecordsSnapshotMissingIsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(404, ['code' => 'accounting.snapshot_not_found', 'request_id' => 'r'])]);
        try {
            (new Accounting(Clients::client($transport)))->listRecords('t1', 'c1', 'customer', q: 'acme');
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(404, $problem->httpStatus);
            self::assertSame('accounting.snapshot_not_found', $problem->problemCode);
        }
    }
}
