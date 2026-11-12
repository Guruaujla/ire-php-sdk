<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Internal\ResponseReader;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Read sync jobs. Polling never resubmits work: a slow job is waited on, a
 * terminal failure is returned for the caller to reconcile.
 */
final class Jobs
{
    private const SYNC_JOBS_PREFIX = '/v1/sync-jobs/';

    private const ACCOUNTING_OPERATIONS_PREFIX = '/v1/accounting/operations/';

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

    /**
     * Read a job by the exact `polling_url` the runtime returned.
     *
     * Accepts both shapes the runtime hands out: `/v1/sync-jobs/{id}?tenant_id=…`
     * (`quickbooks()` and `/v1/operations/execute`) and
     * `/v1/accounting/operations/{id}?tenant_id=…` (`accounting()->submit()`).
     * An accounting operation is read through its sync job (same job ID), so
     * this always returns a {@see SyncJob}. Once it is terminal, call
     * {@see Accounting::status()} for the neutral result: that read also links
     * the created document for later updates, which this sync-job read does not.
     */
    public function get(string $pollingUrl): SyncJob
    {
        return self::read($this->client, $pollingUrl);
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
     * read either way; check {@see SyncJob::isTerminal()}. A 429, a transient
     * 5xx or a network failure (PSR-18 `NetworkExceptionInterface`) on a READ
     * is waited out, never turned into a resubmission. Takes the same polling
     * URLs as {@see get()}.
     *
     * Polls with the client's 429 retries disabled, so a `Retry-After` is
     * waited out here against the deadline instead of inside one request.
     */
    public function wait(string $pollingUrl, int $timeoutSeconds = 120, float $intervalSeconds = 2.0): SyncJob
    {
        $syncJobUrl = self::syncJobUrl($pollingUrl);
        $poller = $this->client->withoutRetry();
        $deadline = microtime(true) + max(0, $timeoutSeconds);
        $interval = max(0.5, $intervalSeconds);
        $last = null;
        while (true) {
            $transient = null;
            try {
                $last = self::read($poller, $syncJobUrl);
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
            } catch (NetworkExceptionInterface $transport) {
                // The read never got an answer; nothing was submitted. Keep
                // polling until the deadline like a transient 5xx. Other PSR-18
                // errors (a malformed request) are deterministic and propagate.
                $transient = $transport;
                $delay = $interval;
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

    private static function read(Client $client, string $pollingUrl): SyncJob
    {
        return SyncJob::fromArray(ResponseReader::json($client->send('GET', self::syncJobUrl($pollingUrl))));
    }

    /**
     * The sync-job read for a runtime polling URL. A sync-jobs URL is used as
     * is; an accounting operation URL maps to the sync job with the same ID in
     * the same tenant. Anything else is refused before a request is sent.
     */
    private static function syncJobUrl(string $pollingUrl): string
    {
        if (str_starts_with($pollingUrl, self::SYNC_JOBS_PREFIX)) {
            return $pollingUrl;
        }
        if (str_starts_with($pollingUrl, self::ACCOUNTING_OPERATIONS_PREFIX)) {
            $parts = explode('?', $pollingUrl, 2);
            $jobId = rawurldecode(substr($parts[0], strlen(self::ACCOUNTING_OPERATIONS_PREFIX)));
            parse_str($parts[1] ?? '', $query);
            $tenantId = $query['tenant_id'] ?? null;
            if ($jobId !== '' && $jobId !== '.' && $jobId !== '..' && ! str_contains($jobId, '/')
                && is_string($tenantId) && $tenantId !== '') {
                return ResponseReader::query(self::SYNC_JOBS_PREFIX . rawurlencode($jobId), ['tenant_id' => $tenantId]);
            }
        }

        throw new \InvalidArgumentException('pollingUrl must be the polling_url returned by the runtime');
    }
}
