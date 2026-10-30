<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

/**
 * A non-2xx runtime response, carrying the stable problem `code`.
 *
 * Branch on {@see $problemCode} or {@see decision()}, never on the message:
 * `title` and `detail` are prose and change without a version bump.
 */
final class RuntimeProblemException extends \RuntimeException
{
    /** Fix the request body/identifiers, then resend with the SAME idempotency key. */
    public const FIX_REQUEST = 'fix_request';

    /** Wait (Retry-After) and resend the SAME request with a fresh signature. */
    public const RETRY_SAME_REQUEST = 'retry_same_request';

    /** A release gate or provisioning refused it. Do not loop; ask the operator. */
    public const ASK_OPERATOR = 'ask_operator';

    /** Identity or outcome is uncertain. Stop; poll the original job or reconcile. Never re-key. */
    public const STOP_AND_RECONCILE = 'stop_and_reconcile';

    /** Signing or credentials are wrong. Check app/key/secret and clock. */
    public const FIX_CREDENTIALS = 'fix_credentials';

    /**
     * Accounting: the connection's setup is missing something (a mapping, a
     * record-type setting, a link, the connection itself). Nothing was sent.
     * Fix the setup, then resend the SAME saved request unchanged.
     */
    public const FIX_SETUP = 'fix_setup';

    /**
     * Accounting: the document cannot be written as sent (it changed in the
     * provider, the amount or tax is wrong, the number exists, the period is
     * closed). Nothing was sent. Refresh it and have a person review it; a
     * corrected document is a new business event with a NEW idempotency key.
     */
    public const REVIEW_AND_RESUBMIT = 'review_and_resubmit';

    /**
     * Accounting: this document or action is outside what the connection
     * syncs (dated before the sync start date, or unsupported by the
     * provider). Keep it in the ERP only; do not retry.
     */
    public const DO_NOT_SYNC = 'do_not_sync';

    /**
     * Every documented neutral accounting code (accounting-operations.md
     * "Errors"). An `accounting.*` code not listed here is unknown and
     * decides stop_and_reconcile; `accounting.provider_refused` carries a
     * native refusal and follows its HTTP status.
     *
     * @var array<string, string>
     */
    private const ACCOUNTING_DECISIONS = [
        'accounting.mapping_missing' => self::FIX_SETUP,
        'accounting.record_type_disabled' => self::FIX_SETUP,
        'accounting.document_unlinked' => self::FIX_SETUP,
        'accounting.no_accounting_connection' => self::FIX_SETUP,
        'accounting.multiple_accounting_connections' => self::FIX_SETUP,
        'accounting.connection_exists' => self::FIX_SETUP,
        'accounting.revision_conflict' => self::REVIEW_AND_RESUBMIT,
        'accounting.amount_exceeded' => self::REVIEW_AND_RESUBMIT,
        'accounting.tax_mismatch' => self::REVIEW_AND_RESUBMIT,
        'accounting.document_exists' => self::REVIEW_AND_RESUBMIT,
        'accounting.period_closed' => self::REVIEW_AND_RESUBMIT,
        'accounting.before_sync_start' => self::DO_NOT_SYNC,
        'accounting.capability_unsupported' => self::DO_NOT_SYNC,
        'accounting.provider_unavailable' => self::RETRY_SAME_REQUEST,
        'accounting.rate_limited' => self::RETRY_SAME_REQUEST,
        'accounting.operation_in_progress' => self::RETRY_SAME_REQUEST,
        'accounting.operation_disabled' => self::ASK_OPERATOR,
        'accounting.mapping_conflict' => self::FIX_REQUEST,
        'accounting.reference_invalid' => self::FIX_REQUEST,
        'accounting.unsupported_change' => self::FIX_REQUEST,
        'accounting.settings_conflict' => self::FIX_REQUEST,
        'accounting.reference_not_found' => self::FIX_REQUEST,
        'accounting.mapping_not_found' => self::FIX_REQUEST,
        'accounting.connection_not_found' => self::FIX_REQUEST,
        'accounting.operation_not_found' => self::FIX_REQUEST,
        'accounting.invalid_input' => self::FIX_REQUEST,
        'accounting.idempotency_conflict' => self::STOP_AND_RECONCILE,
        'accounting.link_conflict' => self::STOP_AND_RECONCILE,
        'accounting.write_uncertain' => self::STOP_AND_RECONCILE,
    ];

    /**
     * @param array<string, mixed>|null $problem the decoded problem+json body, when there was one
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $problemCode,
        public readonly ?string $requestId = null,
        public readonly ?array $problem = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(sprintf('Integration Runtime returned HTTP %d (%s)', $httpStatus, $problemCode), $httpStatus);
    }

    /**
     * The documented ERP action for this response
     * (docs-site/content/index.md "HTTP decision table"; neutral
     * `accounting.*` codes per accounting-operations.md "Errors").
     * New values may be added; treat an unknown one as stop_and_reconcile.
     */
    public function decision(): string
    {
        $code = $this->problemCode;
        if (isset(self::ACCOUNTING_DECISIONS[$code])) {
            return self::ACCOUNTING_DECISIONS[$code];
        }
        if (str_starts_with($code, 'accounting.') && $code !== 'accounting.provider_refused') {
            // A financial-write refusal this SDK does not know: never mutate and resend.
            return self::STOP_AND_RECONCILE;
        }
        if ($this->httpStatus === 429 || $code === 'tenant_enablement_unavailable') {
            return self::RETRY_SAME_REQUEST;
        }
        if ($this->httpStatus === 401) {
            return $code === 'hmac.nonce_reused' ? self::RETRY_SAME_REQUEST : self::FIX_CREDENTIALS;
        }
        if ($this->httpStatus === 403) {
            return self::ASK_OPERATOR;
        }
        if ($this->httpStatus === 409 && $code === 'recipe_version_retired') {
            return self::FIX_REQUEST;
        }
        if ($this->httpStatus === 409 || $this->httpStatus >= 500) {
            return self::STOP_AND_RECONCILE;
        }

        return self::FIX_REQUEST;
    }
}
