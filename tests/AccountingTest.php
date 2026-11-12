<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\OperationReceipt;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Accounting::class)]
#[CoversClass(OperationReceipt::class)]
final class AccountingTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>}> */
    public static function catalog(): array
    {
        // `pnpm agent:check` validates these fixtures against the runtime's own
        // neutral request schema; building the same request here proves the SDK
        // sends exactly that body.
        $path = dirname(__DIR__, 3) . '/docs-site/content/accounting-operations.json';
        if (! is_file($path)) {
            return []; // split package checkout: the catalog lives in the monorepo
        }
        $cases = [];
        foreach (Clients::obj(Clients::decodeFile($path), 'operations') as $operation => $entry) {
            $cases[$operation] = [$operation, is_array($entry) ? Clients::obj(['r' => $entry], 'r') : []];
        }

        return $cases;
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('catalog')]
    public function testBuildsTheDocumentedRequestForEveryOperation(string $operation, array $expected): void
    {
        $built = Accounting::buildRequest(
            $operation,
            Clients::str($expected, 'tenant_id'),
            Clients::str($expected, 'erp_id'),
            Clients::str($expected, 'idempotency_key'),
            Clients::obj($expected, 'payload'),
        );
        self::assertEquals($expected, $built);
    }

    public function testEveryOperationHasADocumentedExample(): void
    {
        if (self::catalog() === []) {
            self::markTestSkipped('operation catalog is only available in the monorepo');
        }
        self::assertEqualsCanonicalizing(Accounting::OPERATIONS, array_keys(self::catalog()));
    }

    public function testWritesPostTheNeutralBodyWithNoProviderFields(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(202, ['job_id' => 'job_1', 'polling_url' => '/v1/accounting/operations/job_1?tenant_id=t1', 'status' => 'queued', 'operation' => 'accounting.payment.create', 'idempotent_replay' => false]),
            Clients::json(202, ['job_id' => 'job_2', 'polling_url' => '/v1/accounting/operations/job_2?tenant_id=t1', 'status' => 'queued']),
        ]);
        $accounting = new Accounting(Clients::client($transport));
        $receipt = $accounting->createPayment('t1', 'PAY-1', 'payment:PAY-1', ['payment' => ['amount' => '1.00']]);
        $second = $accounting->createInvoice('t1', 'INV-1', 'invoice:INV-1', ['invoice' => []], connectionId: 'c1');

        self::assertSame('job_1', $receipt->jobId);
        self::assertFalse($receipt->idempotentReplay);
        self::assertNull($second->idempotentReplay);
        self::assertSame('/v1/accounting/operations/job_1?tenant_id=t1', $receipt->pollingUrl);
        self::assertSame('/v1/accounting/operations', $transport->target(0));
        self::assertSame(
            ['tenant_id' => 't1', 'operation' => 'accounting.payment.create', 'erp_id' => 'PAY-1', 'idempotency_key' => 'payment:PAY-1', 'payload' => ['payment' => ['amount' => '1.00']]],
            $transport->body(0),
        );
        self::assertSame('c1', $transport->body(1)['connection_id']);
        self::assertArrayNotHasKey('provider_key', $transport->body(1));
    }

    public function testRejectsUnknownOperationsAndUnstableKeys(): void
    {
        foreach (['', '   ', str_repeat('k', 129)] as $key) {
            try {
                Accounting::buildRequest('accounting.invoice.create', 't', 'INV-1', $key, ['invoice' => []]);
                self::fail('accepted key ' . var_export($key, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['quickbooks.invoice.create', 'accounting.journal.create'] as $operation) {
            try {
                Accounting::buildRequest($operation, 't', 'e', 'k', ['x' => 1]);
                self::fail('accepted ' . $operation);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Accounting::buildRequest('accounting.invoice.create', 't', 'e', 'k', []);
    }

    public function testRevisionConflictSurfacesAsAProblem(): void
    {
        $transport = new ScriptedTransport([Clients::json(409, ['code' => 'accounting.revision_conflict', 'request_id' => 'req_1'])]);
        try {
            (new Accounting(Clients::client($transport)))->voidInvoice('t', 'INV-1', 'void:1', ['void_id' => 'V1']);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame(409, $problem->httpStatus);
            self::assertSame('accounting.revision_conflict', $problem->problemCode);
        }
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function accountingProblems(): iterable
    {
        yield 'mapping missing' => [409, 'accounting.mapping_missing', RuntimeProblemException::FIX_SETUP];
        yield 'record type off' => [409, 'accounting.record_type_disabled', RuntimeProblemException::FIX_SETUP];
        yield 'unlinked' => [409, 'accounting.document_unlinked', RuntimeProblemException::FIX_SETUP];
        yield 'no connection' => [409, 'accounting.no_accounting_connection', RuntimeProblemException::FIX_SETUP];
        yield 'revision' => [409, 'accounting.revision_conflict', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'amount' => [409, 'accounting.amount_exceeded', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'tax' => [409, 'accounting.tax_mismatch', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'number exists' => [409, 'accounting.document_exists', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'period closed' => [409, 'accounting.period_closed', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'before start' => [409, 'accounting.before_sync_start', RuntimeProblemException::DO_NOT_SYNC];
        yield 'unsupported' => [422, 'accounting.capability_unsupported', RuntimeProblemException::DO_NOT_SYNC];
        yield 'unavailable' => [503, 'accounting.provider_unavailable', RuntimeProblemException::RETRY_SAME_REQUEST];
        yield 'rate limited' => [429, 'accounting.rate_limited', RuntimeProblemException::RETRY_SAME_REQUEST];
        yield 'mapping conflict' => [409, 'accounting.mapping_conflict', RuntimeProblemException::FIX_REQUEST];
        yield 'settings conflict' => [409, 'accounting.settings_conflict', RuntimeProblemException::FIX_REQUEST];
        yield 'invalid' => [400, 'accounting.invalid_input', RuntimeProblemException::FIX_REQUEST];
        yield 'idempotency' => [409, 'accounting.idempotency_conflict', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'in progress' => [409, 'accounting.operation_in_progress', RuntimeProblemException::RETRY_SAME_REQUEST];
        yield 'link conflict' => [409, 'accounting.link_conflict', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'write uncertain' => [409, 'accounting.write_uncertain', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'refund incomplete' => [409, 'accounting.refund_incomplete', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'disabled' => [403, 'accounting.operation_disabled', RuntimeProblemException::ASK_OPERATOR];
        yield 'unknown 409' => [409, 'accounting.something_new', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'unknown 422' => [422, 'accounting.something_new', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'unknown 400' => [400, 'accounting.something_new', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'not found' => [404, 'accounting.connection_not_found', RuntimeProblemException::FIX_REQUEST];
        yield 'connection exists' => [409, 'accounting.connection_exists', RuntimeProblemException::FIX_SETUP];
        yield 'native refusal 422' => [422, 'accounting.provider_refused', RuntimeProblemException::FIX_REQUEST];
        yield 'native refusal 409' => [409, 'accounting.provider_refused', RuntimeProblemException::STOP_AND_RECONCILE];
    }

    #[DataProvider('accountingProblems')]
    public function testMapsAccountingProblemsToTheDocumentedDecision(int $status, string $code, string $decision): void
    {
        $transport = new ScriptedTransport([Clients::json($status, ['code' => $code, 'request_id' => 'req_1'])]);
        try {
            (new Accounting(Clients::client($transport)))->createInvoice('t', 'INV-1', 'invoice:INV-1:create', ['invoice' => []]);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame($decision, $problem->decision());
        }
    }

    /**
     * Every code in the docs Errors table (the same file the runtime ships)
     * decides what its decision() column says, at the HTTP status it lists.
     */
    public function testDecisionMatchesTheDocsErrorsTable(): void
    {
        $docs = (string) file_get_contents(__DIR__ . '/../../../docs-site/content/accounting-operations.md');
        $table = substr($docs, (int) strpos($docs, '## Errors'), (int) strpos($docs, '## Retry rules') - (int) strpos($docs, '## Errors'));
        $checked = 0;
        $documented = [];
        foreach (explode("\n", $table) as $line) {
            if (! str_starts_with($line, '| `accounting.')) {
                continue;
            }
            $cells = array_map('trim', explode('|', $line));
            $decision = str_replace('`', '', $cells[count($cells) - 2]);
            preg_match_all('/`(accounting\.[a-z_]+)`([^`]*)/', $cells[1], $codes, PREG_SET_ORDER);
            foreach ($codes as [, $code, $after]) {
                $documented[] = $code;
                // The status after this code, else the row's first one; "(native status)" rows try two.
                $statuses = match (true) {
                    preg_match('/\((\d{3})/', $after, $m) === 1 => [(int) $m[1]],
                    preg_match('/\((\d{3})/', $cells[1], $m) === 1 => [(int) $m[1]],
                    default => [409, 422],
                };
                foreach ($statuses as $status) {
                    $expected = $decision === 'by HTTP status' ? (new RuntimeProblemException($status, 'native.code'))->decision() : $decision;
                    self::assertSame($expected, (new RuntimeProblemException($status, $code))->decision(), $code . ' (' . $status . ')');
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(25, $checked);
        // And the SDK maps no code the table leaves out.
        $map = (new \ReflectionClassConstant(RuntimeProblemException::class, 'ACCOUNTING_DECISIONS'))->getValue();
        $mapped = is_array($map) ? array_keys($map) : [];
        self::assertNotSame([], $mapped);
        self::assertSame([], array_values(array_diff($mapped, $documented)));
    }

    public function testReadsAndAdminCallsUseTheDocumentedRoutes(): void
    {
        $ok = static fn(array $body = ['ok' => true]) => Clients::json(200, $body);
        $transport = new ScriptedTransport([
            $ok(['job_id' => 'j/1', 'status' => 'completed']),
            $ok(['connection_state' => 'connected', 'connection' => ['connection_id' => 'c 1', 'provider_key' => 'quickbooks', 'provider_account_id' => '1']]),
            $ok(),
            $ok(),
            $ok(),
            $ok(),
            $ok(),
            Clients::json(204, null),
            $ok(),
            $ok(),
            $ok(),
            $ok(),
            $ok(),
            $ok(),
        ]);
        $a = new Accounting(Clients::client($transport));
        $a->status('t1', 'j/1');
        self::assertSame('c 1', $a->connectionId('t1'));
        $a->refresh('t1', 'c 1', 'invoice', 'INV 1');
        $a->link('t1', 'c 1', 'INV-1', [['erp_line_id' => 'L1', 'provider_line_id' => '1']]);
        $a->referenceData('t1', 'c 1', 'tax_code', name: 'HST ON');
        $a->getMapping('t1', 'c 1', 'tax_code', 'HST');
        $a->setMapping('t1', 'c 1', 'tax_code', 'HST', '5', 'taxable');
        $a->deleteMapping('t1', 'c 1', 'payment_method', 'VISA');
        $a->settings('t1', 'c 1');
        $a->updateSettings('t1', 'c 1', ['invoice', 'payment'], 3);
        $a->listRecords('t1', 'c 1', 'customer', cursor: 'n2', limit: 50);
        $a->referenceOptions('t1', 'c 1', 'deposit_account', limit: 25);
        $a->matchRecords('t1', 'c 1', 'item', [['erp_id' => 'I1', 'name' => 'Consulting']]);
        $a->bindRecords('t1', 'c 1', 'customer', [['erp_id' => 'C1', 'provider_ref' => '58']]);

        $c = '/v1/accounting/connections/c%201';
        self::assertSame([
            ['GET', '/v1/accounting/operations/j%2F1?tenant_id=t1'],
            ['GET', '/v1/accounting/providers?tenant_id=t1'],
            ['GET', $c . '/documents/invoice/INV%201?tenant_id=t1'],
            ['PUT', $c . '/documents/invoice/INV-1/link'],
            ['GET', $c . '/reference-data/tax_code?tenant_id=t1&name=HST%20ON'],
            ['GET', $c . '/mappings/tax_code/HST?tenant_id=t1'],
            ['PUT', $c . '/mappings/tax_code/HST'],
            ['DELETE', $c . '/mappings/payment_method/VISA?tenant_id=t1'],
            ['GET', $c . '/settings?tenant_id=t1'],
            ['PUT', $c . '/settings'],
            ['GET', $c . '/records/customer?tenant_id=t1&cursor=n2&limit=50'],
            ['GET', $c . '/reference-data/deposit_account/options?tenant_id=t1&limit=25'],
            ['POST', $c . '/records/item/match'],
            ['POST', $c . '/records/customer/bind'],
        ], array_map(fn(int $i) => [$transport->requests[$i]->getMethod(), $transport->target($i)], array_keys($transport->requests)));
        self::assertSame(['tenant_id' => 't1', 'lines' => [['erp_line_id' => 'L1', 'provider_line_id' => '1']]], $transport->body(3));
        self::assertSame(['tenant_id' => 't1', 'provider_ref' => '5', 'treatment' => 'taxable'], $transport->body(6));
        self::assertSame(['tenant_id' => 't1', 'record_types' => ['invoice', 'payment'], 'expected_revision' => 3], $transport->body(9));
    }

    public function testConnectionIdRefusesWhenTheTenantHasNoSingleConnection(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['connection_state' => 'multiple', 'connection' => null, 'providers' => []])]);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('multiple');
        (new Accounting(Clients::client($transport)))->connectionId('t1');
    }

    public function testRejectsUnsupportedReadTargetsBeforeSending(): void
    {
        $a = new Accounting(Clients::client(new ScriptedTransport([])));
        foreach ([
            fn() => $a->refresh('t', 'c', 'refund', 'R1'),
            fn() => $a->listRecords('t', 'c', 'vendor'),
            fn() => $a->link('t', 'c', 'I1', []),
            fn() => $a->referenceData('t', 'c', 'tax_code'),
            fn() => $a->referenceData('t', 'c', 'tax_code', '5', 'HST ON'),
        ] as $call) {
            try {
                $call();
                self::fail('accepted an unsupported target');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
