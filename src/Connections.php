<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * Native QuickBooks connections for one runtime tenant.
 *
 * Flow: {@see start()} -> redirect the user to `authorize_url` -> the runtime
 * redirects back to your `return_url` with `status`, `connection_id` and your
 * `state` -> check `state` -> {@see get()} and require `status === 'connected'`.
 * The redirect parameters are hints; {@see get()} is authoritative.
 */
final class Connections
{
    public function __construct(private readonly Client $client) {}

    /**
     * @param string $state one-time, unpredictable value you stored server-side for this attempt
     * @return array{session_id: string, authorize_url: string, expires_at: int, connection_id: string|null}
     */
    public function start(string $tenantId, string $returnUrl, string $state): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('returnUrl', $returnUrl);
        ResponseReader::requireNonEmpty('state', $state);
        $body = ResponseReader::json($this->client->send('POST', '/v1/connections/connect-session', [
            'tenant_id' => $tenantId,
            'provider_key' => 'quickbooks',
            'return_url' => $returnUrl,
            'state' => $state,
        ]), [200, 201]);
        $sessionId = $body['session_id'] ?? null;
        $authorizeUrl = $body['authorize_url'] ?? null;
        $expiresAt = $body['expires_at'] ?? null;
        $connectionId = $body['connection_id'] ?? null;
        if (! is_string($sessionId) || ! is_string($authorizeUrl) || ! str_starts_with($authorizeUrl, 'https://') || ! is_int($expiresAt)) {
            throw new \UnexpectedValueException('Connect session response is missing session_id, authorize_url or expires_at.');
        }

        return [
            'session_id' => $sessionId,
            'authorize_url' => $authorizeUrl,
            'expires_at' => $expiresAt,
            'connection_id' => is_string($connectionId) ? $connectionId : null,
        ];
    }

    /** @return array<string, mixed> authoritative connection state; use only `status === 'connected'` */
    public function get(string $tenantId, string $connectionId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);

        return ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/connections/' . rawurlencode($connectionId), ['tenant_id' => $tenantId])));
    }

    /** @return array<string, mixed> one page: `data` plus cursor fields */
    public function list(string $tenantId, ?string $cursor = null, ?int $limit = null): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);

        return ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/connections', ['tenant_id' => $tenantId, 'cursor' => $cursor, 'limit' => $limit])));
    }
}
