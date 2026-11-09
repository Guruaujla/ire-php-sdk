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

/**
 * Conformance against the published outbound webhook vectors
 * (openapi/test-vectors/outbound-webhook-signing.json). The file is GENERATED
 * from the runtime's own delivery code (tools/emit-outbound-webhook-vectors.ts)
 * and pinned by test/tools/emit-outbound-webhook-vectors.test.ts, so these are
 * the exact requests the runtime sends. Every vector and every receiver check
 * in the file is exercised; nothing is hardcoded here.
 */
#[CoversClass(WebhookVerifier::class)]
#[CoversClass(WebhookEvent::class)]
final class WebhookVerifierVectorsTest extends TestCase
{
    private const VECTORS_PATH = __DIR__ . '/../../../openapi/test-vectors/outbound-webhook-signing.json';

    /** @return array<string, mixed> */
    private static function file(): array
    {
        return Clients::decodeFile(self::VECTORS_PATH);
    }

    /** @return array<string, array<string, mixed>> name => vector */
    private static function vectorsByName(): array
    {
        $out = [];
        foreach (Clients::obj(['v' => self::file()['vectors'] ?? null], 'v') as $vector) {
            $vector = Clients::obj(['v' => $vector], 'v');
            $out[Clients::str($vector, 'name')] = $vector;
        }
        if ($out === []) {
            throw new \RuntimeException('outbound vectors file contains no vectors');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vector
     * @return array<string, string>
     */
    private static function headers(array $vector): array
    {
        $headers = [];
        foreach (Clients::obj($vector, 'headers') as $name => $value) {
            $headers[$name] = is_string($value) ? $value : '';
        }

        return $headers;
    }

    private static function verifier(int $now): WebhookVerifier
    {
        $file = self::file();
        $secret = Clients::str(Clients::obj($file, 'secret'), 'value');
        $window = $file['verifyWithinSeconds'] ?? null;
        if (! is_int($window)) {
            throw new \UnexpectedValueException('verifyWithinSeconds is not an integer');
        }

        // Keyed by the endpoint key, as an app receiving on several endpoints would.
        return new WebhookVerifier([Clients::str($file, 'keyId') => $secret], $window, static fn(): int => $now);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        foreach (self::vectorsByName() as $name => $vector) {
            yield $name => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('vectors')]
    public function testVerifiesThePublishedDelivery(array $vector): void
    {
        $body = Clients::str($vector, 'body');
        $headers = self::headers($vector);
        $event = self::verifier((int) Clients::str($vector, 'timestamp') + 10)->verify(
            Clients::str($vector, 'method'),
            Clients::str($vector, 'pathWithQuery'),
            $headers,
            $body,
        );

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame($decoded['delivery_id'], $event->deliveryId);
        self::assertSame($decoded['event_id'], $event->eventId);
        self::assertSame($decoded['event_type'], $event->eventType);
        self::assertSame($decoded['tenant_id'], $event->tenantId);
        self::assertSame($headers['X-IR-Event-Type'], $event->eventType);
        self::assertSame(hash('sha256', $body), Clients::str($vector, 'bodySha256'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function receiverChecks(): iterable
    {
        $checks = self::file()['receiverChecks'] ?? null;
        if (! is_array($checks) || $checks === []) {
            throw new \RuntimeException('outbound vectors file contains no receiver checks');
        }
        foreach ($checks as $check) {
            $check = Clients::obj(['c' => $check], 'c');
            yield Clients::str($check, 'name') => [$check];
        }
    }

    /** @param array<string, mixed> $check */
    #[DataProvider('receiverChecks')]
    public function testReceiverCheckHasTheDocumentedOutcome(array $check): void
    {
        $vector = self::vectorsByName()[Clients::str($check, 'vector')] ?? null;
        self::assertIsArray($vector, 'receiver check names a missing vector');
        $change = Clients::obj($check, 'change');

        $headers = self::headers($vector);
        foreach (isset($change['headers']) ? Clients::obj($change, 'headers') : [] as $name => $value) {
            $headers[$name] = is_string($value) ? $value : '';
        }
        $target = isset($change['pathWithQuery']) ? Clients::str($change, 'pathWithQuery') : Clients::str($vector, 'pathWithQuery');
        $body = isset($change['body']) ? Clients::str($change, 'body') : Clients::str($vector, 'body');
        $now = isset($change['receivedAt']) && is_int($change['receivedAt'])
            ? $change['receivedAt']
            : (int) Clients::str($vector, 'timestamp');

        $verifier = self::verifier($now);
        if (Clients::str($check, 'expect') === 'valid') {
            $event = $verifier->verify('POST', $target, $headers, $body);
            // Unsigned metadata headers never change what the body says.
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame($decoded['event_type'], $event->eventType);

            return;
        }
        try {
            $verifier->verify('POST', $target, $headers, $body);
            self::fail(Clients::str($check, 'name') . ' verified but must not');
        } catch (WebhookVerificationException $e) {
            self::assertContains($e->reason, ['signature_invalid', 'timestamp_out_of_window']);
        }
    }

    public function testRejectsEveryVectorUnderAWrongSecret(): void
    {
        $wrongSecret = rtrim(strtr(base64_encode(str_repeat("\xff", 32)), '+/', '-_'), '=');
        foreach (self::vectorsByName() as $name => $vector) {
            $now = (int) Clients::str($vector, 'timestamp');
            $wrong = new WebhookVerifier($wrongSecret, 300, static fn(): int => $now);
            try {
                $wrong->verify('POST', Clients::str($vector, 'pathWithQuery'), self::headers($vector), Clients::str($vector, 'body'));
                self::fail($name . ' verified under the wrong secret');
            } catch (WebhookVerificationException $e) {
                self::assertSame('signature_invalid', $e->reason, $name);
            }
        }
    }
}
