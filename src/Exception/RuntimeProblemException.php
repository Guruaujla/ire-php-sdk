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
     * (docs-site/content/index.md "HTTP decision table").
     */
    public function decision(): string
    {
        $code = $this->problemCode;
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
