<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\TenantMappingException;
use Psr\Http\Message\ResponseInterface;

/**
 * Own-app mapping only. Product authorization and durable storage are required.
 *
 * @phpstan-type Mapping array{tenant_id: non-empty-string, external_tenant_id: string, name: string, status: string}
 * @phpstan-type Registration array{tenant_id: non-empty-string, external_tenant_id: string, name: string, status: string, created: bool, metadata_matches: bool}
 */
final class TenantClient
{
    public function __construct(private readonly Client $client) {}

    public static function externalIdPath(string $externalId): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $externalId) !== 1) {
            throw new TenantMappingException('sdk.invalid_external_tenant_id');
        }
        $segment = ($externalId === '.' || $externalId === '..') ? str_replace('.', '%252E', $externalId) : rawurlencode($externalId);

        return '/v1/tenants/by-external-id/' . $segment;
    }

    private static function validate(string $externalId, string $name): void
    {
        self::externalIdPath($externalId);
        $count = preg_match_all('/./us', $name, $chars);
        $units = $count === false ? 0 : array_sum(array_map(static fn(string $char): int => strlen($char) === 4 ? 2 : 1, $chars[0]));
        if ($units < 1 || $units > 200) {
            throw new TenantMappingException('sdk.invalid_tenant_name');
        }
    }

    private static function read(ResponseInterface $response): mixed
    {
        try {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $code = is_array($body) && isset($body['code']) && is_string($body['code']) ? $body['code'] : 'http.' . $status;
            throw new TenantMappingException($code, $status);
        }

        return $body;
    }

    /** @return Mapping */
    private static function mapping(mixed $body, string $externalId): array
    {
        if (! is_array($body) || ! isset($body['tenant_id'], $body['external_tenant_id'], $body['name'], $body['status']) || ! is_string($body['tenant_id']) || $body['tenant_id'] === '' || ! is_string($body['external_tenant_id']) || ! is_string($body['name']) || ! is_string($body['status'])) {
            throw new TenantMappingException('sdk.tenant_invalid_response');
        }
        if ($body['external_tenant_id'] !== $externalId) {
            throw new TenantMappingException('sdk.tenant_identity_conflict');
        }

        return ['tenant_id' => $body['tenant_id'], 'external_tenant_id' => $body['external_tenant_id'], 'name' => $body['name'], 'status' => $body['status']];
    }

    /** @return Mapping */
    public function getTenantByExternalId(string $externalId): array
    {
        return self::mapping(self::read($this->client->send('GET', self::externalIdPath($externalId))), $externalId);
    }

    /** @return Registration */
    public function registerTenant(string $externalId, string $name): array
    {
        self::validate($externalId, $name);
        $body = self::read($this->client->send('POST', '/v1/tenants', ['external_tenant_id' => $externalId, 'name' => $name]));
        $mapping = self::mapping($body, $externalId);
        if (! is_array($body) || ! isset($body['created'], $body['metadata_matches']) || ! is_bool($body['created']) || ! is_bool($body['metadata_matches'])) {
            throw new TenantMappingException('sdk.tenant_invalid_response');
        }

        return [...$mapping, 'created' => $body['created'], 'metadata_matches' => $body['metadata_matches']];
    }

    /**
     * @param  Mapping  $mapping
     * @return Mapping
     */
    private static function checked(array $mapping, string $name, bool $metadataMatches = true): array
    {
        if ($mapping['name'] !== $name || ! $metadataMatches) {
            throw new TenantMappingException('sdk.tenant_metadata_conflict');
        }

        return ['tenant_id' => $mapping['tenant_id'], 'external_tenant_id' => $mapping['external_tenant_id'], 'name' => $mapping['name'], 'status' => $mapping['status']];
    }

    /** @return Mapping */
    public function registerOrResolveTenant(string $externalId, string $name): array
    {
        self::validate($externalId, $name);
        try {
            return self::checked($this->getTenantByExternalId($externalId), $name);
        } catch (TenantMappingException $error) {
            if ($error->httpStatus !== 404 || $error->problemCode !== 'tenant.not_found') {
                throw $error;
            }
        }
        try {
            $result = $this->registerTenant($externalId, $name);

            return self::checked($result, $name, $result['metadata_matches']);
        } catch (\Throwable $error) {
            $ambiguous = $error instanceof TenantMappingException ? (($error->httpStatus !== null && $error->httpStatus >= 500) || $error->problemCode === 'sdk.tenant_invalid_response') : ! ($error instanceof \InvalidArgumentException);
            if (! $ambiguous) {
                throw $error;
            }
            try {
                return self::checked($this->getTenantByExternalId($externalId), $name);
            } catch (\Throwable $recovery) {
                if ($recovery instanceof TenantMappingException && in_array($recovery->problemCode, ['sdk.tenant_metadata_conflict', 'sdk.tenant_identity_conflict'], true)) {
                    throw $recovery;
                }
                throw $error;
            }
        }
    }
}
