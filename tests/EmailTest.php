<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Email;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Email::class)]
final class EmailTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function operationStatus(string $status, string $messageStatus): array
    {
        return [
            'job_id' => 'job_1',
            'operation' => 'message.send',
            'message_ref' => 'msg_1',
            'external_ref' => 'inv-42-email',
            'provider_key' => 'google-mail',
            'status' => $status,
            'idempotent_replay' => false,
            'polling_url' => '/v1/email/operations/job_1?tenant_id=t1',
            'error' => null,
            'message' => ['status' => $messageStatus],
        ];
    }

    /** @return array<string, mixed> */
    private static function body(ScriptedTransport $transport, int $i): array
    {
        $decoded = json_decode((string) $transport->requests[$i]->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testProvidersSendsTenant(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['providers' => [], 'connections' => []])]);
        $out = (new Email(Clients::client($transport)))->providers('t1');

        self::assertSame([], $out['connections']);
        self::assertSame('GET', $transport->requests[0]->getMethod());
        self::assertSame('/v1/email/providers?tenant_id=t1', $transport->target(0));
    }

    public function testSendPostsNeutralBody(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, self::operationStatus('completed', 'submitted'))]);
        $message = [
            'external_ref' => 'inv-42-email',
            'to' => [['email' => 'customer@example.com', 'name' => 'Customer']],
            'subject' => 'Your invoice',
            'text' => 'Hello',
        ];
        $out = (new Email(Clients::client($transport)))->send('t1', 'conn_1', 'inv-42-email:1', $message);

        self::assertSame('completed', $out['status']);
        self::assertSame('POST', $transport->requests[0]->getMethod());
        self::assertSame('/v1/email/operations', $transport->target(0));
        self::assertSame([
            'operation' => 'message.send',
            'tenant_id' => 't1',
            'connection_id' => 'conn_1',
            'idempotency_key' => 'inv-42-email:1',
            'message' => $message,
        ], self::body($transport, 0));
    }

    public function testSendAcceptsAccepted(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('queued', 'sending'))]);
        $out = (new Email(Clients::client($transport)))->send('t1', 'conn_1', 'k', [
            'external_ref' => 'r',
            'subject' => 's',
            'text' => 't',
        ]);

        self::assertSame('queued', $out['status']);
    }

    public function testSendRequiresExternalRefAndSubject(): void
    {
        $email = new Email(Clients::client(new ScriptedTransport([])));
        try {
            $email->send('t1', 'conn_1', 'k', ['subject' => 's']);
            self::fail('expected missing external_ref to throw');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $email->send('t1', 'conn_1', 'k', ['external_ref' => 'r']);
    }

    public function testOperationPollsJob(): void
    {
        $transport = new ScriptedTransport([Clients::json(202, self::operationStatus('processing', 'sending'))]);
        (new Email(Clients::client($transport)))->operation('t1', 'job/1');

        self::assertSame('/v1/email/operations/job%2F1?tenant_id=t1', $transport->target(0));
    }

    public function testSentReadsByExternalRef(): void
    {
        $transport = new ScriptedTransport([Clients::json(200, ['external_ref' => 'inv 42', 'status' => 'submitted'])]);
        $out = (new Email(Clients::client($transport)))->sent('t1', 'conn_1', 'inv 42');

        self::assertSame('submitted', $out['status']);
        self::assertSame('/v1/email/connections/conn_1/sent/inv%2042?tenant_id=t1', $transport->target(0));
    }
}
