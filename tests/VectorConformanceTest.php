<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Signer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformance against the shared ADR-012 Phase-0 HMAC test vectors
 * (openapi/test-vectors/hmac-signing.json — the SAME file the runtime and the
 * TypeScript SDK verify against). Every vector in the file is exercised; the
 * list is NOT hardcoded here, so regenerated/extended vectors are picked up
 * automatically.
 *
 * @phpstan-type Vector array{
 *     name: string,
 *     method: string,
 *     pathWithQuery: string,
 *     timestamp: string,
 *     nonce: string,
 *     body: string,
 *     bodyHashedAsEmpty: bool,
 *     canonicalPayload: string,
 *     signature: string,
 * }
 */
#[CoversClass(Signer::class)]
final class VectorConformanceTest extends TestCase
{
    private const VECTORS_PATH = __DIR__ . '/../../../openapi/test-vectors/hmac-signing.json';

    /**
     * @param Vector $vector
     */
    #[DataProvider('vectors')]
    public function testVectorConformance(array $vector, string $secret): void
    {
        $bodyBytes = $vector['bodyHashedAsEmpty'] ? '' : $vector['body'];

        self::assertSame(
            $vector['canonicalPayload'],
            Signer::computeCanonicalPayload(
                $vector['method'],
                $vector['pathWithQuery'],
                $vector['timestamp'],
                $vector['nonce'],
                $bodyBytes,
            ),
            'computeCanonicalPayload must reproduce the vector canonical payload byte-for-byte',
        );

        $result = Signer::signRequest(
            appId: 'app_erp',
            keyId: 'key_primary',
            secret: $secret,
            method: $vector['method'],
            pathWithQuery: $vector['pathWithQuery'],
            bodyBytes: $bodyBytes,
            timestamp: $vector['timestamp'],
            nonce: $vector['nonce'],
        );

        self::assertSame(
            $vector['signature'],
            $result->headers['X-IR-Signature'],
            'signRequest must reproduce the vector signature',
        );
        self::assertSame($vector['canonicalPayload'], $result->canonicalPayload);

        // All five X-IR-* headers, each with its expected value — nothing else.
        self::assertSame(
            [
                'X-IR-App-Id' => 'app_erp',
                'X-IR-Key-Id' => 'key_primary',
                'X-IR-Timestamp' => $vector['timestamp'],
                'X-IR-Nonce' => $vector['nonce'],
                'X-IR-Signature' => $vector['signature'],
            ],
            $result->headers,
        );
    }

    /**
     * Guards against silent vector-file drift: the documented test secret must
     * decode to exactly bytes 0x00..0x1f. If this fails, the vectors file was
     * regenerated with a different secret and every signature above is suspect.
     */
    public function testVectorFileSecretDecodesToBytesZeroThroughThirtyOne(): void
    {
        $file = self::loadVectorFile();

        $expected = implode('', array_map(chr(...), range(0, 31)));
        self::assertSame($expected, Signer::decodeSecret($file['secret']));
    }

    /**
     * @return iterable<string, array{Vector, string}>
     */
    public static function vectors(): iterable
    {
        $file = self::loadVectorFile();
        if ($file['vectors'] === []) {
            throw new \RuntimeException('vectors file contains no vectors');
        }

        foreach ($file['vectors'] as $vector) {
            yield $vector['name'] => [$vector, $file['secret']];
        }
    }

    /**
     * @return array{secret: string, vectors: list<Vector>}
     */
    private static function loadVectorFile(): array
    {
        $raw = file_get_contents(self::VECTORS_PATH);
        if ($raw === false) {
            throw new \RuntimeException('unable to read vectors file: ' . self::VECTORS_PATH);
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('vectors file did not decode to a JSON object');
        }

        $secretNode = $decoded['secret'] ?? null;
        if (!is_array($secretNode) || !isset($secretNode['value']) || !is_string($secretNode['value'])) {
            throw new \RuntimeException('vectors file is missing a string secret.value');
        }

        $vectorsNode = $decoded['vectors'] ?? null;
        if (!is_array($vectorsNode)) {
            throw new \RuntimeException('vectors file is missing a vectors array');
        }

        $vectors = [];
        foreach ($vectorsNode as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('vector entry is not a JSON object');
            }
            $vectors[] = [
                'name' => self::stringField($entry, 'name'),
                'method' => self::stringField($entry, 'method'),
                'pathWithQuery' => self::stringField($entry, 'pathWithQuery'),
                'timestamp' => self::stringField($entry, 'timestamp'),
                'nonce' => self::stringField($entry, 'nonce'),
                'body' => self::stringField($entry, 'body'),
                'bodyHashedAsEmpty' => self::boolField($entry, 'bodyHashedAsEmpty'),
                'canonicalPayload' => self::stringField($entry, 'canonicalPayload'),
                'signature' => self::stringField($entry, 'signature'),
            ];
        }

        return ['secret' => $secretNode['value'], 'vectors' => $vectors];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function stringField(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('vector field "%s" is missing or not a string', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function boolField(array $entry, string $key): bool
    {
        $value = $entry[$key] ?? null;
        if (!is_bool($value)) {
            throw new \RuntimeException(sprintf('vector field "%s" is missing or not a bool', $key));
        }

        return $value;
    }
}
