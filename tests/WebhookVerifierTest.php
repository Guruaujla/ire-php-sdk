<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\WebhookVerificationException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use Ezee\IntegrationRuntime\WebhookEvent;
use Ezee\IntegrationRuntime\WebhookVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookVerifier::class)]
#[CoversClass(WebhookEvent::class)]
final class WebhookVerifierTest extends TestCase
{
    /** @return array<string, string> */
    private static function fixture(): array
    {
        // Signed by the runtime's real outbound signer; pinned by
        // test/sdk/outbound-webhook-fixture.test.ts.
        $raw = Clients::decodeFile(dirname(__DIR__) . '/fixtures/outbound-webhook.json');
        $fixture = [];
        foreach (['secret', 'timestamp', 'nonce', 'signature', 'key_id', 'app_id', 'body'] as $key) {
            $fixture[$key] = Clients::str($raw, $key);
        }

        return $fixture;
    }

    /**
     * @param array<string, string> $f
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private static function headers(array $f, array $override = []): array
    {
        return [...[
            'x-ir-timestamp' => $f['timestamp'],
            'X-IR-Nonce' => $f['nonce'],
            'X-IR-Signature' => $f['signature'],
            'X-IR-Key-Id' => $f['key_id'],
            'X-IR-App-Id' => $f['app_id'],
        ], ...$override];
    }

    private static function verifier(int $now): WebhookVerifier
    {
        return new WebhookVerifier(self::fixture()['secret'], 300, static fn(): int => $now);
    }

    public function testVerifiesARuntimeSignedDelivery(): void
    {
        $f = self::fixture();
        $event = self::verifier((int) $f['timestamp'] + 10)->verify('POST', '/integration-runtime/webhooks', self::headers($f), $f['body']);

        self::assertSame('0190d3c4-8e21-7f3a-9b64-2c1f5a8e4d07', $event->deliveryId);
        self::assertSame('sync_job.completed', $event->eventType);
        self::assertSame('tnt_9f21c4', $event->tenantId);
        self::assertSame('quickbooks.invoice.create', $event->operationKey());
        self::assertSame('1042', $event->externalObjectId());
        self::assertSame('0190d3b7-1a02-7c55-8e91-6d3b0f2a91cc', $event->jobId());
    }

    public function testParsesTheNeutralAccountingDocumentEvent(): void
    {
        $body = [
            'schema_version' => 1, 'delivery_id' => 'd1', 'event_id' => 'e1:accounting.document.status_changed',
            'event_type' => 'accounting.document.status_changed', 'occurred_at' => '2026-10-28T10:00:00.000Z',
            'app_id' => 'app_erp', 'tenant_id' => 't1', 'callback_epoch' => 1,
            'data' => ['connection_id' => 'c1', 'sequence' => 3, 'document' => ['document_type' => 'invoice', 'erp_id' => 'INV-1', 'balance' => '0.00']],
        ];
        $event = WebhookEvent::fromArray($body);
        self::assertSame(['document_type' => 'invoice', 'erp_id' => 'INV-1', 'balance' => '0.00'], $event->document);
        self::assertSame([], $event->job);
        self::assertNull($event->jobId());
        self::assertSame('c1', $event->connectionId());
        self::assertSame(3, $event->sequence());

        unset($body['data']['document']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('data.document');
        WebhookEvent::fromArray($body);
    }

    public function testASyncJobEventStillRequiresItsJob(): void
    {
        $this->expectExceptionMessage('data.sync_job');
        WebhookEvent::fromArray([
            'delivery_id' => 'd', 'event_id' => 'e', 'event_type' => 'sync_job.completed', 'occurred_at' => 'x',
            'app_id' => 'a', 'tenant_id' => 't', 'data' => ['document' => ['erp_id' => 'INV-1']],
        ]);
    }

    public function testParsesTheQuickBooksTransactionEvent(): void
    {
        $body = [
            'schema_version' => 1, 'delivery_id' => 'd2', 'event_id' => 'e2:quickbooks.transaction.status_changed',
            'event_type' => 'quickbooks.transaction.status_changed', 'occurred_at' => '2026-10-28T10:00:00.000Z',
            'app_id' => 'app_erp', 'tenant_id' => 't1', 'callback_epoch' => 1,
            'data' => ['transaction' => ['connection_id' => 'c1', 'entity_type' => 'Invoice', 'external_id' => '42']],
        ];
        $event = WebhookEvent::fromArray($body);
        self::assertSame(['connection_id' => 'c1', 'entity_type' => 'Invoice', 'external_id' => '42'], $event->transaction);
        self::assertSame([], $event->job);
        self::assertSame([], $event->document);

        unset($body['data']['transaction']);
        $this->expectExceptionMessage('data.transaction');
        WebhookEvent::fromArray($body);
    }

    public function testAcceptsAnEventTypeThisVersionDoesNotKnow(): void
    {
        $event = WebhookEvent::fromArray([
            'delivery_id' => 'd3', 'event_id' => 'e3', 'event_type' => 'connection.status_changed', 'occurred_at' => 'x',
            'app_id' => 'a', 'tenant_id' => 't', 'data' => ['connection' => ['id' => 'c1']],
        ]);
        self::assertSame('connection.status_changed', $event->eventType);
        self::assertSame([], $event->job);
        $data = $event->payload['data'];
        self::assertIsArray($data);
        self::assertSame(['id' => 'c1'], $data['connection']);
    }

    public function testSelectsTheSecretByEndpointKeyDuringRotation(): void
    {
        $f = self::fixture();
        $verifier = new WebhookVerifier(['whk_old' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $f['key_id'] => $f['secret']], 300, static fn(): int => (int) $f['timestamp']);
        self::assertSame('sync_job.completed', $verifier->verify('POST', '/integration-runtime/webhooks', self::headers($f), $f['body'])->eventType);
    }

    /** @return iterable<string, array{string, string, array<string, string>, string, string}> */
    public static function tampered(): iterable
    {
        $f = self::fixture();
        $path = '/integration-runtime/webhooks';
        yield 'body changed' => ['POST', $path, [], str_replace('1042', '1043', $f['body']), 'signature_invalid'];
        yield 'reserialized body' => ['POST', $path, [], json_encode(json_decode($f['body'], true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 'signature_invalid'];
        yield 'decoded or different path' => ['POST', $path . '?x=1', [], $f['body'], 'signature_invalid'];
        yield 'nonce changed' => ['POST', $path, ['X-IR-Nonce' => $f['nonce'] . 'x'], $f['body'], 'signature_invalid'];
        yield 'stale' => ['POST', $path, ['x-ir-timestamp' => (string) ((int) $f['timestamp'] - 301)], $f['body'], 'timestamp_out_of_window'];
        yield 'missing signature' => ['POST', $path, ['X-IR-Signature' => ''], $f['body'], 'signature_headers_missing'];
        yield 'garbage signature' => ['POST', $path, ['X-IR-Signature' => 'not*base64'], $f['body'], 'signature_malformed'];
        yield 'unknown key' => ['POST', $path, ['X-IR-Key-Id' => 'whk_other'], $f['body'], 'signature_invalid'];
        yield 'GET' => ['GET', $path, [], $f['body'], 'method_not_post'];
        yield 'absolute URL target' => ['POST', 'https://erp.example.com' . $path, [], $f['body'], 'target_not_raw_path'];
    }

    /** @param array<string, string> $override */
    #[DataProvider('tampered')]
    public function testRejectsTamperedDeliveries(string $method, string $target, array $override, string $body, string $reason): void
    {
        $f = self::fixture();
        $verifier = new WebhookVerifier([$f['key_id'] => $f['secret'], 'whk_second' => $f['secret']], 300, static fn(): int => (int) $f['timestamp']);
        try {
            $verifier->verify($method, $target, self::headers($f, $override), $body);
            self::fail('accepted a tampered delivery');
        } catch (WebhookVerificationException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testRejectsAWrongSecret(): void
    {
        $f = self::fixture();
        $this->expectException(WebhookVerificationException::class);
        (new WebhookVerifier('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 300, static fn(): int => (int) $f['timestamp']))
            ->verify('POST', '/integration-runtime/webhooks', self::headers($f), $f['body']);
    }
}
