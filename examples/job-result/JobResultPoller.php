<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\JobResult;

use Ezee\IntegrationRuntime\Client;

/**
 * Minimal consumer for a recipe-declared result on GET /v1/sync-jobs/{id}.
 *
 * @phpstan-type PublicResult array<string, mixed>
 */
final class JobResultPoller
{
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $tenantId,
        ?\Closure $sleep = null,
        private readonly int $maxAttempts = 10,
    ) {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenantId must not be empty');
        }
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least one');
        }
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) min($seconds * 1_000_000, 999_999));
            }
        };
    }

    /** @return PublicResult */
    public function poll(string $jobId): array
    {
        if ($jobId === '') {
            throw new \InvalidArgumentException('jobId must not be empty');
        }
        $path = '/v1/sync-jobs/' . rawurlencode($jobId)
            . '?tenant_id=' . rawurlencode($this->tenantId);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $response = $this->client->send('GET', $path);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(
                    "Job polling returned HTTP {$response->getStatusCode()}; retry only the scoped polling request.",
                );
            }
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new \UnexpectedValueException('Job polling response must be a JSON object.');
            }
            $status = $body['status'] ?? null;
            if ($status === 'completed') {
                $resultStatus = $body['result_status'] ?? null;
                if (! is_string($resultStatus) || $resultStatus === 'none') {
                    throw new \UnexpectedValueException(
                        'Completed job did not expose the declared public result.',
                    );
                }
                // result_status is an extensible enum. Treat any non-none
                // member as potentially usable and validate the result shape,
                // rather than rejecting a future additive status value.
                if (! is_array($body['result'] ?? null)) {
                    throw new \UnexpectedValueException(
                        "Completed job result status {$resultStatus} did not include an object.",
                    );
                }

                /** @var PublicResult $result */
                $result = $body['result'];

                return $result;
            }
            if (in_array($status, ['failed', 'dead_lettered', 'cancelled', 'manually_resolved'], true)) {
                $code = is_string($body['last_error_code'] ?? null)
                    ? $body['last_error_code']
                    : 'unknown';
                throw new \RuntimeException("Job ended as {$status} ({$code}).");
            }
            if ($attempt < $this->maxAttempts) {
                ($this->sleep)(0.05);
            }
        }

        throw new \RuntimeException('Job did not complete within the bounded polling attempts.');
    }
}
