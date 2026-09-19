<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\ConnectionResult;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\IdempotencyConflictException;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\MockRuntimeTransport;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\PendingConnectAttempt;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\QuickBooksInvoiceJourney;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\RuntimeIdentity;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\RuntimeResponseException;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\SubmissionOutcomeUnknownException;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\UnverifiedAuthorizationFailure;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../examples/quickbooks-invoice/QuickBooksInvoiceJourney.php';
require_once __DIR__ . '/../examples/quickbooks-invoice/MockRuntimeTransport.php';

final class ExampleTransportException extends \RuntimeException implements ClientExceptionInterface {}

final class ExampleQueueTransport implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|ClientExceptionInterface> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \OutOfBoundsException('example response queue is empty');
        }
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }
}

/** @phpstan-import-type InvoiceRequest from QuickBooksInvoiceJourney */
final class QuickBooksInvoiceExampleTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private const TENANT_ID = 'tenant_example_001';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $responses
     * @param (\Closure(float): void)|null $sleep
     *
     * @return array{QuickBooksInvoiceJourney, ExampleQueueTransport}
     */
    private function journey(
        array $responses,
        ?\Closure $sleep = null,
        int $maxPollAttempts = 5,
        ?\Closure $now = null,
    ): array {
        $transport = new ExampleQueueTransport($responses);
        $factory = new HttpFactory();
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.example.test',
            'app_erp_example',
            'key_example',
            self::SECRET,
            RetryPolicy::disabled(),
        );

        return [
            new QuickBooksInvoiceJourney(
                $client,
                new RuntimeIdentity('app_erp_example', 'key_example', self::TENANT_ID),
                $sleep,
                $maxPollAttempts,
                now: $now ?? static fn(): int => 1_700_000_000,
            ),
            $transport,
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => $status >= 400 ? 'application/problem+json' : 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function accepted(string $pollingUrl = ''): array
    {
        return [
            'job_id' => 'job_invoice_001',
            'correlation_id' => 'cor_invoice_001',
            'status' => 'queued',
            'polling_url' => $pollingUrl !== ''
                ? $pollingUrl
                : '/v1/sync-jobs/job_invoice_001?tenant_id=' . self::TENANT_ID,
        ];
    }

    /** @return array<string, mixed> */
    private function job(string $status): array
    {
        return [
            'id' => 'job_invoice_001',
            'app_id' => 'app_erp_example',
            'status' => $status,
            'tenant_id' => self::TENANT_ID,
            'connection_id' => 'conn_qb_001',
            'last_error_code' => null,
            'steps' => [],
        ];
    }

    /** @return InvoiceRequest */
    private function invoiceRequest(QuickBooksInvoiceJourney $journey): array
    {
        return $journey->buildInvoiceRequest(
            'conn_qb_001',
            'erp-invoice-1001',
            'qb-customer-12',
            'qb-item-34',
            'qb-tax-code-56',
            '100.00',
            'taxable',
        );
    }

    private function activeAttempt(
        string $state,
        ?string $expectedConnectionId = null,
        int $expiresAt = 1_800_000_000,
    ): PendingConnectAttempt {
        $attempt = new PendingConnectAttempt(self::TENANT_ID, $state, $expectedConnectionId);
        $attempt->markSessionRequested();
        $attempt->recordSessionExpiry($expiresAt);

        return $attempt;
    }

    public function testFullJourneyUsesFreshNoncesAndPreservesReplayIdentity(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(201, [
                'session_id' => '01911111-1111-7111-8111-111111111111',
                'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?example=1',
                'expires_at' => 1_800_000_000,
                'connection_id' => null,
            ]),
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('processing')),
            $this->json(200, $this->job('completed')),
            $this->json(202, $this->accepted()),
            $this->json(409, [
                'code' => 'idempotency_key_conflict',
                'detail' => 'different payload',
            ]),
        ], static function (float $seconds): void {});

        $attempt = new PendingConnectAttempt(self::TENANT_ID, 'server-state-001');
        $session = $journey->createConnectSession(
            'https://erp.example.test/quickbooks/return',
            $attempt,
            'operator-42',
        );
        self::assertSame('https://appcenter.intuit.com/connect/oauth2?example=1', $session->authorizeUrl);

        $connection = $journey->acceptAuthorizationReturn(
            'https://erp.example.test/quickbooks/return?status=connected'
            . '&connection_id=conn_qb_001&state=server-state-001',
            $attempt,
        );
        self::assertInstanceOf(ConnectionResult::class, $connection);
        self::assertSame('conn_qb_001', $connection->connectionId);

        $request = $this->invoiceRequest($journey);
        $submission = $journey->submitInvoice($request);
        self::assertSame('job_invoice_001', $submission->jobId);
        self::assertSame('completed', $journey->pollToTerminal($submission)->kind);

        $replay = $journey->submitInvoice($submission->request);
        self::assertSame($submission->jobId, $replay->jobId);

        $changed = $journey->buildInvoiceRequest(
            'conn_qb_001',
            'erp-invoice-1001',
            'qb-customer-12',
            'qb-item-34',
            'qb-tax-code-56',
            '101.00',
            'taxable',
        );
        try {
            $journey->submitInvoice($changed);
            self::fail('changed payload with the same key should conflict');
        } catch (IdempotencyConflictException $exception) {
            self::assertSame('idempotency_key_conflict', $exception->problemCode);
        }

        self::assertCount(6, $transport->requests);
        $nonces = array_map(
            static fn(RequestInterface $request): string => $request->getHeaderLine('X-IR-Nonce'),
            $transport->requests,
        );
        self::assertCount(6, array_unique($nonces), 'every request must carry a fresh signing nonce');

        $firstSubmit = $transport->requests[1];
        $exactReplay = $transport->requests[4];
        $conflict = $transport->requests[5];
        self::assertSame((string) $firstSubmit->getBody(), (string) $exactReplay->getBody());
        self::assertNotSame((string) $firstSubmit->getBody(), (string) $conflict->getBody());
        $firstBody = json_decode((string) $firstSubmit->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $conflictBody = json_decode((string) $conflict->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($firstBody);
        self::assertIsArray($conflictBody);
        self::assertSame($firstBody['idempotency_key'], $conflictBody['idempotency_key']);
    }

    public function testAuthorizationHandoffAcceptsAlreadyConnectedAndRejectsReplay(): void
    {
        [$journey] = $this->journey([]);
        $attempt = $this->activeAttempt('state-reconnect', 'conn_qb_001');
        $url = 'https://erp.example.test/return?status=already_connected'
            . '&connection_id=conn_qb_001&state=state-reconnect';

        $result = $journey->acceptAuthorizationReturn($url, $attempt);
        self::assertInstanceOf(ConnectionResult::class, $result);
        self::assertSame('already_connected', $result->status);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('already consumed');
        $journey->acceptAuthorizationReturn($url, $attempt);
    }

    public function testWrongOrMissingStateIsRejectedWithoutConsumingThePendingAttempt(): void
    {
        [$journey] = $this->journey([]);
        $attempt = $this->activeAttempt('expected-state');

        foreach (
            [
                'https://erp.example.test/return?status=connected&connection_id=conn_qb_001',
                'https://erp.example.test/return?status=connected&connection_id=conn_qb_001&state=wrong',
            ] as $url
        ) {
            try {
                $journey->acceptAuthorizationReturn($url, $attempt);
                self::fail('uncorrelated callback should fail');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString('state does not match', $exception->getMessage());
            }
        }

        $valid = 'https://erp.example.test/return?status=connected'
            . '&connection_id=conn_qb_001&state=expected-state';
        $result = $journey->acceptAuthorizationReturn($valid, $attempt);
        self::assertInstanceOf(ConnectionResult::class, $result);
        self::assertSame('conn_qb_001', $result->connectionId);
    }

    public function testStateLessFailureIsUnverifiedAndDoesNotConsumeThePendingAttempt(): void
    {
        [$journey] = $this->journey([]);
        $attempt = $this->activeAttempt('pending-after-failure');

        $result = $journey->acceptAuthorizationReturn(
            'https://erp.example.test/return?status=failed&reason=access_denied'
            . '&state=attacker-state&connection_id=conn_attacker&app_id=app_attacker&tenant_id=tenant_attacker',
            $attempt,
        );

        self::assertInstanceOf(UnverifiedAuthorizationFailure::class, $result);
        self::assertSame('access_denied', $result->reportedReason);
        self::assertTrue($result->requiresReconciliation());
        self::assertStringContainsString('not correlated', $result->message);

        $valid = 'https://erp.example.test/return?status=connected'
            . '&connection_id=conn_qb_001&state=pending-after-failure';
        $connection = $journey->acceptAuthorizationReturn($valid, $attempt);
        self::assertInstanceOf(ConnectionResult::class, $connection);
        self::assertSame('conn_qb_001', $connection->connectionId);
    }

    public function testUnknownOrMissingAuthorizationStatusIsRejectedWithoutConsuming(): void
    {
        foreach (
            [
                'https://erp.example.test/return?state=status-state&connection_id=conn_qb_001',
                'https://erp.example.test/return?status=cancelled&state=status-state&connection_id=conn_qb_001',
            ] as $url
        ) {
            [$journey] = $this->journey([]);
            $attempt = $this->activeAttempt('status-state');

            try {
                $journey->acceptAuthorizationReturn($url, $attempt);
                self::fail('missing or unknown callback status should fail');
            } catch (\UnexpectedValueException) {
                $valid = 'https://erp.example.test/return?status=connected'
                    . '&connection_id=conn_qb_001&state=status-state';
                $connection = $journey->acceptAuthorizationReturn($valid, $attempt);
                self::assertInstanceOf(ConnectionResult::class, $connection);
                self::assertSame('conn_qb_001', $connection->connectionId);
            }
        }
    }

    public function testReconnectConnectionMismatchIsRejectedAndConsumesTheAttempt(): void
    {
        [$journey] = $this->journey([]);
        $attempt = $this->activeAttempt('reconnect-state', 'conn_expected');
        $mismatch = 'https://erp.example.test/return?status=connected'
            . '&connection_id=conn_other&state=reconnect-state';

        try {
            $journey->acceptAuthorizationReturn($mismatch, $attempt);
            self::fail('mismatched reconnect identity should fail');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('pending reconnect', $exception->getMessage());
        }

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('already consumed');
        $journey->acceptAuthorizationReturn(
            'https://erp.example.test/return?status=connected'
            . '&connection_id=conn_expected&state=reconnect-state',
            $attempt,
        );
    }

    public function testConnectSessionFailureDoesNotCreateAnotherSessionImplicitly(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(500, ['code' => 'internal_error', 'detail' => 'unknown outcome']),
        ]);

        try {
            $journey->createConnectSession(
                'https://erp.example.test/return',
                new PendingConnectAttempt(self::TENANT_ID, 'connect-state'),
            );
            self::fail('500 should fail');
        } catch (\RuntimeException) {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testAuthorizationReturnAtSessionExpiryIsRejectedAndConsumed(): void
    {
        [$journey] = $this->journey([
            $this->json(201, [
                'session_id' => '01911111-1111-7111-8111-111111111111',
                'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?example=1',
                'expires_at' => 1_700_000_100,
                'connection_id' => null,
            ]),
        ], now: static fn(): int => 1_700_000_100);
        $attempt = new PendingConnectAttempt(self::TENANT_ID, 'expired-state');
        $journey->createConnectSession('https://erp.example.test/return', $attempt);
        $returnUrl = 'https://erp.example.test/return?status=connected'
            . '&connection_id=conn_qb_001&state=expired-state';

        try {
            $journey->acceptAuthorizationReturn($returnUrl, $attempt);
            self::fail('a callback at the session expiry must be rejected');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('session expired', $exception->getMessage());
        }

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('already consumed');
        $journey->acceptAuthorizationReturn($returnUrl, $attempt);
    }

    public function testSubmissionFiveHundredIsUnknownAndNeverBlindlyRetried(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(500, ['code' => 'internal_error', 'detail' => 'unknown outcome']),
        ]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('500 should be an unknown submission outcome');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertStringContainsString('may have landed', $exception->getMessage());
            self::assertSame($request, $exception->request);
            self::assertCount(1, $transport->requests);
        }
    }

    public function testSubmissionTransportErrorIsUnknownAndNeverBlindlyRetried(): void
    {
        [$journey, $transport] = $this->journey([
            new ExampleTransportException('connection closed'),
        ]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('transport failure should be an unknown submission outcome');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertStringContainsString('outcome is unknown', $exception->getMessage());
            self::assertSame($request, $exception->request);
            self::assertCount(1, $transport->requests);
        }
    }

    public function testTruncatedAcceptedBodyIsUnknownAndPreservesRequestIdentity(): void
    {
        [$journey, $transport] = $this->journey([
            new Response(202, ['Content-Type' => 'application/json'], '{"job_id":'),
        ]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('an unusable 202 acknowledgement should be an unknown submission outcome');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertStringContainsString('unusable acknowledgement', $exception->getMessage());
            self::assertSame($request, $exception->request);
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
            self::assertCount(1, $transport->requests);
        }
    }

    public function testAcceptedBodyMissingRequiredFieldIsUnknownAndPreservesRequestIdentity(): void
    {
        $accepted = $this->accepted();
        unset($accepted['polling_url']);
        [$journey, $transport] = $this->journey([$this->json(202, $accepted)]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('an incomplete 202 acknowledgement should be an unknown submission outcome');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertSame($request, $exception->request);
            self::assertCount(1, $transport->requests);
        }
    }

    public function testNonIdempotencyConflictKeepsItsActualProblemCode(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(409, [
                'code' => 'recipe_version_retired',
                'detail' => 'recipe version is no longer admitted',
            ]),
        ]);

        try {
            $journey->submitInvoice($this->invoiceRequest($journey));
            self::fail('retired recipe should be refused');
        } catch (RuntimeResponseException $exception) {
            self::assertNotInstanceOf(IdempotencyConflictException::class, $exception);
            self::assertSame('recipe_version_retired', $exception->problemCode);
            self::assertCount(1, $transport->requests);
        }
    }

    public function testMalformedConflictPreservesHttpStatusAndReconciliationGuidance(): void
    {
        [$journey, $transport] = $this->journey([
            new Response(409, ['Content-Type' => 'text/html'], '<html>truncated conflict'),
        ]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('a malformed 409 must remain a typed conflict response');
        } catch (RuntimeResponseException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertNull($exception->problemCode);
            self::assertStringContainsString('original request and idempotency key', $exception->getMessage());
            self::assertStringContainsString('never mint a replacement key', $exception->getMessage());
            self::assertCount(1, $transport->requests);
            self::assertSame('POST', $transport->requests[0]->getMethod());
        }
    }

    public function testPollingIsBoundedWithBackoff(): void
    {
        $delays = [];
        [$journey, $transport] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('queued')),
            $this->json(200, $this->job('processing')),
            $this->json(200, $this->job('processing')),
        ], static function (float $seconds) use (&$delays): void {
            $delays[] = $seconds;
        }, 3);

        $submission = $journey->submitInvoice($this->invoiceRequest($journey));
        $result = $journey->pollToTerminal($submission);

        self::assertSame('timed_out', $result->kind);
        self::assertSame('processing', $result->runtimeStatus);
        self::assertSame('processing', $result->job['status'] ?? null);
        self::assertTrue($result->requiresReconciliation());
        self::assertSame([0.25, 0.5], $delays);
        self::assertCount(4, $transport->requests);
    }

    public function testPollingHttpErrorRequiresPollingOnlyRecovery(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('queued')),
            $this->json(503, ['code' => 'internal_error', 'detail' => 'temporarily unavailable']),
        ]);
        $submission = $journey->submitInvoice($this->invoiceRequest($journey));

        $result = $journey->pollToTerminal($submission);

        self::assertSame('polling_error', $result->kind);
        self::assertSame('queued', $result->runtimeStatus);
        self::assertSame('queued', $result->job['status'] ?? null);
        self::assertStringContainsString('Retry only the polling URL', $result->message);
        self::assertCount(3, $transport->requests);
    }

    public function testPollingTransportErrorRequiresPollingOnlyRecovery(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('processing')),
            new ExampleTransportException('connection closed'),
        ]);
        $submission = $journey->submitInvoice($this->invoiceRequest($journey));

        $result = $journey->pollToTerminal($submission);

        self::assertSame('polling_error', $result->kind);
        self::assertSame('processing', $result->runtimeStatus);
        self::assertSame('processing', $result->job['status'] ?? null);
        self::assertStringContainsString('Retry only this GET', $result->message);
        self::assertCount(3, $transport->requests);
    }

    public function testMalformedSuccessfulPollBodyBecomesPollingError(): void
    {
        [$journey] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('queued')),
            new Response(200, ['Content-Type' => 'text/html'], '<html>upstream error</html>'),
        ]);
        $submission = $journey->submitInvoice($this->invoiceRequest($journey));

        $result = $journey->pollToTerminal($submission);

        self::assertSame('polling_error', $result->kind);
        self::assertSame('queued', $result->runtimeStatus);
        self::assertSame('queued', $result->job['status'] ?? null);
        self::assertStringContainsString('malformed body', $result->message);
        self::assertSame('job_invoice_001', $submission->jobId);
    }

    public function testMissingPollStatusRetainsTheLastValidObservation(): void
    {
        $withoutStatus = $this->job('processing');
        unset($withoutStatus['status']);
        [$journey] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('processing')),
            $this->json(200, $withoutStatus),
        ]);
        $submission = $journey->submitInvoice($this->invoiceRequest($journey));

        $result = $journey->pollToTerminal($submission);

        self::assertSame('polling_error', $result->kind);
        self::assertSame('processing', $result->runtimeStatus);
        self::assertSame('processing', $result->job['status'] ?? null);
        self::assertStringContainsString('no usable status', $result->message);
    }

    public function testUnknownFutureJobStatusIsPreservedForReconciliation(): void
    {
        [$journey] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('awaiting_provider_reconciliation')),
        ]);
        $submission = $journey->submitInvoice($this->invoiceRequest($journey));

        $result = $journey->pollToTerminal($submission);

        self::assertSame('unrecognized_status', $result->kind);
        self::assertSame('awaiting_provider_reconciliation', $result->runtimeStatus);
        self::assertSame('awaiting_provider_reconciliation', $result->job['status'] ?? null);
    }

    public function testPollingRejectsAJobForAnotherJobAppOrTenant(): void
    {
        foreach (
            [
                'id' => 'job_invoice_other',
                'app_id' => 'app_other',
                'tenant_id' => 'tenant_other',
            ] as $field => $wrongValue
        ) {
            $job = $this->job('completed');
            $job[$field] = $wrongValue;
            [$journey, $transport] = $this->journey([
                $this->json(202, $this->accepted()),
                $this->json(200, $job),
            ]);

            $result = $journey->pollToTerminal(
                $journey->submitInvoice($this->invoiceRequest($journey)),
            );

            self::assertSame('polling_error', $result->kind, $field);
            self::assertNull($result->runtimeStatus, $field);
            self::assertNull($result->job, $field);
            self::assertStringContainsString('mismatched job/app/tenant', $result->message, $field);
            self::assertCount(2, $transport->requests, $field);
        }
    }

    public function testMockTransportCompletesWithConfiguredAppAndTenantOverrides(): void
    {
        $factory = new HttpFactory();
        $transport = new MockRuntimeTransport(
            tenantId: 'tenant_custom_mock',
            appId: 'app_custom_mock',
        );
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.mock.invalid',
            'app_custom_mock',
            'key_custom_mock',
            self::SECRET,
            RetryPolicy::disabled(),
        );
        $journey = new QuickBooksInvoiceJourney(
            $client,
            new RuntimeIdentity('app_custom_mock', 'key_custom_mock', 'tenant_custom_mock'),
            sleep: static function (float $seconds): void {},
        );
        $request = $journey->buildInvoiceRequest(
            'conn_mock_quickbooks_001',
            'erp-invoice-1001',
            'qb-customer-12',
            'qb-item-34',
            'qb-tax-code-56',
            '100.00',
            'taxable',
        );

        $result = $journey->pollToTerminal($journey->submitInvoice($request));

        self::assertSame('completed', $result->kind);
        self::assertSame('app_custom_mock', $result->job['app_id'] ?? null);
        self::assertSame('tenant_custom_mock', $result->job['tenant_id'] ?? null);
    }

    public function testMismatchedLaterJobRetainsTheLastValidObservation(): void
    {
        $mismatched = $this->job('completed');
        $mismatched['id'] = 'job_invoice_other';
        [$journey] = $this->journey([
            $this->json(202, $this->accepted()),
            $this->json(200, $this->job('processing')),
            $this->json(200, $mismatched),
        ]);

        $result = $journey->pollToTerminal(
            $journey->submitInvoice($this->invoiceRequest($journey)),
        );

        self::assertSame('polling_error', $result->kind);
        self::assertSame('processing', $result->runtimeStatus);
        self::assertSame('processing', $result->job['status'] ?? null);
    }

    public function testAcceptedPollingUrlMustIdentifyTheJobAndTenant(): void
    {
        [$journey, $transport] = $this->journey([
            $this->json(202, $this->accepted('/v1/sync-jobs/job_invoice_001?tenant_id=another-tenant')),
        ]);
        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('an untrusted polling URL should make the accepted outcome unknown');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertSame($request, $exception->request);
            self::assertStringContainsString(
                'not scoped to the configured tenant',
                $exception->getPrevious()?->getMessage() ?? '',
            );
            self::assertCount(1, $transport->requests);
        }
    }

    public function testAcceptedPollingUrlRejectsAmbiguousTenantQueryShapes(): void
    {
        foreach (
            [
                'tenant_id=' . self::TENANT_ID . '&tenant_id=' . self::TENANT_ID,
                'tenant_id=' . self::TENANT_ID . '&tenant_id=tenant_other',
                'tenant_id=' . self::TENANT_ID . '&tenant%5Fid=' . self::TENANT_ID,
                'tenant_id%5B%5D=' . self::TENANT_ID,
            ] as $query
        ) {
            [$journey, $transport] = $this->journey([
                $this->json(
                    202,
                    $this->accepted('/v1/sync-jobs/job_invoice_001?' . $query),
                ),
            ]);
            $request = $this->invoiceRequest($journey);

            try {
                $journey->submitInvoice($request);
                self::fail("ambiguous polling query should be refused: {$query}");
            } catch (SubmissionOutcomeUnknownException $exception) {
                self::assertSame($request, $exception->request, $query);
                self::assertStringContainsString(
                    'not scoped to the configured tenant',
                    $exception->getPrevious()?->getMessage() ?? '',
                    $query,
                );
                self::assertCount(1, $transport->requests, $query);
            }
        }
    }

    public function testAcceptedPollingUrlRejectsSchemeRelativeOrigin(): void
    {
        [$journey] = $this->journey([
            $this->json(
                202,
                $this->accepted('//evil.example/v1/sync-jobs/job_invoice_001?tenant_id=' . self::TENANT_ID),
            ),
        ]);

        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('a scheme-relative polling URL should make the accepted outcome unknown');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertSame($request, $exception->request);
            self::assertStringContainsString(
                'must be a relative runtime path',
                $exception->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    public function testAcceptedPollingUrlRejectsSchemeWithoutHost(): void
    {
        [$journey] = $this->journey([
            $this->json(
                202,
                $this->accepted('custom:/v1/sync-jobs/job_invoice_001?tenant_id=' . self::TENANT_ID),
            ),
        ]);

        $request = $this->invoiceRequest($journey);

        try {
            $journey->submitInvoice($request);
            self::fail('a scheme-bearing polling URL should make the accepted outcome unknown');
        } catch (SubmissionOutcomeUnknownException $exception) {
            self::assertSame($request, $exception->request);
            self::assertStringContainsString(
                'must be a relative runtime path',
                $exception->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    public function testInvoiceBuilderProducesStableBusinessIdentityAndIdenticalPayload(): void
    {
        [$journey] = $this->journey([]);

        self::assertSame($this->invoiceRequest($journey), $this->invoiceRequest($journey));
        self::assertSame(
            'erp.invoice.erp-invoice-1001:quickbooks:create',
            $this->invoiceRequest($journey)['idempotency_key'],
        );
    }

    public function testInvoiceBuilderRequiresAnExplicitSupportedTaxTreatment(): void
    {
        [$journey] = $this->journey([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxTreatment is not supported');
        $journey->buildInvoiceRequest(
            'conn_qb_001',
            'erp-invoice-1001',
            'qb-customer-12',
            'qb-item-34',
            'qb-tax-code-56',
            '100.00',
            'guess_from_tax_code',
        );
    }
}
