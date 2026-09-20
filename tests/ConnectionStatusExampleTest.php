<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionReadException;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionSnapshot;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionStatusJourney;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../examples/connection-status/ConnectionStatusJourney.php';
require_once __DIR__ . '/../examples/connection-status/MockRuntimeTransport.php';

final class ConnectionStatusExampleTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private const TENANT_ID = 'tenant_local_fixture';

    /** @return array{ConnectionStatusJourney, MockRuntimeTransport} */
    private function journey(): array
    {
        $transport = new MockRuntimeTransport(self::TENANT_ID);
        $factory = new HttpFactory();
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.example.test',
            'app_local_fixture',
            'key_local_fixture',
            self::SECRET,
            RetryPolicy::disabled(),
        );

        return [new ConnectionStatusJourney($client, self::TENANT_ID), $transport];
    }

    public function testMockedDeveloperJourneyPagesThroughAllAuthoritativeStates(): void
    {
        [$journey, $transport] = $this->journey();
        $first = $journey->listConnections(2);
        self::assertSame(['conn_connected', 'conn_reconnect'], array_map(
            static fn(ConnectionSnapshot $connection): string => $connection->id,
            $first->connections,
        ));
        self::assertSame(['ready', 'reconnect'], array_map(
            static fn(ConnectionSnapshot $connection): string => $connection->recommendedAction(),
            $first->connections,
        ));
        self::assertSame('mock-page-2', $first->nextCursor);

        $second = $journey->listConnections(2, $first->nextCursor);
        self::assertCount(2, $second->connections);
        self::assertSame('disconnected', $second->connections[0]->runtimeStatus);
        self::assertSame('disconnected', $second->connections[0]->recommendedAction());
        self::assertSame('disabled', $second->connections[1]->runtimeStatus);
        self::assertSame('unavailable', $second->connections[1]->recommendedAction());
        self::assertNull($second->nextCursor);

        self::assertCount(2, $transport->requests());
        self::assertSame('/v1/connections', $transport->requests()[0]->getUri()->getPath());
        self::assertStringContainsString('tenant_id=tenant_local_fixture', $transport->requests()[0]->getUri()->getQuery());
        self::assertStringContainsString('cursor=mock-page-2', $transport->requests()[1]->getUri()->getQuery());
        foreach ($transport->requests() as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertNotSame('', $request->getHeaderLine('X-IR-Signature'));
            self::assertSame('', (string) $request->getBody());
        }
    }

    public function testCallbackHintIsComparedWithAuthoritativeGetResponse(): void
    {
        [$journey] = $this->journey();

        $connected = $journey->reconcileCallbackHint('conn_connected', 'already_connected');
        self::assertTrue($connected->hintMatches);
        self::assertSame('connected', $connected->normalizedHint);
        self::assertSame('ready', $connected->recommendedAction());

        $reconnect = $journey->reconcileCallbackHint('conn_reconnect', 'connected');
        self::assertFalse($reconnect->hintMatches);
        self::assertSame('reconnect_required', $reconnect->authoritative->runtimeStatus);
        self::assertSame('reconnect', $reconnect->recommendedAction());

        $disconnected = $journey->reconcileCallbackHint('conn_disconnected', 'disconnected');
        self::assertTrue($disconnected->hintMatches);
        self::assertSame('disconnected', $disconnected->recommendedAction());

        $disabled = $journey->reconcileCallbackHint('conn_disabled', 'connected');
        self::assertFalse($disabled->hintMatches);
        self::assertSame('disabled', $disabled->authoritative->runtimeStatus);
        self::assertSame('unavailable', $disabled->recommendedAction());
    }

    public function testUnknownFutureStatusIsPreservedAndRequiresReview(): void
    {
        $connection = new ConnectionSnapshot(
            'conn_future',
            'future-provider',
            'future_status',
            null,
            1,
            2,
        );

        self::assertSame('future_status', $connection->runtimeStatus);
        self::assertSame('review', $connection->recommendedAction());
    }

    public function testUnknownConnectionPreservesTheNonEnumeratingProblemCode(): void
    {
        [$journey] = $this->journey();

        try {
            $journey->getConnection('conn_missing');
            self::fail('missing connection should fail');
        } catch (ConnectionReadException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('connection.not_found', $exception->problemCode);
        }
    }

    public function testExampleRespectsTheDocumentedPageBound(): void
    {
        [$journey] = $this->journey();

        foreach ([0, 101] as $limit) {
            try {
                $journey->listConnections($limit);
                self::fail("limit {$limit} should fail locally");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('limit must be between 1 and 100', $exception->getMessage());
            }
        }
    }

    public function testExampleRoutesAndAllowlistExistInCurrentPublicOpenApi(): void
    {
        $path = dirname(__DIR__, 3) . '/openapi/integration-runtime.openapi.yaml';
        $openApi = file_get_contents($path);
        self::assertIsString($openApi);
        self::assertStringContainsString('  /v1/connections:', $openApi);
        self::assertStringContainsString('operationId: listConnections', $openApi);
        self::assertStringContainsString('operationId: getConnection', $openApi);

        $schemaStart = strpos($openApi, '    ConnectionPublicDTO:');
        $schemaEnd = strpos($openApi, '    ConnectionsListResponse:', (int) $schemaStart);
        self::assertNotFalse($schemaStart);
        self::assertNotFalse($schemaEnd);
        $schema = substr($openApi, (int) $schemaStart, (int) $schemaEnd - (int) $schemaStart);
        foreach (['id:', 'provider_key:', 'status:', 'connected_at:', 'created_at:', 'updated_at:'] as $field) {
            self::assertStringContainsString($field, $schema);
        }
        foreach (['native_credential_id', 'scopes', 'provider_account_id', 'connected_by_user_id', 'provider_profile_id', 'is_sandbox'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $schema);
        }
    }
}
