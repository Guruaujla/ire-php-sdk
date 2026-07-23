<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

/** Thrown when a caller-supplied nonce is outside the runtime's [22, 64] string-length bound. */
final class InvalidNonceException extends \InvalidArgumentException {}
