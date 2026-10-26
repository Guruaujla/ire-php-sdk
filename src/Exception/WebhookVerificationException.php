<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

/** The delivery failed verification. Respond 401 and do not read the body. */
final class WebhookVerificationException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Webhook delivery rejected (' . $reason . ')');
    }
}
