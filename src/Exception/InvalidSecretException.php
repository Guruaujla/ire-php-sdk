<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

/** Thrown when a service-key secret is not a valid base64url string. */
final class InvalidSecretException extends \InvalidArgumentException {}
