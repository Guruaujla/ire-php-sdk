<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\OperationReceipt;
use Ezee\IntegrationRuntime\QuickBooks;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuickBooks::class)]
#[CoversClass(OperationReceipt::class)]
#[CoversClass(RuntimeProblemException::class)]
final class QuickBooksTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>}> */
    public static function catalog(): array
    {
        // The docs fixtures are validated against the runtime's own admission
        // and payload validators by `pnpm agent:check`. Building the same
        // request here proves the SDK's recipe identity table agrees with them.
        $path = dirname(__DIR__, 3) . '/docs-site/content/quickbooks-operations.json';
        if (! is_file($path)) {
            return []; // split package checkout: the catalog lives in the monorepo
        }
        $cases = [];
        foreach (Clients::obj(Clients::decodeFile($path), 'operations') as $operation => $entry) {
            $cases[$operation] = [$operation, Clients::obj(is_array($entry) ? $entry : [], 'request')];
        }

        return $cases;
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('catalog')]
    public function testBuildsTheDocumentedRequestForEveryOperation(string $operation, array $expected): void
    {
        $built = QuickBooks::buildRequest(
            $operation,
            Clients::str($expected, 'tenant_id'),
            Clients::str($expected, 'connection_id'),
            Clients::str($expected, 'internal_object_id'),
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
        self::assertEqualsCanonicalizing(array_keys(QuickBooks::OPERATIONS), array_keys(self::catalog()));
    }

    public function testSubmitPostsOnceAndReturnsTheReceipt(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, ['job_id' => 'job_1', 'polling_url' => '/v1/sync-jobs/job_1?tenant_id=t1', 'status' => 'queued', 'correlation_id' => null])]);
        $receipt = (new QuickBooks(Clients::client($transport)))->createPayment('t1', 'c1', 'pay-1', 'erp_payment_pay-1', ['payment' => ['TotalAmt' => '1.00']]);

        self::assertSame('job_1', $receipt->jobId);
        self::assertSame('/v1/sync-jobs/job_1?tenant_id=t1', $receipt->pollingUrl);
        self::assertCount(1, $transport->requests);
        self::assertSame('/v1/operations/execute', $transport->target(0));
        $body = $transport->body(0);
        self::assertSame('erp.quickbooks.payment.created', $body['recipe_key']);
        self::assertSame(2, $body['recipe_version']);
        self::assertSame('erp_payment_pay-1', $body['idempotency_key']);
    }

    public function testMasterUpsertDerivesTheDocumentedKey(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, ['job_id' => 'j', 'polling_url' => '/v1/sync-jobs/j?tenant_id=t', 'status' => 'queued'])]);
        (new QuickBooks(Clients::client($transport)))->upsertMaster('t', 'c', [
            'command_id' => 'cmd-1', 'erp_id' => 'e1', 'action' => 'create', 'expected_company_id' => '123', 'entity_type' => 'Item',
            'record' => ['name' => 'X', 'item_type' => 'Service', 'income_account_id' => '1', 'unit_price_minor' => 100],
        ]);
        $body = $transport->body(0);
        self::assertSame('master:123:Item:cmd-1', $body['idempotency_key']);
        self::assertSame('cmd-1', $body['internal_object_id']);
        self::assertSame('erp.quickbooks.item.sync', $body['recipe_key']);
    }

    public function testRejectsMissingOrOversizedIdempotencyKeys(): void
    {
        foreach (['', '   ', str_repeat('k', 129)] as $key) {
            try {
                QuickBooks::buildRequest('quickbooks.invoice.create', 't', 'c', 'i', $key, ['invoice' => []]);
                self::fail('accepted key ' . var_export($key, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        QuickBooks::buildRequest('quickbooks.journal.create', 't', 'c', 'i', 'k', ['x' => 1]);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function problems(): iterable
    {
        yield 'conflict' => [409, 'idempotency_key_conflict', RuntimeProblemException::STOP_AND_RECONCILE];
        yield 'retired' => [409, 'recipe_version_retired', RuntimeProblemException::FIX_REQUEST];
        yield 'not enabled' => [403, 'operation_not_enabled', RuntimeProblemException::ASK_OPERATOR];
        yield 'quota' => [429, 'rate_limit.quota_exceeded', RuntimeProblemException::RETRY_SAME_REQUEST];
        yield 'signature' => [401, 'hmac.signature_invalid', RuntimeProblemException::FIX_CREDENTIALS];
        yield 'nonce' => [401, 'hmac.nonce_reused', RuntimeProblemException::RETRY_SAME_REQUEST];
        yield 'tax' => [400, 'financial_write_tax_payload_invalid', RuntimeProblemException::FIX_REQUEST];
        yield 'internal' => [500, 'internal_error', RuntimeProblemException::STOP_AND_RECONCILE];
    }

    #[DataProvider('problems')]
    public function testMapsProblemsToTheDocumentedDecision(int $status, string $code, string $decision): void
    {
        $transport = new ScriptedTransport([Clients::json($status, ['code' => $code, 'request_id' => 'req_1'], $status === 429 ? ['Retry-After' => '7'] : [])]);
        try {
            (new QuickBooks(Clients::client($transport)))->submit(QuickBooks::buildRequest('quickbooks.invoice.create', 't', 'c', 'i', 'k', ['invoice' => []]));
            self::fail('expected a problem');
        } catch (RuntimeProblemException $problem) {
            self::assertSame($code, $problem->problemCode);
            self::assertSame('req_1', $problem->requestId);
            self::assertSame($decision, $problem->decision());
            self::assertSame($status === 429 ? 7 : null, $problem->retryAfterSeconds);
        }
    }

    public function testReadsUseScopedQueryStrings(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['id' => '7']), Clients::json(200, ['balance_minor' => 0]), Clients::json(200, ['erp_id' => 'e 1'])]);
        $qb = new QuickBooks(Clients::client($transport));
        $qb->findMaster('t1', 'c1', 'Customer', name: 'Acme & Co');
        $qb->status('t1', 'c1', 'Invoice', '101', refresh: true);
        $qb->getMasterMapping('t1', 'c1', 'Item', 'e 1');
        self::assertSame('/v1/connections/c1/quickbooks/master/Customer?tenant_id=t1&name=Acme%20%26%20Co', $transport->target(0));
        self::assertSame('/v1/connections/c1/quickbooks/status/Invoice/101?tenant_id=t1&refresh=1', $transport->target(1));
        self::assertSame('/v1/connections/c1/quickbooks/master/mappings/Item/e%201?tenant_id=t1', $transport->target(2));
        self::assertSame('GET', $transport->requests[0]->getMethod());
    }
}
