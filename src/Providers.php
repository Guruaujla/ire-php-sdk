<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * The providers your app can connect (`GET /v1/providers`), for building an
 * integrations screen that shows a newly added provider without a code change.
 *
 * Each provider is `['provider_key' => 'xero', 'display_name' => 'Xero',
 * 'category' => 'accounting', 'enabled' => true]`. `category` names the
 * unified API that serves it (`accounting` today; `meetings` and `calendar`
 * as they ship; treat an unknown category as one your product does not use
 * yet). `enabled` is true when your app is set up for it, so you can offer
 * Connect: pass `provider_key` to {@see Connections::start()} (it can still
 * answer 503 `connect.provider_not_configured` while the operator is adding
 * the provider's credentials to the environment). Use
 * {@see Connections::list()} for the tenant's existing connections.
 */
final class Providers
{
    public function __construct(private readonly Client $client) {}

    /**
     * Every available provider, ordered by category then provider key.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $tenantId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        $body = ResponseReader::json(
            $this->client->send('GET', ResponseReader::query('/v1/providers', ['tenant_id' => $tenantId])),
        );
        $providers = $body['providers'] ?? [];
        $out = [];
        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (is_array($provider)) {
                    /** @var array<string, mixed> $provider */
                    $out[] = $provider;
                }
            }
        }

        return $out;
    }

    /**
     * The available providers of one category, for example `accounting`.
     *
     * @return list<array<string, mixed>>
     */
    public function inCategory(string $tenantId, string $category): array
    {
        return array_values(array_filter(
            $this->list($tenantId),
            static fn(array $p): bool => ($p['category'] ?? null) === $category,
        ));
    }
}
