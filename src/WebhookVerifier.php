<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\InvalidSecretException;
use Ezee\IntegrationRuntime\Exception\WebhookVerificationException;

/**
 * Verifies an outbound `sync_job.*` delivery from the runtime.
 *
 * Same HMAC scheme as inbound requests with the roles reversed
 * (docs/runbooks/outbound-webhooks.md §3): the runtime signs
 * `v1\nPOST\n<raw path+query>\n<X-IR-Timestamp>\n<X-IR-Nonce>\n<sha256(raw body)>`
 * with the endpoint secret, base64url without padding.
 *
 * Pass the RAW request target and RAW body bytes exactly as received. A
 * framework-decoded path or a re-encoded JSON body will not verify.
 */
final class WebhookVerifier
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var array<string, string> endpoint key id => raw secret bytes */
    private readonly array $keys;

    /**
     * @param string|array<string, string> $secrets one base64url endpoint secret, or `endpoint_key => secret`
     *                                              to accept several during a rotation
     * @param (\Closure(): int)|null $clock testing seam returning unix seconds
     */
    public function __construct(
        #[\SensitiveParameter]
        string|array $secrets,
        private readonly int $maxSkewSeconds = 300,
        ?\Closure $clock = null,
    ) {
        $keys = [];
        foreach (is_string($secrets) ? ['' => $secrets] : $secrets as $keyId => $secret) {
            try {
                $keys[(string) $keyId] = Signer::decodeSecret($secret);
            } catch (InvalidSecretException) {
                throw new \InvalidArgumentException('webhook secret must be the base64url value issued for the endpoint');
            }
        }
        if ($keys === []) {
            throw new \InvalidArgumentException('at least one webhook secret is required');
        }
        $this->keys = $keys;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param array<string, string|list<string>> $headers request headers; names are matched case-insensitively
     *
     * @throws WebhookVerificationException when anything fails; respond 401 and ignore the body
     */
    public function verify(string $method, string $rawTarget, array $headers, string $rawBody): WebhookEvent
    {
        $header = static function (string $name) use ($headers): ?string {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    $value = is_array($value) ? ($value[0] ?? null) : $value;

                    return is_string($value) ? trim($value) : null;
                }
            }

            return null;
        };
        if (strtoupper($method) !== 'POST') {
            throw new WebhookVerificationException('method_not_post');
        }
        if ($rawTarget === '' || $rawTarget[0] !== '/') {
            throw new WebhookVerificationException('target_not_raw_path');
        }
        $timestamp = $header('X-IR-Timestamp');
        $nonce = $header('X-IR-Nonce');
        $signature = $header('X-IR-Signature');
        if ($timestamp === null || $nonce === null || $signature === null || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new WebhookVerificationException('signature_headers_missing');
        }
        if (preg_match('/\A\d{1,15}\z/', $timestamp) !== 1 || abs(($this->clock)() - (int) $timestamp) > $this->maxSkewSeconds) {
            throw new WebhookVerificationException('timestamp_out_of_window');
        }
        $presented = base64_decode(strtr($signature, '-_', '+/'), true);
        if ($presented === false || strlen($presented) !== 32) {
            throw new WebhookVerificationException('signature_malformed');
        }
        $keyId = $header('X-IR-Key-Id') ?? '';
        $candidates = isset($this->keys[$keyId]) ? [$this->keys[$keyId]] : (count($this->keys) === 1 ? array_values($this->keys) : []);
        $canonical = Signer::computeCanonicalPayload('POST', $rawTarget, $timestamp, $nonce, $rawBody);
        $valid = false;
        foreach ($candidates as $key) {
            $valid = hash_equals(hash_hmac('sha256', $canonical, $key, true), $presented) || $valid;
        }
        if (! $valid) {
            throw new WebhookVerificationException('signature_invalid');
        }
        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationException('body_not_json');
        }
        if (! is_array($body)) {
            throw new WebhookVerificationException('body_not_object');
        }
        $normalized = [];
        foreach ($body as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        try {
            return WebhookEvent::fromArray($normalized);
        } catch (\UnexpectedValueException) {
            throw new WebhookVerificationException('payload_shape_invalid');
        }
    }
}
