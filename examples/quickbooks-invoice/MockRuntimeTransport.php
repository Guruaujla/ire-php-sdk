<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\QuickBooksInvoice;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Secret-free, in-process transport for the runnable example.
 *
 * This is deliberately not a runtime emulator. It exercises the real PHP SDK's
 * request construction/signing while returning the small contract slice needed
 * for the example, without OAuth consent, credentials, network access or writes.
 */
final class MockRuntimeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private ?string $acceptedInvoiceBody = null;

    private ?string $acceptedIdempotencyKey = null;

    private int $pollCount = 0;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $appId = 'app_erp_mock',
        private readonly string $connectionId = 'conn_mock_quickbooks_001',
        private readonly string $jobId = 'job_mock_invoice_001',
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'POST' && $path === '/v1/connections/connect-session') {
            return $this->json(201, [
                'session_id' => '01911111-1111-7111-8111-111111111111',
                'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2?mock=true',
                'expires_at' => time() + 900,
                'connection_id' => null,
            ]);
        }

        if ($request->getMethod() === 'POST' && $path === '/v1/operations/execute') {
            return $this->executeInvoice($request);
        }

        if ($request->getMethod() === 'GET' && $path === "/v1/sync-jobs/{$this->jobId}") {
            parse_str($request->getUri()->getQuery(), $query);
            if (($query['tenant_id'] ?? null) !== $this->tenantId) {
                return $this->problem(403, 'tenant.forbidden', 'Wrong tenant scope.');
            }

            $this->pollCount++;

            return $this->json(200, $this->job($this->pollCount === 1 ? 'processing' : 'completed'));
        }

        return $this->problem(404, 'request.route_not_found', 'No mock route matched.');
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    private function executeInvoice(RequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $this->problem(400, 'request.validation_failed', 'Body must be a JSON object.');
        }
        $key = $decoded['idempotency_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return $this->problem(400, 'idempotency_key_required', 'Missing idempotency key.');
        }

        if ($this->acceptedInvoiceBody === null) {
            $this->acceptedInvoiceBody = $body;
            $this->acceptedIdempotencyKey = $key;

            return $this->accepted();
        }

        if ($key === $this->acceptedIdempotencyKey && $body === $this->acceptedInvoiceBody) {
            return $this->accepted();
        }

        if ($key === $this->acceptedIdempotencyKey) {
            return $this->problem(
                409,
                'idempotency_key_conflict',
                'The key is already bound to different input.',
            );
        }

        return $this->problem(409, 'idempotency_key_conflict', 'The mock accepts one business event.');
    }

    private function accepted(): ResponseInterface
    {
        return $this->json(202, [
            'job_id' => $this->jobId,
            'correlation_id' => 'cor_mock_invoice_001',
            'status' => 'queued',
            'polling_url' => "/v1/sync-jobs/{$this->jobId}?tenant_id=" . rawurlencode($this->tenantId),
        ]);
    }

    /** @return array<string, mixed> */
    private function job(string $status): array
    {
        return [
            'id' => $this->jobId,
            'app_id' => $this->appId,
            'tenant_id' => $this->tenantId,
            'provider_key' => 'quickbooks',
            'connection_id' => $this->connectionId,
            'operation_key' => 'quickbooks.invoice.create',
            'recipe_key' => 'erp.quickbooks.invoice.created',
            'recipe_version' => 3,
            'object_type' => 'invoice',
            'internal_object_id' => 'erp-invoice-1001',
            'external_object_id' => $status === 'completed' ? 'qb-invoice-mock-1001' : null,
            'status' => $status,
            'attempt_count' => $status === 'completed' ? 1 : 0,
            'last_error_code' => null,
            'created_at' => 1_700_000_000,
            'completed_at' => $status === 'completed' ? 1_700_000_001 : null,
            'steps' => [],
        ];
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
                'request_id' => 'req_mock_001',
                'correlation_id' => null,
                'instance' => null,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
