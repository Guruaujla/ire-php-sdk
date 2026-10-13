<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Exception\TenantMappingException;
use Ezee\IntegrationRuntime\RetryPolicy;
use Ezee\IntegrationRuntime\TenantClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TenantTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|\Throwable> $script */
    public function __construct(private array $script) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof ResponseInterface) {
            throw new \LogicException('Unexpected request');
        }

        return $next;
    }
}
#[CoversClass(TenantClient::class)]
final class TenantClientTest extends TestCase
{
    /** @return array{tenant_id:string,external_tenant_id:string,name:string,status:string} */
    private static function dto(string $external = 'Customer_001', string $name = 'Customer', string $status = 'active'): array
    {
        return ['tenant_id' => 'opaque-original', 'external_tenant_id' => $external, 'name' => $name, 'status' => $status];
    }

    private static function response(mixed $body, int $status = 200): Response
    {
        return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<ResponseInterface|\Throwable>  $script
     * @return array{TenantClient,TenantTransport}
     */
    private static function fixture(array $script): array
    {
        $transport = new TenantTransport($script);
        $factory = new HttpFactory();

        return [new TenantClient(new Client($transport, $factory, $factory, 'https://runtime.invalid', 'product-app', 'test-key', 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8', RetryPolicy::disabled())), $transport];
    }

    /** @return list<array{string,string}> */
    public static function keys(): array
    {
        return [['.', '%252E'], ['..', '%252E%252E'], ['00123', '00123'], ['Case.Name_9', 'Case.Name_9']];
    }

    #[DataProvider('keys')]
    public function test_exact_lookup_bytes(string $external, string $encoded): void
    {
        [$tenant, $transport] = self::fixture([self::response(self::dto($external))]);
        self::assertSame(self::dto($external), $tenant->getTenantByExternalId($external));
        self::assertSame('/v1/tenants/by-external-id/' . $encoded, $transport->requests[0]->getUri()->getPath());
    }

    /** @return list<array{string}> */
    public static function invalidKeys(): array
    {
        return [[''], ['a/b'], ['a?b'], ['a%2Eb'], ["a\n"], ['é'], [str_repeat('x', 129)]];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_key_fails_before_io(string $key): void
    {
        [$tenant, $transport] = self::fixture([]);
        try {
            $tenant->getTenantByExternalId($key);
            self::fail('Expected refusal');
        } catch (TenantMappingException $error) {
            self::assertSame('sdk.invalid_external_tenant_id', $error->problemCode);
        }
        self::assertCount(0, $transport->requests);
    }

    public function test_registration_body_and_projection(): void
    {
        [$tenant, $transport] = self::fixture([self::response([...self::dto(), 'created' => true, 'metadata_matches' => true, 'secret' => 'not-projected'], 201)]);
        self::assertSame([...self::dto(), 'created' => true, 'metadata_matches' => true], $tenant->registerTenant('Customer_001', 'Customer'));
        self::assertSame(['external_tenant_id' => 'Customer_001', 'name' => 'Customer'], json_decode((string) $transport->requests[0]->getBody(), true));
        self::assertSame('product-app', $transport->requests[0]->getHeaderLine('X-IR-App-Id'));
    }

    public function test_lost_and_unavailable_registration_recover_with_one_post(): void
    {
        foreach ([new \RuntimeException('lost response'), self::response(['code' => 'tenant.registry_unavailable'], 503)] as $lost) {
            [$tenant, $transport] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), $lost, self::response(self::dto())]);
            self::assertSame(self::dto(), $tenant->registerOrResolveTenant('Customer_001', 'Customer'));
            self::assertSame(['GET', 'POST', 'GET'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $transport->requests));
            self::assertCount(3, array_unique(array_map(static fn(RequestInterface $request): string => $request->getHeaderLine('X-IR-Nonce'), $transport->requests)));
        }
    }

    public function test_metadata_and_mapping_conflicts_refuse(): void
    {
        foreach ([self::dto('Customer_001', 'Existing'), self::dto('foreign'), ['tenant_id' => 'id']] as $body) {
            [$tenant, $transport] = self::fixture([self::response($body)]);
            try {
                $tenant->registerOrResolveTenant('Customer_001', 'Customer');
                self::fail('Expected conflict');
            } catch (TenantMappingException) {
                self::assertCount(1, $transport->requests);
            }
        }
        [$tenant] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), self::response([...self::dto(), 'created' => false, 'metadata_matches' => false])]);
        $this->expectExceptionMessage('sdk.tenant_metadata_conflict');
        $tenant->registerOrResolveTenant('Customer_001', 'Customer');
    }

    public function test_inactive_and_unknown_are_retained_without_post(): void
    {
        foreach (['inactive', 'future_status'] as $status) {
            [$tenant, $transport] = self::fixture([self::response(self::dto(status: $status))]);
            self::assertSame($status, $tenant->registerOrResolveTenant('Customer_001', 'Customer')['status']);
            self::assertCount(1, $transport->requests);
        }
    }

    public function test_unsupported_and_known_refusal_never_fallback(): void
    {
        [$tenant, $transport] = self::fixture([self::response([], 404)]);
        try {
            $tenant->registerOrResolveTenant('Customer_001', 'Customer');
            self::fail('Expected unsupported');
        } catch (TenantMappingException $error) {
            self::assertSame(404, $error->httpStatus);
            self::assertCount(1, $transport->requests);
        }
        [$tenant, $transport] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), self::response(['code' => 'tenant.not_active'], 409)]);
        try {
            $tenant->registerOrResolveTenant('Customer_001', 'Customer');
            self::fail('Expected inactive');
        } catch (TenantMappingException $error) {
            self::assertSame('tenant.not_active', $error->problemCode);
            self::assertCount(2, $transport->requests);
        }
    }

    public function test_failed_recovery_never_repeats_post_and_preserves_original_error(): void
    {
        [$tenant, $transport] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), new \RuntimeException('lost'), self::response(['code' => 'tenant.not_found'], 404)]);
        try {
            $tenant->registerOrResolveTenant('Customer_001', 'Customer');
            self::fail('Expected loss');
        } catch (\RuntimeException $error) {
            self::assertSame('lost', $error->getMessage());
        }
        self::assertSame(['GET', 'POST', 'GET'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $transport->requests));
    }

    public function test_invalid_registration_flags_recover_only_by_exact_lookup(): void
    {
        [$tenant, $transport] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), self::response([...self::dto(), 'created' => 'true', 'metadata_matches' => true]), self::response(self::dto())]);
        self::assertSame(self::dto(), $tenant->registerOrResolveTenant('Customer_001', 'Customer'));
        self::assertCount(3, $transport->requests);
        self::assertSame('GET', $transport->requests[2]->getMethod());
    }

    public function test_numeric_looking_runtime_ids_remain_opaque(): void
    {
        foreach (['0', '00042', 'runtime:opaque'] as $id) {
            [$tenant] = self::fixture([self::response([...self::dto(), 'tenant_id' => $id])]);
            self::assertSame($id, $tenant->getTenantByExternalId('Customer_001')['tenant_id']);
        }
    }

    public function test_conflicting_recovery_mapping_is_not_masked(): void
    {
        foreach ([[self::dto('foreign'), 'sdk.tenant_identity_conflict'], [self::dto(name: 'Other name'), 'sdk.tenant_metadata_conflict']] as [$body, $code]) {
            [$tenant, $transport] = self::fixture([self::response(['code' => 'tenant.not_found'], 404), new \RuntimeException('lost'), self::response($body)]);
            try {
                $tenant->registerOrResolveTenant('Customer_001', 'Customer');
                self::fail('Expected conflict');
            } catch (TenantMappingException $error) {
                self::assertSame($code, $error->problemCode);
            }
            self::assertCount(3, $transport->requests);
        }
    }

    public function test_utf16_name_limit_before_io(): void
    {
        foreach (['', str_repeat('😀', 101)] as $name) {
            [$tenant, $transport] = self::fixture([]);
            try {
                $tenant->registerTenant('Customer_001', $name);
                self::fail('Expected name refusal');
            } catch (TenantMappingException $error) {
                self::assertSame('sdk.invalid_tenant_name', $error->problemCode);
                self::assertCount(0, $transport->requests);
            }
        }
    }
}
