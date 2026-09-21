<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\JobResult;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Credential-free, in-process polling fixture; it performs no network call. */
final class MockRuntimeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private int $polls = 0;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $jobId = 'job_result_mock_001',
        private readonly string $completedResultStatus = 'available',
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        parse_str($request->getUri()->getQuery(), $query);
        if (
            $request->getMethod() !== 'GET'
            || $request->getUri()->getPath() !== "/v1/sync-jobs/{$this->jobId}"
            || ($query['tenant_id'] ?? null) !== $this->tenantId
        ) {
            return new Response(404, ['Content-Type' => 'application/problem+json'], '{}');
        }

        $this->polls++;
        $completed = $this->polls > 1;

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $this->jobId,
            'app_id' => 'app_erp_mock',
            'tenant_id' => $this->tenantId,
            'status' => $completed ? 'completed' : 'processing',
            'last_error_code' => null,
            'result_status' => $completed ? $this->completedResultStatus : 'none',
            'result' => $completed ? [
                'page' => 1,
                'items' => [['id' => 'synthetic-1', 'label' => 'mock result']],
                'next_cursor' => null,
            ] : null,
            'steps' => [],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }
}
