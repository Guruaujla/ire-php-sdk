<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * Native accounting connections (QuickBooks or Xero) for one runtime tenant.
 *
 * Flow: {@see start()} -> redirect the user to `authorize_url` -> the runtime
 * redirects back to your `return_url` with `status`, `connection_id` and your
 * `state` -> check `state` -> {@see get()} and require `status === 'connected'`.
 * The redirect parameters are hints; {@see get()} is authoritative.
 */
final class Connections
{
    /** The providers the public connect-session endpoint accepts. */
    public const PROVIDER_KEYS = ['quickbooks', 'xero'];

    public function __construct(private readonly Client $client) {}

    /**
     * @param string $state one-time, unpredictable value you stored server-side for this attempt
     * @param string $providerKey 'quickbooks' (the default) or 'xero'; Xero needs the
     *   operator to add the app's Xero provider configuration first
     * @return array{session_id: string, authorize_url: string, expires_at: int, connection_id: string|null}
     */
    public function start(string $tenantId, string $returnUrl, string $state, string $providerKey = 'quickbooks'): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('returnUrl', $returnUrl);
        ResponseReader::requireNonEmpty('state', $state);
        if (! in_array($providerKey, self::PROVIDER_KEYS, true)) {
            throw new \InvalidArgumentException('providerKey must be one of ' . implode(', ', self::PROVIDER_KEYS));
        }
        $body = ResponseReader::json($this->client->send('POST', '/v1/connections/connect-session', [
            'tenant_id' => $tenantId,
            'provider_key' => $providerKey,
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

    /**
     * Start a connect session that reconnects an existing connection: the
     * answer to a `connection.reconnect_required` webhook. Reads the
     * connection (one signed GET) for its `provider_key`, then calls
     * {@see start()} for the same tenant and provider; the runtime reconnects
     * the tenant's existing connection. Redirect the user to `authorize_url`
     * exactly as for a first connect.
     *
     * @param string $state one-time, unpredictable value you stored server-side for this attempt
     * @return array{session_id: string, authorize_url: string, expires_at: int, connection_id: string|null}
     */
    public function reconnect(string $tenantId, string $connectionId, string $returnUrl, string $state): array
    {
        ResponseReader::requireNonEmpty('returnUrl', $returnUrl);
        ResponseReader::requireNonEmpty('state', $state);
        $connection = $this->get($tenantId, $connectionId);
        $providerKey = $connection['provider_key'] ?? null;
        if (! is_string($providerKey) || ! in_array($providerKey, self::PROVIDER_KEYS, true)) {
            throw new \UnexpectedValueException('Connection ' . $connectionId . ' has no provider_key this SDK can reconnect.');
        }

        return $this->start($tenantId, $returnUrl, $state, $providerKey);
    }

    /**
     * Disconnect one native accounting connection (QuickBooks or Xero):
     * `POST /v1/connections/{id}/disconnect`. Writes for the connection stop
     * at once, so ask the admin to confirm first. Reads the connection (one
     * signed GET) for its `provider_key`.
     *
     * `$idempotencyKey`: make a new key for each disconnect the admin
     * confirms and store it; reuse it only to retry that same attempt. An old
     * key (for example from before a reconnect) returns the earlier result
     * and disconnects nothing.
     *
     * `$providerAccountId`: the company id (QuickBooks company or Xero tenant
     * id) you stored from {@see Accounting::providers()} `connection` when the
     * connection was confirmed. Connection reads do not return it. When you
     * omit it, the SDK reads `providers()` (one more signed GET) and uses its
     * `connection` if it is this connection. That works only while this is the
     * tenant's one connected accounting connection, so pass the stored value
     * to disconnect an extra connection or one that needs a reconnect, and on
     * a retry.
     *
     * A refusal throws {@see RuntimeProblemException}: retry
     * `409 oauth.lifecycle_busy` and `503 oauth.disconnect_retryable` later
     * with the SAME key; `409 connection.recovery_pending` (Xero) waits for
     * the operator.
     *
     * @return array{provider_cleanup: string|null} `customer_action_required`
     *   when a Xero disconnect completed locally only (the grant was already
     *   dead): the Xero user who connected the organisation can remove it at
     *   https://apps.xero.com/connected. Null otherwise.
     *
     * @throws RuntimeProblemException
     * @throws \InvalidArgumentException when an argument is blank, too long, or not a provider this SDK can disconnect
     */
    public function disconnect(string $tenantId, string $connectionId, string $idempotencyKey, ?string $providerAccountId = null): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('idempotencyKey must be a stable 1-128 character key for this disconnect attempt');
        }
        if ($providerAccountId !== null) {
            ResponseReader::requireNonEmpty('providerAccountId', $providerAccountId);
        }
        $connection = $this->get($tenantId, $connectionId);
        $providerKey = $connection['provider_key'] ?? null;
        if (! is_string($providerKey) || ! in_array($providerKey, self::PROVIDER_KEYS, true)) {
            throw new \UnexpectedValueException('Connection ' . $connectionId . ' has no provider_key this SDK can disconnect.');
        }
        $providerAccountId ??= $this->connectedProviderAccountId($tenantId, $connectionId);
        $response = $this->client->send('POST', '/v1/connections/' . rawurlencode($connectionId) . '/disconnect', [
            'tenant_id' => $tenantId,
            'provider_key' => $providerKey,
            'provider_account_id' => $providerAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);
        if ($response->getStatusCode() !== 204) {
            ResponseReader::json($response, [204]);
        }
        $cleanup = trim($response->getHeaderLine('X-IR-Provider-Cleanup'));

        return ['provider_cleanup' => $cleanup === '' ? null : $cleanup];
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

    /** The company id `GET /v1/accounting/providers` reports for this connection, while it is the tenant's one connected accounting connection. */
    private function connectedProviderAccountId(string $tenantId, string $connectionId): string
    {
        $body = ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/accounting/providers', ['tenant_id' => $tenantId])));
        $connection = $body['connection'] ?? null;
        $accountId = is_array($connection) && ($connection['connection_id'] ?? null) === $connectionId
            ? ($connection['provider_account_id'] ?? null)
            : null;
        if (! is_string($accountId) || $accountId === '') {
            throw new \UnexpectedValueException(
                'Connection ' . $connectionId . ' is not the tenant\'s one connected accounting connection, so its provider_account_id '
                . 'cannot be read; pass the provider_account_id you stored from providers() when the connection was confirmed.',
            );
        }

        return $accountId;
    }
}
