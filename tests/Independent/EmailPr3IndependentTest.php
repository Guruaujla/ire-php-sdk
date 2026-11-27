<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests\Independent;

use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Email;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use Ezee\IntegrationRuntime\Tests\Support\TransportFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Clean-room tests for Email API PR 3 (written from the spec, not the diff). */
#[CoversClass(Email::class)]
#[CoversClass(Connections::class)]
final class EmailPr3IndependentTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function email(ScriptedTransport $transport): Email
    {
        $this->sleeps = [];

        return new Email(Clients::client($transport), function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @return array<string, mixed> */
    private static function op(string $status, mixed $error = null): array
    {
        return ['job_id' => 'job_1', 'status' => $status, 'error' => $error, 'message' => ['status' => 'sending']];
    }

    /** @return array<string, mixed> */
    private static function connection(string $id, string $provider, mixed $accountId = '__absent__'): array
    {
        $c = ['connection_id' => $id, 'tenant_id' => 't1', 'provider_key' => $provider, 'status' => 'connected'];
        if ($accountId !== '__absent__') {
            $c['provider_account_id'] = $accountId;
        }

        return $c;
    }

    private static function session(): \Psr\Http\Message\ResponseInterface
    {
        return Clients::json(201, ['session_id' => 's1', 'authorize_url' => 'https://accounts.example/auth', 'expires_at' => 1_900_000_000, 'connection_id' => 'conn_m']);
    }

    // ---- connect -------------------------------------------------------

    public function testConnectStartsAGoogleMailSessionWithPrincipalRef(): void
    {
        $t = new ScriptedTransport([self::session()]);
        $out = $this->email($t)->connect('t1', 'user-7', 'https://app.example/back', 'st-1');

        self::assertSame('conn_m', $out['connection_id']);
        self::assertSame('https://accounts.example/auth', $out['authorize_url']);
        self::assertSame('POST', $t->requests[0]->getMethod());
        self::assertSame('/v1/connections/connect-session', $t->target(0));
        $body = $t->body(0);
        self::assertSame('google-mail', $body['provider_key']);
        self::assertSame('user-7', $body['principal_ref']);
        self::assertSame('t1', $body['tenant_id']);
        self::assertSame('https://app.example/back', $body['return_url']);
        self::assertSame('st-1', $body['state']);
        self::assertArrayNotHasKey('principal_label', $body);
    }

    public function testConnectPassesPrincipalLabel(): void
    {
        $t = new ScriptedTransport([self::session()]);
        $this->email($t)->connect('t1', 'user-7', 'https://app.example/back', 'st-1', 'google-mail', 'Ada');
        self::assertSame('Ada', $t->body(0)['principal_label']);
    }

    public function testConnectRefusesBlankPrincipalRefBeforeSending(): void
    {
        $t = new ScriptedTransport([]);
        try {
            $this->email($t)->connect('t1', '  ', 'https://app.example/back', 'st-1');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->requests);
    }

    // ---- Connections google-mail ----------------------------------------

    public function testGoogleMailIsAKnownPerUserProvider(): void
    {
        self::assertContains('google-mail', Connections::PROVIDER_KEYS);
        self::assertContains('google-mail', Connections::PER_USER_PROVIDER_KEYS);
    }

    public function testStartRequiresPrincipalRefForGoogleMail(): void
    {
        $t = new ScriptedTransport([]);
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new Connections(Clients::client($t)))->start('t1', 'https://app.example/back', 'st', 'google-mail');
        } finally {
            self::assertSame([], $t->requests);
        }
    }

    public function testStartAcceptsGoogleMailWithPrincipalRef(): void
    {
        $t = new ScriptedTransport([self::session()]);
        (new Connections(Clients::client($t)))->start('t1', 'https://app.example/back', 'st', 'google-mail', 'p1');
        self::assertSame('google-mail', $t->body(0)['provider_key']);
        self::assertSame('p1', $t->body(0)['principal_ref']);
    }

    public function testReconnectRefusesGoogleMail(): void
    {
        $t = new ScriptedTransport([Clients::json(200, self::connection('conn_m', 'google-mail', 'acct-g'))]);
        try {
            (new Connections(Clients::client($t)))->reconnect('t1', 'conn_m', 'https://app.example/back', 'st');
            self::fail('expected refusal');
        } catch (\InvalidArgumentException) {
        }
        self::assertCount(1, $t->requests, 'no connect-session may be started');
    }

    // ---- disconnect ------------------------------------------------------

    public function testEmailDisconnectUsesProviderAccountIdFromTheConnectionRead(): void
    {
        $t = new ScriptedTransport([
            Clients::json(200, self::connection('conn_m', 'google-mail', 'google-sub-1')),
            Clients::json(204, null),
        ]);
        $out = $this->email($t)->disconnect('t1', 'conn_m', 'dc-1');

        self::assertSame(['provider_cleanup' => null], $out);
        self::assertCount(2, $t->requests, 'no extra provider-list request');
        self::assertSame('/v1/connections/conn_m?tenant_id=t1', $t->target(0));
        self::assertSame('/v1/connections/conn_m/disconnect', $t->target(1));
        self::assertSame([
            'tenant_id' => 't1',
            'provider_key' => 'google-mail',
            'provider_account_id' => 'google-sub-1',
            'idempotency_key' => 'dc-1',
        ], $t->body(1));
    }

    public function testExplicitProviderAccountIdWinsOverTheRead(): void
    {
        $t = new ScriptedTransport([
            Clients::json(200, self::connection('conn_m', 'google-mail', 'google-sub-1')),
            Clients::json(204, null),
        ]);
        $this->email($t)->disconnect('t1', 'conn_m', 'dc-1', 'stored-acct');
        self::assertCount(2, $t->requests);
        self::assertSame('stored-acct', $t->body(1)['provider_account_id']);
    }

    public function testAccountingDisconnectAlsoUsesTheReadAccountId(): void
    {
        $t = new ScriptedTransport([
            Clients::json(200, self::connection('conn_q', 'quickbooks', 'realm-9')),
            Clients::json(204, null),
        ]);
        (new Connections(Clients::client($t)))->disconnect('t1', 'conn_q', 'dc-2');
        self::assertCount(2, $t->requests);
        self::assertSame('realm-9', $t->body(1)['provider_account_id']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableReadAccountIds(): iterable
    {
        yield 'absent' => ['__absent__'];
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'number' => [123];
    }

    #[DataProvider('unusableReadAccountIds')]
    public function testPerUserFallbackReadsTheCalendarProviders(mixed $accountId): void
    {
        $t = new ScriptedTransport([
            Clients::json(200, self::connection('conn_m', 'google-mail', $accountId)),
            Clients::json(200, ['providers' => [], 'connections' => [['connection_id' => 'conn_m', 'provider_account_id' => 'from-list']]]),
            Clients::json(204, null),
        ]);
        $this->email($t)->disconnect('t1', 'conn_m', 'dc-3');
        self::assertCount(3, $t->requests);
        self::assertStringStartsWith('/v1/calendar/providers', $t->target(1));
        self::assertSame('from-list', $t->body(2)['provider_account_id']);
    }

    #[DataProvider('unusableReadAccountIds')]
    public function testAccountingFallbackReadsTheAccountingProviders(mixed $accountId): void
    {
        $t = new ScriptedTransport([
            Clients::json(200, self::connection('conn_x', 'xero', $accountId)),
            Clients::json(200, ['connection' => ['connection_id' => 'conn_x', 'provider_account_id' => 'xero-t']]),
            Clients::json(204, null),
        ]);
        (new Connections(Clients::client($t)))->disconnect('t1', 'conn_x', 'dc-4');
        self::assertCount(3, $t->requests);
        self::assertSame('/v1/accounting/providers?tenant_id=t1', $t->target(1));
        self::assertSame('xero-t', $t->body(2)['provider_account_id']);
    }

    // ---- send ------------------------------------------------------------

    public function testSendWithNullIdempotencyKeyUsesExternalRef(): void
    {
        $t = new ScriptedTransport([Clients::json(202, self::op('queued'))]);
        $this->email($t)->send('t1', 'conn_m', null, ['external_ref' => 'inv-9', 'subject' => 's', 'text' => 'x', 'to' => [['email' => 'a@example.com']]]);
        self::assertSame('inv-9', $t->body(0)['idempotency_key']);
        self::assertSame('message.send', $t->body(0)['operation']);
    }

    public function testSendWithExplicitKeyKeepsIt(): void
    {
        $t = new ScriptedTransport([Clients::json(200, self::op('completed'))]);
        $this->email($t)->send('t1', 'conn_m', 'my-key', ['external_ref' => 'inv-9', 'subject' => 's']);
        self::assertSame('my-key', $t->body(0)['idempotency_key']);
    }

    public function testSendNullKeyAndMissingExternalRefRefusesBeforeSending(): void
    {
        $t = new ScriptedTransport([]);
        try {
            $this->email($t)->send('t1', 'conn_m', null, ['subject' => 's']);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->requests);
    }

    // ---- waitFor ---------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function terminal(): iterable
    {
        foreach (['completed', 'failed', 'dead_lettered', 'cancelled', 'manually_resolved'] as $s) {
            yield $s => [$s];
        }
    }

    #[DataProvider('terminal')]
    public function testWaitForStopsAtEachTerminalStatus(string $status): void
    {
        $t = new ScriptedTransport([
            Clients::json(202, self::op('queued')),
            Clients::json(202, self::op('processing')),
            Clients::json(200, self::op($status)),
        ]);
        $out = $this->email($t)->waitFor('t1', 'job_1', 600, 0.5);
        self::assertSame($status, $out['status']);
        self::assertCount(3, $t->requests);
        foreach ([0, 1, 2] as $i) {
            self::assertSame('GET', $t->requests[$i]->getMethod());
            self::assertSame('/v1/email/operations/job_1?tenant_id=t1', $t->target($i));
        }
        self::assertCount(2, $this->sleeps);
    }

    public function testWaitForWaitsOut429And5xxAndNetworkErrors(): void
    {
        $t = new ScriptedTransport([
            Clients::json(429, ['code' => 'email.rate_limited'], ['Retry-After' => '7']),
            Clients::json(503, ['code' => 'email.state_unavailable']),
            Clients::json(500, ['code' => 'internal']),
            new TransportFailure('connection refused'),
            Clients::json(200, self::op('completed')),
        ]);
        $out = $this->email($t)->waitFor('t1', 'job_1', 600, 0.5);
        self::assertSame('completed', $out['status']);
        self::assertCount(5, $t->requests);
        self::assertCount(4, $this->sleeps);
        self::assertGreaterThanOrEqual(7.0, $this->sleeps[0], 'Retry-After is honoured');
    }

    /** @return iterable<string, array{int}> */
    public static function deterministic4xx(): iterable
    {
        yield '400' => [400];
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '409' => [409];
        yield '422' => [422];
    }

    #[DataProvider('deterministic4xx')]
    public function testWaitForThrowsOtherClientErrorsAtOnce(int $status): void
    {
        $t = new ScriptedTransport([
            Clients::json(202, self::op('queued')),
            Clients::json($status, ['code' => 'email.operation_not_found']),
        ]);
        try {
            $this->email($t)->waitFor('t1', 'job_1', 600, 0.5);
            self::fail('expected RuntimeProblemException');
        } catch (RuntimeProblemException $e) {
            self::assertSame($status, $e->httpStatus);
        }
        self::assertCount(2, $t->requests);
    }

    public function testWaitForReturnsTheLastReadOnTimeout(): void
    {
        // timeout 1s, interval 0.5 -> delays 0.5, 0.75 fit; 1.125 does not: 3 polls.
        $t = new ScriptedTransport([
            Clients::json(202, self::op('queued')),
            Clients::json(202, self::op('processing')),
            Clients::json(202, ['job_id' => 'job_1', 'status' => 'processing', 'error' => null, 'marker' => 'last']),
        ]);
        $out = $this->email($t)->waitFor('t1', 'job_1', 1, 0.5);
        self::assertSame('last', $out['marker'] ?? null);
        self::assertFalse(Email::isFinished($out));
    }

    public function testWaitForReturnsTheLastSuccessfulReadWhenATransientErrorHitsTheDeadline(): void
    {
        $t = new ScriptedTransport([
            Clients::json(202, ['job_id' => 'job_1', 'status' => 'processing', 'marker' => 'good']),
            Clients::json(503, ['code' => 'email.state_unavailable']),
            Clients::json(503, ['code' => 'email.state_unavailable']),
        ]);
        $out = $this->email($t)->waitFor('t1', 'job_1', 1, 0.5);
        self::assertSame('good', $out['marker'] ?? null);
    }

    public function testWaitForThrowsTheTransientErrorWhenNoReadSucceeded(): void
    {
        $t = new ScriptedTransport([Clients::json(503, ['code' => 'email.state_unavailable'])]);
        try {
            $this->email($t)->waitFor('t1', 'job_1', 0, 0.5);
            self::fail('expected RuntimeProblemException');
        } catch (RuntimeProblemException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertSame('email.state_unavailable', $e->problemCode);
        }

        $n = new ScriptedTransport([new TransportFailure('timeout')]);
        $this->expectException(TransportFailure::class);
        $this->email($n)->waitFor('t1', 'job_1', 0, 0.5);
    }

    public function testWaitForValidatesArgumentsBeforeSending(): void
    {
        $t = new ScriptedTransport([]);
        foreach ([['', 'job_1'], ['t1', '']] as [$tenant, $job]) {
            try {
                $this->email($t)->waitFor($tenant, $job, 0);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $t->requests);
    }

    // ---- isFinished / decisionFor ----------------------------------------

    public function testIsFinished(): void
    {
        foreach (['completed', 'failed', 'dead_lettered', 'cancelled', 'manually_resolved'] as $s) {
            self::assertTrue(Email::isFinished(['status' => $s]), $s);
        }
        foreach (['queued', 'processing', 'running', 'retry_scheduled', 'COMPLETED', '', 'unknown'] as $s) {
            self::assertFalse(Email::isFinished(['status' => $s]), $s);
        }
        self::assertFalse(Email::isFinished([]));
        self::assertFalse(Email::isFinished(['status' => null]));
    }

    public function testDecisionForWithoutAnErrorArrayIsNull(): void
    {
        self::assertNull(Email::decisionFor([]));
        self::assertNull(Email::decisionFor(['error' => null]));
        self::assertNull(Email::decisionFor(['error' => 'email.send_uncertain']));
        self::assertNull(Email::decisionFor(['error' => 42]));
    }

    public function testDecisionForMissingOrEmptyCodeAsksTheOperator(): void
    {
        self::assertSame(RuntimeProblemException::ASK_OPERATOR, Email::decisionFor(['error' => []]));
        self::assertSame(RuntimeProblemException::ASK_OPERATOR, Email::decisionFor(['error' => ['message' => 'x']]));
        self::assertSame(RuntimeProblemException::ASK_OPERATOR, Email::decisionFor(['error' => ['code' => '']]));
        self::assertSame(RuntimeProblemException::ASK_OPERATOR, Email::decisionFor(['error' => ['code' => 7]]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function codes(): iterable
    {
        yield 'uncertain' => ['email.send_uncertain', RuntimeProblemException::REVIEW_AND_RESUBMIT];
        yield 'reconnect' => ['email.reconnect_required', RuntimeProblemException::FIX_SETUP];
        yield 'disabled' => ['email.operation_disabled', RuntimeProblemException::ASK_OPERATOR];
    }

    #[DataProvider('codes')]
    public function testDecisionForMapsTheCode(string $code, string $decision): void
    {
        self::assertSame($decision, Email::decisionFor(['status' => 'failed', 'error' => ['code' => $code, 'message' => 'm']]));
    }

    public function testDecisionForMatchesRuntimeProblemExceptionForEveryCode(): void
    {
        foreach (['email.provider_refused', 'email.insufficient_scopes', 'email.provider_unavailable', 'some.unknown_code'] as $code) {
            self::assertSame((new RuntimeProblemException(409, $code))->decision(), Email::decisionFor(['error' => ['code' => $code]]), $code);
        }
    }
}
