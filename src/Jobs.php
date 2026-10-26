<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * Read sync jobs. Polling never resubmits work: a slow job is waited on, a
 * terminal failure is returned for the caller to reconcile.
 */
final class Jobs
{
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @param (\Closure(float): void)|null $sleep testing seam */
    public function __construct(private readonly Client $client, ?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds >= 1) {
                sleep((int) $seconds);
            } elseif ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /** Read a job by the exact `polling_url` the runtime returned. */
    public function get(string $pollingUrl): SyncJob
    {
        if (! str_starts_with($pollingUrl, '/v1/sync-jobs/')) {
            throw new \InvalidArgumentException('pollingUrl must be the polling_url returned by the runtime');
        }

        return SyncJob::fromArray(ResponseReader::json($this->client->send('GET', $pollingUrl)));
    }

    /** Read a job by ID within one runtime tenant. */
    public function find(string $tenantId, string $jobId): SyncJob
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('jobId', $jobId);

        return $this->get(ResponseReader::query('/v1/sync-jobs/' . rawurlencode($jobId), ['tenant_id' => $tenantId]));
    }

    /**
     * @param array{status?: string, connection_id?: string, cursor?: string, limit?: int} $filters
     * @return array<string, mixed> the page as returned (`data` plus cursor fields)
     */
    public function list(string $tenantId, array $filters = []): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);

        return ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/sync-jobs', ['tenant_id' => $tenantId, ...$filters])));
    }

    /**
     * Poll until the job is terminal or the deadline passes. Returns the last
     * read either way; check {@see SyncJob::isTerminal()}. A 429 or transient
     * 5xx on a READ is waited out, never turned into a resubmission.
     */
    public function wait(string $pollingUrl, int $timeoutSeconds = 120, float $intervalSeconds = 2.0): SyncJob
    {
        $deadline = microtime(true) + max(0, $timeoutSeconds);
        $interval = max(0.5, $intervalSeconds);
        $last = null;
        while (true) {
            $transient = null;
            try {
                $last = $this->get($pollingUrl);
                if ($last->isTerminal()) {
                    return $last;
                }
                $delay = $interval;
            } catch (RuntimeProblemException $problem) {
                if ($problem->httpStatus !== 429 && $problem->httpStatus < 500) {
                    throw $problem;
                }
                $transient = $problem;
                $delay = (float) max($interval, $problem->retryAfterSeconds ?? 0);
            }
            if (microtime(true) + $delay > $deadline) {
                // No successful read to return: surface the transient error
                // rather than issue an extra request past the deadline.
                if ($last === null) {
                    throw $transient ?? new \LogicException('unreachable: no read and no error');
                }

                return $last;
            }
            ($this->sleep)($delay);
            $interval = min($interval * 1.5, 30.0);
        }
    }
}
