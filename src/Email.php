<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * The provider-neutral Email API (`/v1/email/*`): send email from a person's
 * own connected mailbox (Gmail first, then Microsoft 365), with the same code
 * for every provider.
 *
 * 1. Each person connects their mailbox with {@see Connections::start()}
 *    (`provider_key` `google-mail` and your own `principal_ref` for them).
 * 2. {@see providers()} lists the email providers with their capabilities and
 *    the tenant's mailbox connections (`mailbox` is the verified address;
 *    `granted_capabilities.send` false means the person did not allow sending:
 *    ask them to reconnect).
 * 3. {@see send()} sends one message. It answers the operation status:
 *    `message.status` is `submitted` when the provider accepted it, otherwise
 *    poll {@see operation()} with `job_id`. Retrying with the same idempotency
 *    key and message replays and never sends twice.
 * 4. A message is sent at most once. `send_uncertain` means the provider's
 *    answer was lost: it may or may not have gone out, and the runtime never
 *    sends it again. `failed` means nothing was sent. To send it again, use a
 *    new `external_ref` and pass the old one as `retry_of` (one retry per
 *    message).
 * 5. {@see sent()} reads a message by your `external_ref`. Its subject,
 *    addresses and body are removed 90 days after the send ended; ids and
 *    status stay.
 *
 * Message fields: `external_ref` (required, unique per connection), `to`,
 * `cc`, `bcc`, `reply_to` (lists of `['email' => ..., 'name' => ...]`, at
 * least one recipient, at most 100), `subject`, `text` and/or `html` (512 KB
 * together), and `reply` (`['internet_message_id' => '<...>']` to answer a
 * message in the same conversation).
 */
final class Email
{
    public function __construct(private readonly Client $client) {}

    /**
     * Email providers with capabilities, and the tenant's mailbox connections.
     *
     * @return array<string, mixed>
     */
    public function providers(string $tenantId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/email/providers', ['tenant_id' => $tenantId]),
        ));
    }

    /**
     * Send a message from the connection's mailbox.
     *
     * @param array<string, mixed> $message `external_ref`, recipients, `subject`, `text`/`html`, optional `reply` and `retry_of`
     * @return array<string, mixed> the operation status; `message.status` once it finished
     */
    public function send(string $tenantId, string $connectionId, string $idempotencyKey, array $message): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('idempotencyKey', $idempotencyKey);
        if (!is_string($message['external_ref'] ?? null) || $message['external_ref'] === '') {
            throw new \InvalidArgumentException('message.external_ref must be a non-empty string');
        }
        if (!is_string($message['subject'] ?? null)) {
            throw new \InvalidArgumentException('message.subject must be a string');
        }

        return ResponseReader::json($this->client->send('POST', '/v1/email/operations', [
            'operation' => 'message.send',
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'idempotency_key' => $idempotencyKey,
            'message' => $message,
        ]), [200, 202]);
    }

    /**
     * Poll a send by the `job_id` from {@see send()}: `200` once it finished,
     * `202` while it is still running.
     *
     * @return array<string, mixed>
     */
    public function operation(string $tenantId, string $jobId): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('jobId', $jobId);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query('/v1/email/operations/' . rawurlencode($jobId), ['tenant_id' => $tenantId]),
        ), [200, 202]);
    }

    /**
     * A message you sent, by your `external_ref` (no provider call). Use it to
     * learn what happened to a send whose response you lost.
     *
     * @return array<string, mixed>
     */
    public function sent(string $tenantId, string $connectionId, string $externalRef): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('externalRef', $externalRef);

        return ResponseReader::json($this->client->send(
            'GET',
            ResponseReader::query(
                '/v1/email/connections/' . rawurlencode($connectionId) . '/sent/' . rawurlencode($externalRef),
                ['tenant_id' => $tenantId],
            ),
        ));
    }
}
