<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * The `202` acknowledgement of POST /v1/operations/execute (and of
 * POST /v1/accounting/operations, whose `polling_url` {@see Jobs} also reads).
 *
 * Persist {@see $jobId} and {@see $pollingUrl} next to the ERP document and its
 * idempotency key before doing anything else: they are how a lost process
 * recovers the job without submitting the operation again.
 */
final readonly class OperationReceipt
{
    public function __construct(
        public string $jobId,
        public string $pollingUrl,
        public string $status,
        public ?string $correlationId,
        /**
         * `idempotent_replay` from the acknowledgement: true when the runtime
         * matched an earlier submission with the same idempotency key and
         * returned that job instead of admitting a new one. Null when the
         * response did not carry the field (or carried a non-boolean).
         */
        public ?bool $idempotentReplay = null,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $jobId = $body['job_id'] ?? null;
        $pollingUrl = $body['polling_url'] ?? null;
        $status = $body['status'] ?? null;
        $correlationId = $body['correlation_id'] ?? null;
        $idempotentReplay = $body['idempotent_replay'] ?? null;
        if (! is_string($jobId) || $jobId === '' || ! is_string($pollingUrl) || ! str_starts_with($pollingUrl, '/') || ! is_string($status)) {
            throw new \UnexpectedValueException('Operation acknowledgement is missing job_id, polling_url or status.');
        }

        return new self(
            $jobId,
            $pollingUrl,
            $status,
            is_string($correlationId) ? $correlationId : null,
            is_bool($idempotentReplay) ? $idempotentReplay : null,
        );
    }
}
