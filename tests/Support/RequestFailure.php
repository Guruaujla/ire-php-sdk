<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests\Support;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** A deterministic PSR-18 request failure (malformed request) for scripted transports. */
final class RequestFailure extends \RuntimeException implements RequestExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://runtime.test/');
    }
}
