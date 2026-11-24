<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Calendar;
use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Clean-room tests for the PHP Calendar SDK (Calendar PR 1b work order J),
 * written from docs-site/content/calendar.md.
 */
#[CoversClass(Calendar::class)]
#[CoversClass(Connections::class)]
final class CalendarTest extends TestCase
{
    private const FROM = '2026-12-01T00:00:00-05:00';
    private const TO = '2026-12-08T00:00:00-05:00';

    /** @return array{0: string, 1: array<string, string>} */
    private static function split(ScriptedTransport $transport, int $i): array
    {
        $target = $transport->target($i);
        $path = (string) parse_url('https://x' . $target, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url('https://x' . $target, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return [$path, $query];
    }

    /** @return array<string, mixed> */
    private static function body(ScriptedTransport $transport, int $i): array
    {
        $decoded = json_decode((string) $transport->requests[$i]->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function session(?string $connectionId = 'grant_1'): array
    {
        return [
            'session_id' => 'cs_1',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth?state=s',
            'expires_at' => 1_900_000_000,
            'connection_id' => $connectionId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function page(array $events, ?string $next): array
    {
        return ['connection_id' => 'conn_1', 'calendar_id' => 'primary', 'mode' => 'occurrences', 'events' => $events, 'next_cursor' => $next];
    }

    public function testProvidersSendsTenant(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['providers' => [], 'connections' => []])]);
        $out = (new Calendar(Clients::client($transport)))->providers('t1');

        self::assertSame([], $out['providers']);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/calendar/providers?tenant_id=t1', $transport->target(0));
    }

    public function testCalendarsQueryString(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['connection_id' => 'conn 1', 'calendars' => [], 'next_cursor' => null]),
            Clients::json(200, ['connection_id' => 'conn 1', 'calendars' => [], 'next_cursor' => null]),
        ]);
        $calendar = new Calendar(Clients::client($transport));
        $calendar->calendars('t1', 'conn 1');
        $calendar->calendars('t1', 'conn 1', ['page_size' => 50, 'cursor' => 'c1']);

        [$path, $query] = self::split($transport, 0);
        self::assertSame('/v1/calendar/connections/conn%201/calendars', $path);
        self::assertSame(['tenant_id' => 't1'], $query);
        [, $query] = self::split($transport, 1);
        self::assertSame(['tenant_id' => 't1', 'page_size' => '50', 'cursor' => 'c1'], $query);
    }

    public function testEventsQueryString(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::page([], null)),
            Clients::json(200, self::page([], null)),
        ]);
        $calendar = new Calendar(Clients::client($transport));
        $calendar->events('t1', 'conn_1', ['from' => self::FROM, 'to' => self::TO]);
        $calendar->events('t1', 'conn_1', [
            'from' => self::FROM,
            'to' => self::TO,
            'calendar_id' => 'team+x@group.calendar.google.com',
            'mode' => 'series',
            'page_size' => 250,
            'cursor' => 'cur_2',
        ]);

        self::assertSame('GET', $transport->requests[0]->getMethod());
        [$path, $query] = self::split($transport, 0);
        self::assertSame('/v1/calendar/connections/conn_1/events', $path);
        self::assertSame(['tenant_id' => 't1', 'from' => self::FROM, 'to' => self::TO], $query);
        // The offset's "+"/"-" and ":" survive the round trip (not turned into a space).
        self::assertStringNotContainsString('+', $transport->target(0));

        [, $query] = self::split($transport, 1);
        ksort($query);
        self::assertSame([
            'calendar_id' => 'team+x@group.calendar.google.com',
            'cursor' => 'cur_2',
            'from' => self::FROM,
            'mode' => 'series',
            'page_size' => '250',
            'tenant_id' => 't1',
            'to' => self::TO,
        ], $query);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidEventQueries(): iterable
    {
        yield 'missing from' => [['to' => self::TO]];
        yield 'missing to' => [['from' => self::FROM]];
        yield 'empty from' => [['from' => '', 'to' => self::TO]];
        yield 'bad mode' => [['from' => self::FROM, 'to' => self::TO, 'mode' => 'instances']];
        yield 'page_size 0' => [['from' => self::FROM, 'to' => self::TO, 'page_size' => 0]];
        yield 'page_size 251' => [['from' => self::FROM, 'to' => self::TO, 'page_size' => 251]];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidEventQueries')]
    public function testEventsRefusesInvalidQueryBeforeSending(array $query): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Calendar(Clients::client($transport)))->events('t1', 'conn_1', $query);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }

    /** @return iterable<string, array{0: int}> */
    public static function invalidPageSizes(): iterable
    {
        yield '0' => [0];
        yield '251' => [251];
    }

    #[DataProvider('invalidPageSizes')]
    public function testCalendarsRefusesInvalidPageSize(int $size): void
    {
        $transport = new ScriptedTransport([]);
        $this->expectException(\InvalidArgumentException::class);
        (new Calendar(Clients::client($transport)))->calendars('t1', 'conn_1', ['page_size' => $size]);
    }

    public function testEachEventFollowsCursorsWithTheSameQuery(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, self::page([['event_id' => 'e1'], ['event_id' => 'e2']], 'cur_2')),
            Clients::json(200, self::page([['event_id' => 'e3']], 'cur_3')),
            Clients::json(200, self::page([], null)),
        ]);
        $ids = [];
        foreach ((new Calendar(Clients::client($transport)))->eachEvent('t1', 'conn_1', [
            'from' => self::FROM,
            'to' => self::TO,
            'mode' => 'series',
        ]) as $event) {
            $ids[] = $event['event_id'];
        }

        self::assertSame(['e1', 'e2', 'e3'], $ids);
        self::assertCount(3, $transport->requests);
        [, $q0] = self::split($transport, 0);
        [, $q1] = self::split($transport, 1);
        [, $q2] = self::split($transport, 2);
        self::assertArrayNotHasKey('cursor', $q0);
        self::assertSame('cur_2', $q1['cursor']);
        self::assertSame('cur_3', $q2['cursor']);
        foreach ([$q0, $q1, $q2] as $q) {
            self::assertSame(self::FROM, $q['from']);
            self::assertSame(self::TO, $q['to']);
            self::assertSame('series', $q['mode']);
        }
    }

    public function testEachEventIgnoresACallerCursor(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::page([['event_id' => 'e1']], null))]);
        $events = iterator_to_array((new Calendar(Clients::client($transport)))->eachEvent('t1', 'conn_1', [
            'from' => self::FROM,
            'to' => self::TO,
            'cursor' => 'stale',
        ]), false);

        self::assertCount(1, $events);
        [, $q] = self::split($transport, 0);
        self::assertArrayNotHasKey('cursor', $q);
    }

    public function testConnectSendsPrincipalRef(): void
    {
        $transport = new ScriptedTransport([Clients::json(201, self::session('grant_1'))]);
        $out = (new Calendar(Clients::client($transport)))->connect('t1', 'user-42', 'https://app.test/return', 'state-1');

        self::assertSame('grant_1', $out['connection_id']);
        self::assertSame('POST', $transport->requests[0]->getMethod());
        self::assertSame('/v1/connections/connect-session', $transport->target(0));
        $body = self::body($transport, 0);
        self::assertSame('google-calendar', $body['provider_key']);
        self::assertSame('user-42', $body['principal_ref']);
        self::assertSame('t1', $body['tenant_id']);
        self::assertSame('https://app.test/return', $body['return_url']);
        self::assertSame('state-1', $body['state']);
    }

    public function testStartRefusesPrincipalRefForQuickBooks(): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Connections(Clients::client($transport)))->start('t1', 'https://app.test/return', 's', 'quickbooks', 'user-1');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }

    public function testStartWithoutPrincipalRefForQuickBooksSendsNone(): void
    {
        $transport = new ScriptedTransport([Clients::json(201, self::session(null))]);
        (new Connections(Clients::client($transport)))->start('t1', 'https://app.test/return', 's');

        self::assertArrayNotHasKey('principal_ref', self::body($transport, 0));
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function invalidPrincipalRefs(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('x', 256)];
    }

    #[DataProvider('invalidPrincipalRefs')]
    public function testStartRequiresPrincipalRefForGoogleCalendar(?string $principalRef): void
    {
        $transport = new ScriptedTransport([]);
        try {
            (new Connections(Clients::client($transport)))->start('t1', 'https://app.test/return', 's', 'google-calendar', $principalRef);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }

    public function testStartAcceptsA255CharacterPrincipalRef(): void
    {
        $ref = str_repeat('y', 255);
        $transport = new ScriptedTransport([Clients::json(201, self::session())]);
        (new Connections(Clients::client($transport)))->start('t1', 'https://app.test/return', 's', 'google-calendar', $ref);

        self::assertSame($ref, self::body($transport, 0)['principal_ref']);
    }

    public function testReconnectRefusesGoogleCalendar(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['connection_id' => 'grant_1', 'provider_key' => 'google-calendar', 'status' => 'needs_reconnect']),
        ]);
        try {
            (new Connections(Clients::client($transport)))->reconnect('t1', 'grant_1', 'https://app.test/return', 's');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // No connect session was started.
            foreach ($transport->requests as $request) {
                self::assertNotSame('POST', $request->getMethod());
            }
        }
    }

    public function testDisconnectSendsTheConnectionsProviderAccountId(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, [
                'providers' => [],
                'connections' => [
                    ['connection_id' => 'grant_0', 'provider_key' => 'google-calendar', 'provider_account_id' => 'sub-0', 'principal_ref' => 'u0'],
                    ['connection_id' => 'grant_1', 'provider_key' => 'google-calendar', 'provider_account_id' => 'sub-1', 'principal_ref' => 'u1'],
                ],
            ]),
            Clients::json(200, ['connection_id' => 'grant_1', 'provider_key' => 'google-calendar', 'status' => 'connected']),
            new \GuzzleHttp\Psr7\Response(204),
        ]);
        (new Calendar(Clients::client($transport)))->disconnect('t1', 'grant_1', 'dc-key-1');

        $post = null;
        foreach ($transport->requests as $i => $request) {
            if ($request->getMethod() === 'POST') {
                $post = $i;
            }
        }
        self::assertNotNull($post);
        self::assertSame('/v1/connections/grant_1/disconnect', $transport->target($post));
        $body = self::body($transport, $post);
        self::assertSame('google-calendar', $body['provider_key']);
        self::assertSame('sub-1', $body['provider_account_id']);
        self::assertSame('dc-key-1', $body['idempotency_key']);
    }

    public function testDisconnectWithAnExplicitProviderAccountIdSendsItWithoutListingProviders(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(200, ['connection_id' => 'grant_1', 'provider_key' => 'google-calendar', 'status' => 'reconnect_required']),
            new \GuzzleHttp\Psr7\Response(204),
        ]);
        (new Calendar(Clients::client($transport)))->disconnect('t1', 'grant_1', 'dc-key-2', 'sub-stored');

        $post = null;
        foreach ($transport->requests as $i => $request) {
            [$path] = self::split($transport, $i);
            self::assertNotSame('/v1/calendar/providers', $path, 'an explicit providerAccountId must not list providers');
            if ($request->getMethod() === 'POST') {
                $post = $i;
            }
        }
        self::assertCount(2, $transport->requests);
        self::assertNotNull($post);
        self::assertSame('/v1/connections/grant_1/disconnect', $transport->target($post));
        $body = self::body($transport, $post);
        self::assertSame('google-calendar', $body['provider_key']);
        self::assertSame('sub-stored', $body['provider_account_id']);
        self::assertSame('dc-key-2', $body['idempotency_key']);
        self::assertSame('t1', $body['tenant_id']);
    }

    public function testRateLimitedIsARuntimeProblem(): void
    {
        $transport = new ScriptedTransport([
            Clients::json(429, ['type' => 'about:blank', 'title' => 'Too many', 'status' => 429, 'code' => 'calendar.rate_limited'], ['Retry-After' => '3']),
        ]);
        try {
            (new Calendar(Clients::client($transport)))->calendars('t1', 'conn_1');
            self::fail('expected RuntimeProblemException');
        } catch (RuntimeProblemException $e) {
            self::assertSame('calendar.rate_limited', $e->problemCode);
            self::assertSame(429, $e->httpStatus);
            self::assertSame(3, $e->retryAfterSeconds);
        }
    }
}
