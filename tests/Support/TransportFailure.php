<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests\Support;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** A PSR-18 network failure (connection refused, timeout) for scripted transports. */
final class TransportFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://runtime.test/');
    }
}
