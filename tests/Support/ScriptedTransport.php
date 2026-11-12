<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Records requests and answers from a fixed script. */
final class ScriptedTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|\Throwable> $script a \Throwable entry is thrown in place of a response */
    public function __construct(private array $script) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof ResponseInterface) {
            throw new \LogicException('Unexpected request ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return $next;
    }

    /** @return array<mixed> */
    public function body(int $index): array
    {
        $decoded = json_decode((string) $this->requests[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    public function target(int $index): string
    {
        $uri = $this->requests[$index]->getUri();
        $query = $uri->getQuery();

        return $uri->getPath() . ($query === '' ? '' : '?' . $query);
    }
}
