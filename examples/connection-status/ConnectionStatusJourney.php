<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\ConnectionStatus;

use Ezee\IntegrationRuntime\Client;
use Psr\Http\Message\ResponseInterface;

final readonly class ConnectionSnapshot
{
    public function __construct(
        public string $id,
        public string $providerKey,
        public string $runtimeStatus,
        public ?int $connectedAt,
        public int $createdAt,
        public int $updatedAt,
    ) {}

    /**
     * Product-side classification. Preserve runtimeStatus separately so a new
     * public status never crashes this consumer or gets silently called ready.
     *
     * @return 'ready'|'reconnect'|'disconnected'|'unavailable'|'review'
     */
    public function recommendedAction(): string
    {
        return match ($this->runtimeStatus) {
            'connected' => 'ready',
            'reconnect_required' => 'reconnect',
            'disconnected' => 'disconnected',
            'disabled' => 'unavailable',
            default => 'review',
        };
    }
}

final readonly class ConnectionPage
{
    /** @param list<ConnectionSnapshot> $connections */
    public function __construct(
        public array $connections,
        public ?string $nextCursor,
    ) {}
}

final readonly class ConnectionReconciliation
{
    public function __construct(
        public string $callbackHint,
        public string $normalizedHint,
        public ConnectionSnapshot $authoritative,
        public bool $hintMatches,
    ) {}

    /** @return 'ready'|'reconnect'|'disconnected'|'unavailable'|'review' */
    public function recommendedAction(): string
    {
        return $this->authoritative->recommendedAction();
    }
}

final class ConnectionReadException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $problemCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

/**
 * Product-backend example for discovering connection ids and reconciling an
 * OAuth return hint against authoritative runtime state.
 *
 * The generic Client still owns request signing. This class adds only the
 * bounded consumer behaviour a product needs: tenant-scoped list/get calls,
 * tolerant status handling, and no trust in browser callback status alone.
 */
final readonly class ConnectionStatusJourney
{
    public function __construct(
        private Client $client,
        private string $tenantId,
    ) {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenantId must not be empty');
        }
    }

    public function listConnections(int $limit = 50, ?string $cursor = null): ConnectionPage
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('limit must be between 1 and 100');
        }
        if ($cursor === '') {
            throw new \InvalidArgumentException('cursor must be null or non-empty');
        }

        $query = ['tenant_id' => $this->tenantId, 'limit' => (string) $limit];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        $response = $this->client->send(
            'GET',
            '/v1/connections?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986),
        );
        $body = $this->decodeObject($response, 200);
        $rawConnections = $body['connections'] ?? null;
        if (! is_array($rawConnections) || ! array_is_list($rawConnections)) {
            throw new \UnexpectedValueException('connection list response is missing connections[]');
        }

        $connections = [];
        foreach ($rawConnections as $raw) {
            if (! is_array($raw)) {
                throw new \UnexpectedValueException('connection list contains a non-object item');
            }
            $connections[] = $this->snapshot($raw);
        }
        $nextCursor = $body['next_cursor'] ?? null;
        if ($nextCursor !== null && ! is_string($nextCursor)) {
            throw new \UnexpectedValueException('connection list next_cursor must be string or null');
        }

        return new ConnectionPage($connections, $nextCursor);
    }

    public function getConnection(string $connectionId): ConnectionSnapshot
    {
        if ($connectionId === '') {
            throw new \InvalidArgumentException('connectionId must not be empty');
        }
        $path = sprintf(
            '/v1/connections/%s?tenant_id=%s',
            rawurlencode($connectionId),
            rawurlencode($this->tenantId),
        );
        $response = $this->client->send('GET', $path);

        return $this->snapshot($this->decodeObject($response, 200));
    }

    public function reconcileCallbackHint(
        string $connectionId,
        string $callbackStatusHint,
    ): ConnectionReconciliation {
        if ($callbackStatusHint === '') {
            throw new \InvalidArgumentException('callbackStatusHint must not be empty');
        }
        // The OAuth return surface uses already_connected for its successful
        // no-op handoff; the authoritative connection state uses connected.
        $normalizedHint = $callbackStatusHint === 'already_connected'
            ? 'connected'
            : $callbackStatusHint;
        $authoritative = $this->getConnection($connectionId);

        return new ConnectionReconciliation(
            $callbackStatusHint,
            $normalizedHint,
            $authoritative,
            hash_equals($authoritative->runtimeStatus, $normalizedHint),
        );
    }

    /** @param array<array-key, mixed> $body */
    private function snapshot(array $body): ConnectionSnapshot
    {
        $connectedAt = $body['connected_at'] ?? null;
        if ($connectedAt !== null && ! is_int($connectedAt)) {
            throw new \UnexpectedValueException('connection connected_at must be integer or null');
        }

        return new ConnectionSnapshot(
            $this->requiredString($body, 'id'),
            $this->requiredString($body, 'provider_key'),
            $this->requiredString($body, 'status'),
            $connectedAt,
            $this->requiredInt($body, 'created_at'),
            $this->requiredInt($body, 'updated_at'),
        );
    }

    /** @param array<array-key, mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException("connection {$key} must be a non-empty string");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $body */
    private function requiredInt(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (! is_int($value)) {
            throw new \UnexpectedValueException("connection {$key} must be an integer");
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function decodeObject(ResponseInterface $response, int $expectedStatus): array
    {
        $raw = (string) $response->getBody();
        try {
            $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('runtime returned invalid JSON', previous: $exception);
        }
        if (! is_array($body)) {
            throw new \UnexpectedValueException('runtime response must be a JSON object');
        }
        if ($response->getStatusCode() !== $expectedStatus) {
            $problemCode = is_string($body['code'] ?? null) ? $body['code'] : null;
            $detail = is_string($body['detail'] ?? null) ? $body['detail'] : 'runtime request failed';
            throw new ConnectionReadException($response->getStatusCode(), $problemCode, $detail);
        }

        return $body;
    }
}
