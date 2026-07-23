<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\InvalidNonceException;
use Ezee\IntegrationRuntime\Exception\InvalidSecretException;

/**
 * HMAC request-signing core for the Integration Runtime /v1 API.
 *
 * This is the PRODUCING counterpart to the runtime's verifying side. It
 * mirrors `src/security/hmac.ts#computeCanonicalPayload` BYTE-FOR-BYTE and
 * reproduces the base64url-no-pad HMAC-SHA256 signature that
 * `src/middleware/service-auth.ts` recomputes and constant-time-compares.
 * It is the PHP twin of `sdks/typescript/src/signing.ts`.
 *
 * Canonical payload format (CURRENT-STATE §4.2 / src/security/hmac.ts):
 *   v1
 *   METHOD                    upper-cased
 *   PATH_WITH_QUERY           pathname + search (leading slash, encoded query)
 *   TIMESTAMP                 unix seconds (string, exactly as sent in header)
 *   NONCE                     string length in [22, 64]
 *   SHA256_BODY_HASH          lowercase hex of SHA-256 over the RAW body bytes
 * Newline-joined with "\n", NO trailing newline.
 *
 * Pure PHP, zero runtime dependencies: `hash`/`hash_hmac`/`random_bytes` only
 * (all bundled since PHP 7). `sodium_memzero` is used opportunistically when
 * the sodium extension is loaded.
 */
final class Signer
{
    /**
     * Service-auth nonce bounds — the runtime accepts a nonce whose STRING
     * length is in [22, 64] (src/middleware/service-auth.ts
     * NONCE_MIN_LEN/NONCE_MAX_LEN). Lengths here are BYTE lengths; nonces are
     * expected to be ASCII (the default is base64url), where byte length and
     * string length coincide.
     */
    public const NONCE_MIN_LEN = 22;

    public const NONCE_MAX_LEN = 64;

    /**
     * 24 random bytes base64url-encode to exactly 32 chars (192 bits of
     * entropy) — safely inside the [22, 64] window with margin on both ends.
     */
    private const NONCE_BYTES = 24;

    /** Static-only class. */
    private function __construct() {}

    /**
     * Reconstruct raw HMAC key bytes from the base64url secret string.
     *
     * Mirrors `decodeServiceKeySecret` in the runtime and `decodeSecret` in
     * the TypeScript SDK so all sides share ONE encoding contract: the
     * base64url string the service-keys console shows once at key creation
     * decodes to the same raw key the runtime envelope-decrypts at verify
     * time. Trailing "=" padding is tolerated (the console emits none).
     *
     * @return string raw key bytes
     *
     * @throws InvalidSecretException on empty input or invalid base64url
     */
    public static function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        $trimmed = rtrim($secret, '=');
        if ($trimmed === '') {
            throw new InvalidSecretException('service-key secret must not be empty');
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/', $trimmed) !== 1) {
            throw new InvalidSecretException(
                'service-key secret is not valid base64url (unexpected characters)',
            );
        }
        $b64 = strtr($trimmed, '-_', '+/');
        $rem = strlen($b64) % 4;
        if ($rem === 1) {
            // A base64 payload can never be ≡1 (mod 4) chars; atob throws on
            // this too. Catch it before base64_decode pads it into nonsense.
            throw new InvalidSecretException('service-key secret is not valid base64url (invalid length)');
        }
        if ($rem !== 0) {
            $b64 .= str_repeat('=', 4 - $rem);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new InvalidSecretException('service-key secret is not valid base64url');
        }

        return $raw;
    }

    /** SHA-256 → lowercase hex. Matches src/security/hmac.ts#sha256Hex. */
    public static function sha256Hex(string $bytes): string
    {
        // PHP's hash() already emits lowercase hex — correct as-is.
        return hash('sha256', $bytes);
    }

    /** Raw bytes → base64url without padding (the signature encoding). */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Build the canonical payload string. Byte-for-byte identical to the
     * runtime's `computeCanonicalPayload` for the same inputs — the whole
     * point of the SDK. `$bodyBytes` is the RAW body exactly as sent on the
     * wire (empty string for GET/HEAD/DELETE — see Client).
     */
    public static function computeCanonicalPayload(
        string $method,
        string $pathWithQuery,
        string $timestamp,
        string $nonce,
        string $bodyBytes,
    ): string {
        // strtoupper is ASCII-only/locale-independent on PHP >= 8.2, matching
        // JS String.prototype.toUpperCase for the ASCII method names.
        return implode("\n", [
            'v1',
            strtoupper($method),
            $pathWithQuery,
            $timestamp,
            $nonce,
            self::sha256Hex($bodyBytes),
        ]);
    }

    /** Generate a fresh base64url nonce (32 chars) within the runtime's bounds. */
    public static function generateNonce(): string
    {
        return self::base64UrlEncode(random_bytes(self::NONCE_BYTES));
    }

    /** Current unix-seconds timestamp as a string. */
    public static function currentTimestamp(): string
    {
        return (string) time();
    }

    /**
     * Sign one request. Pure with respect to its inputs (given a fixed
     * timestamp + nonce it is deterministic), so it is directly
     * cross-checkable against the runtime's signing code and the shared
     * test vectors. The secret is decoded, used, and not retained.
     *
     * @param string      $appId         product backend's app id — sent as X-IR-App-Id
     * @param string      $keyId         service key id — sent as X-IR-Key-Id
     * @param string      $secret        the base64url secret string from the service-keys console (shown once)
     * @param string      $method        HTTP method; upper-cased into the payload
     * @param string      $pathWithQuery pathname + encoded query with leading slash, e.g. "/v1/sync-jobs?status=running"
     * @param string      $bodyBytes     RAW body bytes to hash — sign the exact bytes you send ('' for GET/HEAD/DELETE)
     * @param string|null $timestamp     override the unix-seconds timestamp string (defaults to now)
     * @param string|null $nonce         override the nonce (defaults to a fresh random base64url nonce)
     *
     * @throws InvalidNonceException  when a supplied nonce is outside [22, 64]
     * @throws InvalidSecretException when the secret is not valid base64url
     */
    public static function signRequest(
        string $appId,
        string $keyId,
        #[\SensitiveParameter]
        string $secret,
        string $method,
        string $pathWithQuery,
        string $bodyBytes = '',
        ?string $timestamp = null,
        ?string $nonce = null,
    ): SignedRequest {
        $timestamp ??= self::currentTimestamp();
        $nonce ??= self::generateNonce();
        $nonceLen = strlen($nonce);
        if ($nonceLen < self::NONCE_MIN_LEN || $nonceLen > self::NONCE_MAX_LEN) {
            throw new InvalidNonceException(sprintf(
                "nonce length %d is outside the runtime's [%d, %d] bound",
                $nonceLen,
                self::NONCE_MIN_LEN,
                self::NONCE_MAX_LEN,
            ));
        }

        $canonicalPayload = self::computeCanonicalPayload(
            $method,
            $pathWithQuery,
            $timestamp,
            $nonce,
            $bodyBytes,
        );

        $secretBytes = self::decodeSecret($secret);
        // RAW binary HMAC output (4th arg true), then base64url — the default
        // hex output would be wrong.
        $signature = self::base64UrlEncode(hash_hmac('sha256', $canonicalPayload, $secretBytes, true));

        // Best-effort scrub of the decoded key material. $secretBytes is the
        // LAST reference to a fresh base64_decode string, which matters:
        // ext-sodium separates shared buffers before zeroing, so scrubbing a
        // shared string would silently zero only a copy. sodium_memzero leaves
        // the variable NULL; without sodium the reassignment frees the old
        // string unzeroed — genuinely best-effort only (inlined rather than a
        // by-ref helper: PHPStan's paramOut rules can't type the transient
        // NULL on a by-ref parameter).
        if (function_exists('sodium_memzero')) {
            try {
                sodium_memzero($secretBytes);
            } catch (\SodiumException) {
                // best-effort — ignore
            }
        } else {
            $secretBytes = str_repeat("\0", strlen($secretBytes));
        }

        return new SignedRequest(
            headers: [
                'X-IR-App-Id' => $appId,
                'X-IR-Key-Id' => $keyId,
                'X-IR-Timestamp' => $timestamp,
                'X-IR-Nonce' => $nonce,
                'X-IR-Signature' => $signature,
            ],
            timestamp: $timestamp,
            nonce: $nonce,
            canonicalPayload: $canonicalPayload,
        );
    }
}
