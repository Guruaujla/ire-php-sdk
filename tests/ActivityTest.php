<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Activity;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Activity::class)]
final class ActivityTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function event(string $id): array
    {
        return ['id' => $id, 'type' => 'operation', 'occurred_at' => '2026-11-18T06:00:00.000Z', 'summary' => 'Invoice INV-1 updated in QuickBooks.'];
    }

    public function testListSendsFiltersAndJoinsTypes(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['events' => [self::event('op:j1')], 'next_cursor' => null])]);
        $page = (new Activity(Clients::client($transport)))->list('t1', ['connection_id' => 'c1', 'erp_id' => 'INV-1', 'types' => ['operation', 'document'], 'since' => 1000, 'limit' => 10]);

        $events = $page['events'];
        self::assertIsArray($events);
        $first = $events[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('op:j1', $first['id']);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/activity?connection_id=c1&erp_id=INV-1&types=operation%2Cdocument&since=1000&limit=10&tenant_id=t1', $transport->target(0));
    }

    public function testEachFollowsNextCursorAndStopsAtNull(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['events' => [self::event('op:j2'), self::event('op:j1')], 'next_cursor' => 'abc']),
            Clients::json(200, ['events' => [], 'next_cursor' => 'def']),
            Clients::json(200, ['events' => [self::event('connection:x')], 'next_cursor' => null]),
        ]);
        $ids = [];
        foreach ((new Activity(Clients::client($transport)))->each('t1', ['types' => 'operation,connection']) as $event) {
            $ids[] = $event['id'];
        }

        self::assertSame(['op:j2', 'op:j1', 'connection:x'], $ids);
        self::assertCount(3, $transport->requests);
        self::assertSame('/v1/activity?cursor=abc&types=operation%2Cconnection&tenant_id=t1', $transport->target(1));
        self::assertSame('/v1/activity?cursor=def&types=operation%2Cconnection&tenant_id=t1', $transport->target(2));
    }

    public function testEachHonoursMaxPages(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['events' => [self::event('a')], 'next_cursor' => 'n1']),
            Clients::json(200, ['events' => [self::event('b')], 'next_cursor' => 'n2']),
        ]);
        $ids = array_map(static fn(array $e): mixed => $e['id'], iterator_to_array((new Activity(Clients::client($transport)))->each('t1', [], 2), false));

        self::assertSame(['a', 'b'], $ids);
        self::assertCount(2, $transport->requests);
    }

    public function testProblemIsThrown(): void
    {
        $transport = new ScriptedTransport([Clients::json(400, ['code' => 'request.validation_failed'])]);
        $this->expectException(RuntimeProblemException::class);
        (new Activity(Clients::client($transport)))->list('t1', ['cursor' => 'bad']);
    }

    public function testTenantArgumentCannotBeOverriddenByFilters(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['events' => [], 'next_cursor' => null])]);
        (new Activity(Clients::client($transport)))->list('t1', ['tenant_id' => 'other']);

        self::assertSame('/v1/activity?tenant_id=t1', $transport->target(0));
    }

    public function testEmptyTenantIsRefusedBeforeSending(): void
    {
        $transport = new ScriptedTransport([]);
        $this->expectException(\InvalidArgumentException::class);
        (new Activity(Clients::client($transport)))->list('');
    }
}
