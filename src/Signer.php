<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\InvalidMethodException;
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
 *   METHOD                    an HTTP_METHODS constant (see canonicalizeMethod)
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
     * The closed set of HTTP methods this SDK will sign — RFC 9110 §9.3's
     * standard methods plus PATCH (RFC 5789).
     *
     * The canonical payload embeds the method TEXTUALLY, so all four
     * implementations (this SDK, the TypeScript and Python SDKs, and the
     * runtime's verifier) must agree on its spelling for EVERY input, not just
     * the ones a well-behaved caller sends. Case mapping is the trap: this
     * SDK's `strtoupper` is ASCII-only, while JS `String.prototype.toUpperCase`
     * and Python's `str.upper()` are Unicode-aware. So "optıons" (with U+0131
     * DOTLESS I) upper-cases to "OPTIONS" there and stays "OPTıONS" here: one
     * input, two canonical payloads, and a uniform 401 nobody can diagnose.
     * That was the single defect behind all 126 Python↔PHP fuzz mismatches in
     * issue #139 — invisible to review, found only by measurement.
     *
     * Matching the casing functions to each other is not a reachable target,
     * and this is the part a future reader is most likely to undo: Unicode case
     * mapping is a property of each RUNTIME's Unicode table, not of our code. A
     * sweep of 0..0x10FFFF found 95 code points where Node 26 (Unicode 17.0)
     * and Python 3.9 (Unicode 13.0) already disagree with each other — so
     * "Python matched the verifier" was luck on one sample, not a property, and
     * any "just make the casing agree" fix would drift apart again on the next
     * interpreter or Node upgrade, in three runtimes independently.
     *
     * The fix therefore removes the dependency on case mapping instead of
     * chasing it: ASCII-upper-case the input, look the result up in this
     * allowlist, and write the MATCHED CONSTANT into the payload (see
     * {@see canonicalizeMethod}). Every accepted input therefore signs a
     * literal from this array — no locale and no Unicode table can reach it.
     *
     * Restricting to this set is not the SDK enforcing the runtime's route
     * table: the runtime answers 404/405 for a method it does not route, which
     * is a diagnosable outcome, unlike the uniform 401 a mis-canonicalized
     * method produces.
     *
     * @var list<string>
     */
    public const HTTP_METHODS = [
        'CONNECT',
        'DELETE',
        'GET',
        'HEAD',
        'OPTIONS',
        'PATCH',
        'POST',
        'PUT',
        'TRACE',
    ];

    /**
     * 24 random bytes base64url-encode to exactly 32 chars (192 bits of
     * entropy) — safely inside the [22, 64] window with margin on both ends.
     */
    private const NONCE_BYTES = 24;

    /**
     * ASCII whitespace, spelled out as explicit characters rather than via a
     * PCRE class or a bare `trim()`: PCRE's `\v` also matches U+0085 and the
     * TypeScript/Python SDKs carry this same six-character set, so an input
     * lands in the same branch — and gets the same message — in all three.
     */
    private const ASCII_WHITESPACE = " \t\n\v\f\r";

    /**
     * Leading/trailing "HTTP whitespace" — SP, HT, LF, CR (issue #150).
     *
     * A nonce IS part of the canonical payload, and it travels as an HTTP
     * header value. A header value is not the string you handed the headers
     * API; it is what that API stores after NORMALISING it. WHATWG `Headers`
     * (and any spec-conforming client) strips these four characters from both
     * ends — so a nonce with a trailing newline is signed WITH it and sent
     * WITHOUT it, and the verifier hashes a different string than was signed.
     * Result: a guaranteed 401 with nothing in any log naming the cause. The
     * realistic shape is a nonce read out of a file or an env var.
     *
     * Deliberately NARROWER than {@see ASCII_WHITESPACE}, which also carries VT
     * and FF: those two are NOT stripped (measured on Node 26 — a leading or
     * trailing VT/FF round-trips verbatim), so folding them in here would make
     * this guard's message, which tells the caller their character is stripped
     * after signing, a lie about them. They are rejected by the visible-ASCII
     * guard instead, with the message that fits. Do not "simplify" this by
     * reusing ASCII_WHITESPACE.
     */
    private const HTTP_WHITESPACE_EDGES_RE = '/\A[ \t\n\r]|[ \t\n\r]\z/';

    /**
     * Visible ASCII, U+0021..U+007E. Anything outside it either cannot survive
     * header encoding intact or is not a value a header should carry.
     *
     * Byte-oriented on purpose (no `/u`): a UTF-8 multibyte sequence has bytes
     * >= 0x80, so it fails here exactly as the TypeScript and Python SDKs'
     * equivalents fail on the code-unit / code-point reading.
     */
    private const VISIBLE_ASCII_RE = '/\A[\x21-\x7e]+\z/';

    /**
     * The runtime parses X-IR-Timestamp with exactly this regex before it even
     * reaches the clock-window check (src/middleware/service-auth.ts step 1).
     * Matching it here turns a non-canonical timestamp into a local error
     * instead of a uniform, reason-free 401.
     *
     * No `/u` and no `(*UCP)`, so `\d` is ASCII `[0-9]` — the same set JS `\d`
     * matches in the verifier's own regex.
     */
    private const TIMESTAMP_RE = '/\A\d{1,15}\z/';

    /** Static-only class. */
    private function __construct() {}

    /**
     * Length of $value in UTF-16 code units — the unit the RUNTIME measures the
     * nonce bound in (`nonce.length` in src/middleware/service-auth.ts, which
     * is a JS string length).
     *
     * This SDK previously used `strlen`, i.e. BYTES, and over-accepted: a nonce
     * of eleven U+4E2D characters is 33 bytes but only 11 UTF-16 units, so PHP
     * signed a nonce that TypeScript, Python AND the runtime all reject (issue
     * #151). Python's `len` counts CODE POINTS and over-rejected in the other
     * direction. Both were corrected TO this unit, not the reverse: the runtime
     * is the authority, and that direction is the detail most likely to be
     * implemented backwards from a summary line.
     *
     * Implemented over UTF-8 bytes rather than via mbstring/iconv so it needs
     * no extension (this package requires none): every non-continuation byte
     * starts one code point, and a 4-byte sequence (lead byte >= 0xF0, i.e. a
     * code point above U+FFFF) becomes a UTF-16 SURROGATE PAIR — two units.
     * Total by construction: an invalid byte counts as one unit rather than
     * throwing, which keeps the following visible-ASCII guard the thing that
     * rejects it.
     */
    private static function utf16Length(string $value): int
    {
        $units = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if (($byte & 0xC0) === 0x80) {
                continue; // UTF-8 continuation byte — part of the code point already counted.
            }
            $units += $byte >= 0xF0 ? 2 : 1;
        }

        return $units;
    }

    /**
     * Canonicalize an HTTP method to the exact constant that goes into the
     * canonical payload.
     *
     * Public because {@see Client} needs the SAME canonical form to pick its
     * no-body methods (GET/HEAD/DELETE); calling `strtoupper` there instead
     * would let the client and the signer disagree about what a method means.
     *
     * @throws InvalidMethodException when $method is not in {@see HTTP_METHODS}
     */
    public static function canonicalizeMethod(string $method): string
    {
        // strtoupper is ASCII-only and locale-independent on PHP >= 8.2 (this
        // package's floor), so it is a correct ASCII-upper step. What makes
        // the result SAFE, though, is the lookup below: the value returned is
        // the allowlist constant, never the output of the casing function.
        $index = array_search(strtoupper($method), self::HTTP_METHODS, true);
        if ($index === false) {
            throw new InvalidMethodException(sprintf(
                'method "%s" is not a supported HTTP method (expected one of: %s)',
                $method,
                implode(', ', self::HTTP_METHODS),
            ));
        }

        return self::HTTP_METHODS[$index];
    }

    /**
     * Reconstruct raw HMAC key bytes from the base64url secret string.
     *
     * Mirrors `decodeServiceKeySecret` in the runtime and `decodeSecret` in
     * the TypeScript SDK so all sides share ONE encoding contract: the
     * base64url string the service-keys console shows once at key creation
     * decodes to the same raw key the runtime envelope-decrypts at verify
     * time. Trailing "=" padding is tolerated (the console emits none).
     *
     * Every rejection here is a LOCAL error at credential-loading time, named
     * after the actual problem. That is the point (issue #138): an unset
     * IR_SERVICE_KEY_SECRET is the single most likely way a product backend
     * misconfigures the SDK, and the verifier answers a uniform 401 by design —
     * it can never tell the caller their secret was blank, but this function
     * can. The checks and their exact messages are shared with `decodeSecret`
     * (TypeScript) and `decode_secret` (Python); this side was already correct,
     * and TypeScript was aligned TO it rather than the other way round.
     *
     * Padding is tolerated deliberately (leniency in the forgiving direction):
     * the console emits a 43-char, never-padded secret, so a padded spelling
     * only arises from a config system that added one — and it still derives
     * the same key.
     *
     * @return string raw key bytes
     *
     * @throws InvalidSecretException on empty input or invalid base64url
     */
    public static function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        $trimmed = rtrim($secret, '=');
        if (trim($trimmed, self::ASCII_WHITESPACE) === '') {
            // Covers '' and whitespace-only alike. Reported as EMPTY rather
            // than as a charset error because an operator staring at an
            // invisible value is not helped by being told to hunt for an
            // unexpected character.
            throw new InvalidSecretException('service-key secret must not be empty');
        }
        if (trim($trimmed, self::ASCII_WHITESPACE) !== $trimmed) {
            // Named separately from the charset error below because the
            // character is INVISIBLE: "unexpected characters" sends an operator
            // hunting through a base64 blob for something they cannot see. The
            // overwhelmingly common cause is a trailing newline — a
            // file-mounted Kubernetes/Docker secret, `$(cat secret.txt)`, or a
            // `.env` line — so the message names it.
            throw new InvalidSecretException(
                'service-key secret must not have leading or trailing whitespace '
                . '(usually a trailing newline from a file-mounted secret)',
            );
        }
        // `\A…\z`, NOT `^…$`: PCRE's `$` also matches immediately BEFORE a final
        // newline, so `^[A-Za-z0-9_-]+$` accepts "…sL8\n" — and since a 43-char
        // console secret plus "\n" is 44 chars (≡0 mod 4) and base64_decode()
        // skips whitespace, PHP would go on to derive the CORRECT key from it.
        // JS's `$` has no such rule and Python anchors with `\A…\Z`, so this
        // single character was the difference between PHP silently accepting a
        // malformed secret and the other two rejecting it. `\z` is absolute
        // end-of-subject, matching both. (PCRE's `\Z` is the lenient one;
        // Python's `\Z` is the strict one — do not "harmonize" the spellings by
        // eye.)
        //
        // BELT AND BRACES, and deliberately so: the whitespace guard above now
        // rejects every leading/trailing-whitespace input before control reaches
        // here, which means NO behavioural test can distinguish `\z` from `$` in
        // this function — mutating this anchor alone leaves the suite green
        // (verified). It stays strict anyway, because the guard being the only
        // defence is a property of the current check ORDER, and a future edit
        // that reorders or narrows the guard would silently re-open the hole.
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $trimmed) !== 1) {
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
     *
     * @throws InvalidMethodException when $method is not in {@see HTTP_METHODS}
     */
    public static function computeCanonicalPayload(
        string $method,
        string $pathWithQuery,
        string $timestamp,
        string $nonce,
        string $bodyBytes,
    ): string {
        return implode("\n", [
            'v1',
            self::canonicalizeMethod($method),
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
     * @param string      $method        HTTP method; canonicalized into the payload — see {@see canonicalizeMethod}
     * @param string      $pathWithQuery pathname + encoded query with leading slash, e.g. "/v1/sync-jobs?status=running"
     * @param string      $bodyBytes     RAW body bytes to hash — sign the exact bytes you send ('' for GET/HEAD/DELETE)
     * @param string|null $timestamp     override the unix-seconds timestamp string (defaults to now)
     * @param string|null $nonce         override the nonce (defaults to a fresh random base64url nonce)
     *
     * @throws InvalidNonceException     when the nonce carries surrounding HTTP whitespace, is outside [22, 64], or is not visible ASCII
     * @throws InvalidMethodException    when $method is not in {@see HTTP_METHODS}
     * @throws InvalidSecretException    when the secret is not valid base64url
     * @throws \InvalidArgumentException when $timestamp is not 1-15 digits, or $appId/$keyId are not visible ASCII
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

        // CHECK ORDER IS LOAD-BEARING, and it is the same order in all three
        // SDKs. Surrounding whitespace comes FIRST because it is the only
        // failure here whose character is INVISIBLE: reporting a nonce read out
        // of a file as "length 65 is outside [22, 64]" sends an operator
        // counting characters they can see 64 of. Same reasoning as
        // {@see decodeSecret}, which names a trailing newline separately from a
        // charset problem for exactly this reason. Length comes before charset
        // so a plainly-too-short nonce is reported as such.
        if (preg_match(self::HTTP_WHITESPACE_EDGES_RE, $nonce) === 1) {
            throw new InvalidNonceException(
                'nonce must not have leading or trailing whitespace (usually a trailing '
                . 'newline from a file- or env-sourced value) — HTTP header encoding STRIPS '
                . 'it after the signature is computed, so the runtime would verify a '
                . 'different string than was signed',
            );
        }
        $nonceLen = self::utf16Length($nonce);
        if ($nonceLen < self::NONCE_MIN_LEN || $nonceLen > self::NONCE_MAX_LEN) {
            throw new InvalidNonceException(sprintf(
                "nonce length %d is outside the runtime's [%d, %d] bound",
                $nonceLen,
                self::NONCE_MIN_LEN,
                self::NONCE_MAX_LEN,
            ));
        }
        if (preg_match(self::VISIBLE_ASCII_RE, $nonce) !== 1) {
            // Also keeps the bound checked above measured in a unit the other
            // two SDKs can reproduce: once the nonce is ASCII, byte length,
            // code-point count and UTF-16 length all coincide (issue #151).
            throw new InvalidNonceException(
                'nonce must consist of visible ASCII characters (no spaces, control '
                . 'characters, or non-ASCII) so it survives HTTP header encoding intact',
            );
        }
        if (preg_match(self::TIMESTAMP_RE, $timestamp) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'timestamp must be 1-15 digits (unix seconds), got "%s" — the runtime '
                . 'rejects any other spelling before it checks the clock window',
                $timestamp,
            ));
        }
        // app_id / key_id are NOT part of the canonical payload, so a rewritten
        // value cannot break the signature — but it does change which key the
        // runtime looks up, and a value the headers API rewrites (or refuses)
        // is a 401 nobody can read. Labelled with the WIRE field names, not the
        // PHP parameter names: the message text is identical in all three SDKs
        // and that parity is measured.
        foreach (['app_id' => $appId, 'key_id' => $keyId] as $label => $value) {
            if (preg_match(self::VISIBLE_ASCII_RE, $value) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must consist of visible ASCII characters so it survives HTTP '
                    . 'header encoding intact (got "%s")',
                    $label,
                    $value,
                ));
            }
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
