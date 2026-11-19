<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;

/**
 * What an ERP outbox row becomes after a send or a job result
 * (accounting-operations.md "Reliable delivery: the outbox pattern").
 *
 * The ERP saves a pending row with its idempotency key in the same database
 * transaction as the document, and a scheduler resends rows that have no
 * job yet. This maps an exception from the send, or a failed job's
 * `error.code`, onto the row's next state:
 *
 * - `pending`: resend later with the SAME idempotency key (a 429, a 5xx, a
 *   network error, or a setup step still to happen, such as the parent
 *   invoice not linked yet). A same-key resend never writes twice. Count
 *   attempts and escalate a row that stays pending. Only a send that threw
 *   is ever pending: a failed job is never re-run by a same-key resend.
 * - `failed`: send it again with a NEW idempotency key and the same erp_id,
 *   after the user fixes the document (or the setup, or, for a create
 *   refused by the sync start on its document date, after adding
 *   erp_created_at). The runtime never writes a second provider document for
 *   one erp_id.
 * - `skipped`: keep it in the ERP only; do not resend.
 * - `needs_person`: stop. Never resend under a new key.
 */
final class OutboxDecision
{
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const NEEDS_PERSON = 'needs_person';

    /** The row's next state after the send threw. */
    public static function for(\Throwable $error): string
    {
        if ($error instanceof RuntimeProblemException) {
            // A 5xx on submit is never a refusal of the request: an exact
            // resend with the same key returns the job if it was admitted.
            if ($error->httpStatus >= 500) {
                return self::PENDING;
            }
            // A create judged by its document date may have been created after
            // the sync start: resend it with erp_created_at.
            if ($error->problemCode === 'accounting.before_sync_start'
                && ($error->problem['compared'] ?? null) === 'document_date') {
                return self::FAILED;
            }

            return self::fromDecision($error->decision());
        }
        if ($error instanceof \InvalidArgumentException) {
            // The SDK refused to build the request: it needs a fix.
            return self::FAILED;
        }

        // Network error, timeout or lost response: resend the same request.
        return self::PENDING;
    }

    /**
     * The row's next state for a failed job's `error.code`. A same-key resend
     * returns the same failed job, so nothing here is `pending`: what a resend
     * would fix becomes `failed` (new key, same erp_id).
     */
    public static function forJobError(string $errorCode): string
    {
        $state = self::fromDecision((new RuntimeProblemException(409, $errorCode))->decision());

        return $state === self::PENDING ? self::FAILED : $state;
    }

    /** The row's next state for a {@see RuntimeProblemException::decision()} value. */
    public static function fromDecision(string $decision): string
    {
        return match ($decision) {
            RuntimeProblemException::RETRY_SAME_REQUEST, RuntimeProblemException::FIX_SETUP => self::PENDING,
            RuntimeProblemException::FIX_REQUEST, RuntimeProblemException::REVIEW_AND_RESUBMIT => self::FAILED,
            RuntimeProblemException::DO_NOT_SYNC => self::SKIPPED,
            // stop_and_reconcile, ask_operator, fix_credentials, and any value
            // added later: a person decides.
            default => self::NEEDS_PERSON,
        };
    }
}
