<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

/** Thrown when an HTTP method is not one of the nine in Signer::HTTP_METHODS. */
final class InvalidMethodException extends \InvalidArgumentException {}
