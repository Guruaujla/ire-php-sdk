<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Providers;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Providers::class)]
final class ProvidersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function body(): array
    {
        return ['providers' => [
            ['provider_key' => 'quickbooks', 'display_name' => 'QuickBooks Online', 'category' => 'accounting', 'enabled' => true],
            ['provider_key' => 'xero', 'display_name' => 'Xero', 'category' => 'accounting', 'enabled' => false],
            ['provider_key' => 'google-meet', 'display_name' => 'Google Meet', 'category' => 'meetings', 'enabled' => true],
        ]];
    }

    public function testListSendsTenantAndReturnsProviders(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::body())]);
        $providers = (new Providers(Clients::client($transport)))->list('t1');

        self::assertCount(3, $providers);
        $first = $providers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('quickbooks', $first['provider_key']);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/providers?tenant_id=t1', $transport->target(0));
    }

    public function testInCategoryFilters(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::body())]);
        $meetings = (new Providers(Clients::client($transport)))->inCategory('t1', 'meetings');

        self::assertSame(['google-meet'], array_map(static fn(array $p): mixed => $p['provider_key'], $meetings));
    }

    public function testRefusesEmptyTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Providers(Clients::client(new ScriptedTransport([]))))->list('');
    }

    public function testProblemResponseThrows(): void
    {
        $transport = new ScriptedTransport([Clients::json(403, ['code' => 'authz.tenant_mismatch', 'title' => 'Tenant mismatch'])]);
        $this->expectException(RuntimeProblemException::class);
        (new Providers(Clients::client($transport)))->list('t2');
    }
}
