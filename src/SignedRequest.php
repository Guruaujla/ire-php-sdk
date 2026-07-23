<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * Result of {@see Signer::signRequest}: the five X-IR-* headers plus the
 * exact values that were signed. Mirrors the TypeScript SDK's
 * `SignRequestResult`.
 */
final readonly class SignedRequest
{
    /**
     * @param array<string, string> $headers          the five X-IR-* headers to put on the wire
     * @param string                $timestamp        the unix-seconds timestamp string that was signed
     * @param string                $nonce            the nonce that was signed
     * @param string                $canonicalPayload the exact canonical payload string that was HMAC-signed
     */
    public function __construct(
        public array $headers,
        public string $timestamp,
        public string $nonce,
        public string $canonicalPayload,
    ) {}
}
