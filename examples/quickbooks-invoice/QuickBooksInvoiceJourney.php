<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\QuickBooksInvoice;

use Ezee\IntegrationRuntime\Client;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class RuntimeIdentity
{
    public function __construct(
        public string $appId,
        public string $keyId,
        public string $tenantId,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("{$name} must not be empty");
            }
        }
    }
}

final readonly class ConnectSessionHandoff
{
    public function __construct(
        public string $sessionId,
        public string $authorizeUrl,
        public int $expiresAt,
        public ?string $connectionId,
    ) {}
}

/**
 * Product-side server state for one OAuth attempt.
 *
 * Persist the equivalent record in a real application. It is deliberately
 * mutable so the connect-session expiry is bound to the attempt and one
 * matching callback consumes it, including when that callback is expired.
 */
final class PendingConnectAttempt
{
    private bool $consumed = false;

    private bool $sessionRequested = false;

    private ?int $expiresAt = null;

    public function __construct(
        public readonly string $tenantId,
        #[\SensitiveParameter]
        public readonly string $opaqueState,
        public readonly ?string $expectedConnectionId = null,
    ) {
        if ($tenantId === '' || $opaqueState === '') {
            throw new \InvalidArgumentException('pending connect tenant and state must not be empty');
        }
    }

    public static function create(string $tenantId, ?string $expectedConnectionId = null): self
    {
        return new self($tenantId, bin2hex(random_bytes(32)), $expectedConnectionId);
    }

    public function markSessionRequested(): void
    {
        if ($this->sessionRequested || $this->consumed) {
            throw new \UnexpectedValueException('pending connect attempt already has a session request');
        }
        $this->sessionRequested = true;
    }

    public function recordSessionExpiry(int $expiresAt): void
    {
        if (! $this->sessionRequested || $this->expiresAt !== null || $expiresAt < 1) {
            throw new \UnexpectedValueException('pending connect attempt cannot accept this session expiry');
        }
        $this->expiresAt = $expiresAt;
    }

    public function consumeAt(int $now): void
    {
        if ($this->consumed) {
            throw new \UnexpectedValueException('authorization return state was already consumed');
        }
        if (! $this->sessionRequested || $this->expiresAt === null) {
            throw new \UnexpectedValueException('authorization return has no bound connect session');
        }
        $this->consumed = true;
        if ($now >= $this->expiresAt) {
            throw new \UnexpectedValueException('authorization return arrived after the connect session expired');
        }
    }
}

final readonly class ConnectionResult
{
    public function __construct(
        public string $connectionId,
        public string $status,
    ) {}
}

/**
 * The runtime currently omits product state from failed OAuth redirects.
 *
 * This result therefore records only an untrusted browser-reported hint. It
 * does not identify or mutate a connection, and the pending attempt remains
 * available for an independently correlated return or an explicit restart.
 */
final readonly class UnverifiedAuthorizationFailure
{
    public function __construct(
        public ?string $reportedReason,
        public string $message = 'Authorization failure was not correlated to the pending attempt; reconcile or restart consent.',
    ) {}

    public function requiresReconciliation(): bool
    {
        return true;
    }
}

/** @phpstan-import-type InvoiceRequest from QuickBooksInvoiceJourney */
final readonly class InvoiceSubmission
{
    /** @param InvoiceRequest $request */
    public function __construct(
        public string $jobId,
        public string $pollingUrl,
        public string $status,
        public ?string $correlationId,
        public array $request,
    ) {}
}

final readonly class PollResult
{
    /**
     * `kind` is this example's stable classification. `runtimeStatus` preserves
     * the server value so a future status is not rejected or silently coerced.
     *
     * @param 'completed'|'terminal'|'timed_out'|'polling_error'|'unrecognized_status' $kind
     * @param array<string, mixed>|null $job
     */
    public function __construct(
        public string $kind,
        public ?string $runtimeStatus,
        public ?array $job,
        public string $message,
    ) {}

    public function requiresReconciliation(): bool
    {
        return $this->kind !== 'completed';
    }
}

class RuntimeResponseException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $problemCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class IdempotencyConflictException extends RuntimeResponseException {}

/** @phpstan-import-type InvoiceRequest from QuickBooksInvoiceJourney */
final class SubmissionOutcomeUnknownException extends \RuntimeException
{
    /** @param InvoiceRequest $request */
    public function __construct(
        public readonly array $request,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

/**
 * Small integration example over the existing generic signed-request Client.
 *
 * It intentionally does not hide the operational boundaries:
 * - OAuth consent is a human browser handoff, never a server-side GET.
 * - the callback result is verified before its connection_id is trusted;
 * - Invoice submission is one logical POST with a stable body/key;
 * - transport errors and 5xx responses are ambiguous and are never retried here;
 * - polling is bounded, tenant-scoped and tolerant of future statuses.
 *
 * @phpstan-type InvoiceRequest array{
 *   tenant_id: string,
 *   provider_key: 'quickbooks',
 *   connection_id: string,
 *   recipe_key: 'erp.quickbooks.invoice.created',
 *   recipe_version: 3,
 *   trigger_type: 'erp.invoice.created',
 *   object_type: 'invoice',
 *   internal_object_id: string,
 *   idempotency_key: string,
 *   payload: array{
 *     invoice: array{
 *       CustomerRef: array{value: string},
 *       GlobalTaxCalculation: 'TaxExcluded',
 *       Line: list<array{
 *         Amount: string,
 *         DetailType: 'SalesItemLineDetail',
 *         Description: string,
 *         SalesItemLineDetail: array{
 *           ItemRef: array{value: string},
 *           TaxCodeRef: array{value: string},
 *           Qty: '1',
 *           UnitPrice: string
 *         }
 *       }>
 *     },
 *     tax_declarations: list<array{
 *       line_index: int,
 *       treatment: 'taxable'|'zero_rated'|'exempt'|'not_applicable'
 *     }>
 *   }
 * }
 */
final class QuickBooksInvoiceJourney
{
    private const TAX_TREATMENTS = ['taxable', 'zero_rated', 'exempt', 'not_applicable'];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    public function __construct(
        private readonly Client $client,
        private readonly RuntimeIdentity $identity,
        ?\Closure $sleep = null,
        private readonly int $maxPollAttempts = 5,
        private readonly float $initialPollDelaySeconds = 0.25,
        private readonly float $maxPollDelaySeconds = 2.0,
        ?\Closure $now = null,
    ) {
        if ($maxPollAttempts < 1) {
            throw new \InvalidArgumentException('maxPollAttempts must be >= 1');
        }
        if ($initialPollDelaySeconds < 0 || $maxPollDelaySeconds < 0) {
            throw new \InvalidArgumentException('poll delays must be >= 0');
        }

        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds <= 0) {
                return;
            }
            $whole = (int) floor($seconds);
            $microseconds = (int) round(($seconds - $whole) * 1_000_000);
            if ($whole > 0) {
                sleep($whole);
            }
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
        $this->now = $now ?? static fn(): int => time();
    }

    public function createConnectSession(
        string $returnUrl,
        PendingConnectAttempt $attempt,
        ?string $actorUserId = null,
    ): ConnectSessionHandoff {
        if ($attempt->tenantId !== $this->identity->tenantId) {
            throw new \InvalidArgumentException('pending connect attempt belongs to another tenant');
        }
        $attempt->markSessionRequested();
        $body = [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => 'quickbooks',
            'return_url' => $returnUrl,
            'state' => $attempt->opaqueState,
        ];
        if ($actorUserId !== null) {
            $body['actor_user_id'] = $actorUserId;
        }

        $response = $this->sendNonFinancial('POST', '/v1/connections/connect-session', $body);
        if ($response->getStatusCode() !== 201) {
            throw self::responseException($response, 'connect-session was not created');
        }

        $json = self::jsonObject($response);
        $sessionId = self::requiredString($json, 'session_id');
        $authorizeUrl = self::requiredString($json, 'authorize_url');
        $expiresAt = $json['expires_at'] ?? null;
        $connectionId = $json['connection_id'] ?? null;
        $authorizeParts = parse_url($authorizeUrl);
        if (
            $authorizeParts === false
            || ($authorizeParts['scheme'] ?? null) !== 'https'
            || ! is_string($authorizeParts['host'] ?? null)
            || $authorizeParts['host'] === ''
        ) {
            throw new \UnexpectedValueException('connect-session returned an invalid authorize_url');
        }
        if (! is_int($expiresAt)) {
            throw new \UnexpectedValueException('connect-session returned an invalid expires_at');
        }
        if ($connectionId !== null && (! is_string($connectionId) || $connectionId === '')) {
            throw new \UnexpectedValueException('connect-session returned an invalid connection_id');
        }
        $attempt->recordSessionExpiry($expiresAt);

        return new ConnectSessionHandoff($sessionId, $authorizeUrl, $expiresAt, $connectionId);
    }

    /**
     * Validate the product return URL after the human completes consent.
     *
     * The current public API has no independent GET connection-status route.
     * The runtime's browser callback projects the connection and redirects to
     * the registered return_url with this result. The product treats the query
     * as a correlated handoff hint, not as an independent authoritative read:
     * it binds the result to one server-side pending attempt, consumes that
     * attempt once, and keeps app/tenant scope from its own trusted context.
     */
    public function acceptAuthorizationReturn(
        string $returnUrl,
        PendingConnectAttempt $attempt,
    ): ConnectionResult|UnverifiedAuthorizationFailure {
        if ($attempt->tenantId !== $this->identity->tenantId) {
            throw new \UnexpectedValueException('pending connect attempt belongs to another tenant');
        }
        $query = parse_url($returnUrl, PHP_URL_QUERY);
        if (! is_string($query)) {
            throw new \UnexpectedValueException('authorization return URL has no query string');
        }
        $statuses = self::queryValues($query, 'status');
        if (count($statuses) !== 1) {
            throw new \UnexpectedValueException('authorization return has no unambiguous status');
        }
        $status = $statuses[0];
        if ($status === 'failed') {
            $reasons = self::queryValues($query, 'reason');

            return new UnverifiedAuthorizationFailure(
                count($reasons) === 1 && $reasons[0] !== '' ? $reasons[0] : null,
            );
        }
        if (! in_array($status, ['connected', 'already_connected'], true)) {
            throw new \UnexpectedValueException('authorization did not produce a connected result');
        }

        $states = self::queryValues($query, 'state');
        if (count($states) !== 1 || ! hash_equals($attempt->opaqueState, $states[0])) {
            throw new \UnexpectedValueException('authorization return state does not match');
        }
        // Consume immediately after success correlation. A malformed matching
        // success callback must not be replayable.
        $attempt->consumeAt(($this->now)());

        $connectionIds = self::queryValues($query, 'connection_id');
        if (count($connectionIds) !== 1 || $connectionIds[0] === '') {
            throw new \UnexpectedValueException('authorization return has no connection_id');
        }
        $connectionId = $connectionIds[0];
        if (
            $attempt->expectedConnectionId !== null
            && ! hash_equals($attempt->expectedConnectionId, $connectionId)
        ) {
            throw new \UnexpectedValueException(
                'authorization return connection_id does not match the pending reconnect attempt',
            );
        }

        return new ConnectionResult($connectionId, $status);
    }

    /**
     * Build the frozen marker-free QuickBooks Invoice CREATE input.
     *
     * The three provider refs must already exist in the connected QuickBooks
     * company. `taxTreatment` is an explicit declaration about this line, not a
     * value guessed from the tax code; the supplied TaxCodeRef is preserved for
     * the runtime's live tax preflight and stored-document verification.
     *
     * @return InvoiceRequest
     */
    public function buildInvoiceRequest(
        string $connectionId,
        string $internalInvoiceId,
        string $customerRef,
        string $itemRef,
        string $taxCodeRef,
        string $amount,
        string $taxTreatment,
        string $description = 'ERP invoice line',
    ): array {
        foreach (
            compact(
                'connectionId',
                'internalInvoiceId',
                'customerRef',
                'itemRef',
                'taxCodeRef',
                'amount',
                'taxTreatment',
            ) as $name => $value
        ) {
            if ($value === '') {
                throw new \InvalidArgumentException("{$name} must not be empty");
            }
        }
        if (! in_array($taxTreatment, self::TAX_TREATMENTS, true)) {
            throw new \InvalidArgumentException('taxTreatment is not supported by the Invoice v3 contract');
        }

        return [
            'tenant_id' => $this->identity->tenantId,
            'provider_key' => 'quickbooks',
            'connection_id' => $connectionId,
            'recipe_key' => 'erp.quickbooks.invoice.created',
            'recipe_version' => 3,
            'trigger_type' => 'erp.invoice.created',
            'object_type' => 'invoice',
            'internal_object_id' => $internalInvoiceId,
            // Stable business-event identity. Never replace it after an
            // ambiguous response or a 409.
            'idempotency_key' => "erp.invoice.{$internalInvoiceId}:quickbooks:create",
            'payload' => [
                'invoice' => [
                    'CustomerRef' => ['value' => $customerRef],
                    'GlobalTaxCalculation' => 'TaxExcluded',
                    'Line' => [[
                        'Amount' => $amount,
                        'DetailType' => 'SalesItemLineDetail',
                        'Description' => $description,
                        'SalesItemLineDetail' => [
                            'ItemRef' => ['value' => $itemRef],
                            'TaxCodeRef' => ['value' => $taxCodeRef],
                            'Qty' => '1',
                            'UnitPrice' => $amount,
                        ],
                    ]],
                ],
                'tax_declarations' => [[
                    'line_index' => 0,
                    'treatment' => $taxTreatment,
                ]],
            ],
        ];
    }

    /**
     * @param InvoiceRequest $request
     */
    public function submitInvoice(array $request): InvoiceSubmission
    {
        try {
            $response = $this->client->send('POST', '/v1/operations/execute', $request);
        } catch (ClientExceptionInterface $exception) {
            throw new SubmissionOutcomeUnknownException(
                $request,
                'Invoice submission transport failed; the write outcome is unknown. Preserve the '
                . 'same key and payload, then reconcile the original request before any replay.',
                $exception,
            );
        }

        if ($response->getStatusCode() >= 500) {
            throw new SubmissionOutcomeUnknownException(
                $request,
                "Invoice submission returned HTTP {$response->getStatusCode()}; the write may have landed. "
                . 'Do not retry automatically or mint a new idempotency key.',
            );
        }
        if ($response->getStatusCode() === 409) {
            try {
                $problem = self::jsonObject($response);
            } catch (\UnexpectedValueException) {
                throw new RuntimeResponseException(
                    409,
                    null,
                    'Invoice submission returned HTTP 409 with an unusable problem body. Preserve the '
                    . 'original request and idempotency key, reconcile before any resubmission, and never '
                    . 'mint a replacement key.',
                );
            }
            $code = is_string($problem['code'] ?? null) ? $problem['code'] : null;
            $detail = is_string($problem['detail'] ?? null)
                ? $problem['detail']
                : 'Invoice submission was refused with a conflict.';
            if ($code === 'idempotency_key_conflict') {
                throw new IdempotencyConflictException(
                    409,
                    $code,
                    'The idempotency key is already bound to different input; keep the original key and reconcile.',
                );
            }

            throw new RuntimeResponseException(409, $code, $detail);
        }
        if ($response->getStatusCode() !== 202) {
            throw self::responseException($response, 'Invoice submission was not accepted');
        }

        try {
            $json = self::jsonObject($response);
            $jobId = self::requiredString($json, 'job_id');
            $pollingUrl = self::requiredString($json, 'polling_url');
            $status = self::requiredString($json, 'status');
            if (
                ! array_key_exists('correlation_id', $json)
                || (! is_string($json['correlation_id']) && $json['correlation_id'] !== null)
            ) {
                throw new \UnexpectedValueException(
                    'runtime response has no usable correlation_id',
                );
            }
            $correlationId = $json['correlation_id'];
            $this->assertTenantScopedPollingUrl($pollingUrl, $jobId);
        } catch (\UnexpectedValueException $exception) {
            throw new SubmissionOutcomeUnknownException(
                $request,
                'Invoice submission returned HTTP 202 with an unusable acknowledgement. The write may '
                . 'have landed; preserve this exact request identity and reconcile before any replay.',
                $exception,
            );
        }

        return new InvoiceSubmission($jobId, $pollingUrl, $status, $correlationId, $request);
    }

    public function pollToTerminal(InvoiceSubmission $submission): PollResult
    {
        $lastJob = null;
        $lastStatus = null;
        for ($attempt = 1; $attempt <= $this->maxPollAttempts; $attempt++) {
            try {
                $response = $this->client->send('GET', $submission->pollingUrl);
            } catch (ClientExceptionInterface) {
                return new PollResult(
                    'polling_error',
                    $lastStatus,
                    $lastJob,
                    'Job polling transport failed. Retry only this GET; never submit a replacement Invoice.',
                );
            }

            if ($response->getStatusCode() !== 200) {
                return new PollResult(
                    'polling_error',
                    $lastStatus,
                    $lastJob,
                    "Job polling returned HTTP {$response->getStatusCode()}. Retry only the polling URL.",
                );
            }

            try {
                $job = self::jsonObject($response);
            } catch (\UnexpectedValueException) {
                return new PollResult(
                    'polling_error',
                    $lastStatus,
                    $lastJob,
                    'Job polling returned a malformed body. Preserve the accepted job and retry only its polling URL.',
                );
            }
            if (! $this->isExpectedJob($job, $submission)) {
                return new PollResult(
                    'polling_error',
                    $lastStatus,
                    $lastJob,
                    'Job polling returned a mismatched job/app/tenant identity. Preserve the accepted job and reconcile.',
                );
            }
            $status = $job['status'] ?? null;
            if (! is_string($status) || $status === '') {
                return new PollResult(
                    'polling_error',
                    $lastStatus,
                    $lastJob ?? $job,
                    'Job response had no usable status; preserve the job identity and reconcile.',
                );
            }
            if ($status === 'completed') {
                return new PollResult('completed', $status, $job, 'Invoice job completed.');
            }
            if (in_array($status, ['failed', 'dead_lettered', 'cancelled', 'manually_resolved'], true)) {
                return new PollResult(
                    'terminal',
                    $status,
                    $job,
                    'Job reached a terminal non-success state; inspect failure evidence before operator action.',
                );
            }
            if (! in_array($status, ['queued', 'processing'], true)) {
                return new PollResult(
                    'unrecognized_status',
                    $status,
                    $job,
                    'The runtime returned a future status this example does not classify; preserve it and reconcile.',
                );
            }

            $lastJob = $job;
            $lastStatus = $status;

            if ($attempt < $this->maxPollAttempts) {
                $delay = min(
                    $this->initialPollDelaySeconds * (2 ** ($attempt - 1)),
                    $this->maxPollDelaySeconds,
                );
                ($this->sleep)($delay);
            }
        }

        return new PollResult(
            'timed_out',
            $lastStatus,
            $lastJob,
            'Polling reached its attempt bound. Continue with the same polling URL; do not resubmit the Invoice.',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendNonFinancial(string $method, string $path, array $body): ResponseInterface
    {
        try {
            return $this->client->send($method, $path, $body);
        } catch (ClientExceptionInterface $exception) {
            throw new \RuntimeException(
                'Request transport failed. Do not create a replacement connect-session implicitly.',
                previous: $exception,
            );
        }
    }

    private function assertTenantScopedPollingUrl(string $pollingUrl, string $jobId): void
    {
        $parts = parse_url($pollingUrl);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['fragment'])
        ) {
            throw new \UnexpectedValueException('polling_url must be a relative runtime path');
        }
        $expectedPath = '/v1/sync-jobs/' . rawurlencode($jobId);
        if (($parts['path'] ?? null) !== $expectedPath || ! isset($parts['query'])) {
            throw new \UnexpectedValueException('polling_url does not identify the accepted job');
        }

        $tenantIds = self::queryValues($parts['query'], 'tenant_id');
        if (count($tenantIds) !== 1 || ! hash_equals($this->identity->tenantId, $tenantIds[0])) {
            throw new \UnexpectedValueException('polling_url is not scoped to the configured tenant');
        }
    }

    /** @return list<string> */
    private static function queryValues(string $query, string $name): array
    {
        $values = [];
        foreach (explode('&', $query) as $field) {
            [$rawName, $rawValue] = array_pad(explode('=', $field, 2), 2, '');
            if (urldecode($rawName) === $name) {
                $values[] = urldecode($rawValue);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $job */
    private function isExpectedJob(array $job, InvoiceSubmission $submission): bool
    {
        return is_string($job['id'] ?? null)
            && hash_equals($submission->jobId, $job['id'])
            && is_string($job['app_id'] ?? null)
            && hash_equals($this->identity->appId, $job['app_id'])
            && is_string($job['tenant_id'] ?? null)
            && hash_equals($this->identity->tenantId, $job['tenant_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonObject(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('runtime returned invalid JSON', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('runtime returned JSON that is not an object');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('runtime returned a JSON object with a non-string key');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function requiredString(array $json, string $key): string
    {
        $value = $json[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("runtime response has no usable {$key}");
        }

        return $value;
    }

    private static function responseException(
        ResponseInterface $response,
        string $fallbackMessage,
    ): RuntimeResponseException {
        $code = null;
        $detail = null;
        try {
            $problem = self::jsonObject($response);
            $code = is_string($problem['code'] ?? null) ? $problem['code'] : null;
            $detail = is_string($problem['detail'] ?? null) ? $problem['detail'] : null;
        } catch (\UnexpectedValueException) {
            // Preserve the HTTP status even when a proxy did not return problem+json.
        }

        return new RuntimeResponseException(
            $response->getStatusCode(),
            $code,
            $detail ?? $fallbackMessage,
        );
    }
}
