<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\ConnectionStatus;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Secret-free local fixture for the connection read journey. */
final class MockRuntimeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var array<string, array<string, int|string|null>> */
    private array $connections;

    public function __construct(private readonly string $tenantId)
    {
        $this->connections = [
            'conn_connected' => $this->connection('conn_connected', 'connected', 1_700_000_001),
            'conn_reconnect' => $this->connection('conn_reconnect', 'reconnect_required', null),
            'conn_disconnected' => $this->connection('conn_disconnected', 'disconnected', null),
            'conn_disabled' => $this->connection('conn_disabled', 'disabled', null),
        ];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($request->getMethod() !== 'GET') {
            return $this->problem(404, 'request.route_not_found', 'No mock route matched.');
        }

        parse_str($request->getUri()->getQuery(), $query);
        if (($query['tenant_id'] ?? null) !== $this->tenantId) {
            return $this->problem(403, 'authz.tenant_mismatch', 'Wrong tenant scope.');
        }

        $path = $request->getUri()->getPath();
        if ($path === '/v1/connections') {
            $cursor = $query['cursor'] ?? null;
            if ($cursor === null) {
                return $this->json(200, [
                    'connections' => [
                        $this->connections['conn_connected'],
                        $this->connections['conn_reconnect'],
                    ],
                    'next_cursor' => 'mock-page-2',
                ]);
            }
            if ($cursor === 'mock-page-2') {
                return $this->json(200, [
                    'connections' => [
                        $this->connections['conn_disconnected'],
                        $this->connections['conn_disabled'],
                    ],
                    'next_cursor' => null,
                ]);
            }

            return $this->problem(400, 'request.validation_failed', 'Invalid mock cursor.');
        }

        $prefix = '/v1/connections/';
        if (str_starts_with($path, $prefix)) {
            $connectionId = rawurldecode(substr($path, strlen($prefix)));
            $connection = $this->connections[$connectionId] ?? null;
            if ($connection === null) {
                return $this->problem(404, 'connection.not_found', 'Connection not found.');
            }

            return $this->json(200, $connection);
        }

        return $this->problem(404, 'request.route_not_found', 'No mock route matched.');
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return array<string, int|string|null> */
    private function connection(string $id, string $status, ?int $connectedAt): array
    {
        return [
            'id' => $id,
            'provider_key' => 'quickbooks',
            'status' => $status,
            'connected_at' => $connectedAt,
            'created_at' => 1_700_000_000,
            'updated_at' => 1_700_000_100,
        ];
    }

    /** @param array<array-key, mixed> $body */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function problem(int $status, string $code, string $detail): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode([
                'type' => "urn:ir:error:{$code}",
                'title' => 'Mock runtime problem',
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
                'request_id' => 'req_connection_mock',
                'correlation_id' => null,
                'instance' => null,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
