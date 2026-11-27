<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Internal\ResponseReader;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * The provider-neutral Email API (`/v1/email/*`): send email from a person's
 * own connected mailbox (Gmail first, then Microsoft 365), with the same code
 * for every provider.
 *
 * 1. Each person connects their mailbox with {@see connect()} and your own
 *    `principal_ref` for them; {@see disconnect()} removes it.
 * 2. {@see providers()} lists the email providers with their capabilities and
 *    the tenant's mailbox connections (`mailbox` is the verified address;
 *    `granted_capabilities.send` false means the person did not allow sending:
 *    ask them to reconnect).
 * 3. {@see send()} sends one message. It answers the operation status:
 *    `message.status` is `submitted` when the provider accepted it, otherwise
 *    {@see waitFor()} the `job_id`. Retrying with the same idempotency key and
 *    message replays and never sends twice. {@see decisionFor()} says what to
 *    do with a finished send's `error`.
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
    /** Job states after which a send has finished (an unknown state is not finished). */
    public const TERMINAL_JOB_STATUSES = ['completed', 'failed', 'dead_lettered', 'cancelled', 'manually_resolved'];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @param (\Closure(float): void)|null $sleep testing seam for {@see waitFor()} */
    public function __construct(private readonly Client $client, ?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds >= 1) {
                sleep((int) $seconds);
            } elseif ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /**
     * Start connecting one person's mailbox. Redirect the person to
     * `authorize_url`; store `connection_id` against the person. Calling it
     * again with the same `$principalRef` reconnects that same connection.
     *
     * @param string $principalRef your id for the person (1-255 characters), for example your user id
     * @param string $state one-time, unpredictable value you stored server-side for this attempt
     * @param string|null $principalLabel optional display name for the person (1-120 characters); null keeps any stored label
     * @return array{session_id: string, authorize_url: string, expires_at: int, connection_id: string|null}
     */
    public function connect(string $tenantId, string $principalRef, string $returnUrl, string $state, string $providerKey = 'google-mail', ?string $principalLabel = null): array
    {
        return (new Connections($this->client))->start($tenantId, $returnUrl, $state, $providerKey, $principalRef, $principalLabel);
    }

    /**
     * Disconnect one person's mailbox. The runtime deletes its tokens; the
     * person's own consent at Google is left alone (they can remove it at
     * https://myaccount.google.com/permissions). Reuse `$idempotencyKey` only
     * to retry the same attempt. Without `$providerAccountId` the SDK reads it
     * from the connection.
     *
     * @return array{provider_cleanup: string|null}
     */
    public function disconnect(string $tenantId, string $connectionId, string $idempotencyKey, ?string $providerAccountId = null): array
    {
        return (new Connections($this->client))->disconnect($tenantId, $connectionId, $idempotencyKey, $providerAccountId);
    }

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
     * `$idempotencyKey` null (the default) uses `external_ref`, which is
     * already unique per connection: a resend of the same message replays.
     *
     * @param array<string, mixed> $message `external_ref`, recipients, `subject`, `text`/`html`, optional `from` (`name`), `reply` (`internet_message_id`, `provider_message_id`, `thread_id`, `references`) and `retry_of`
     * @return array<string, mixed> the operation status; `message.status` once it finished
     */
    public function send(string $tenantId, string $connectionId, ?string $idempotencyKey, array $message): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        if (!is_string($message['external_ref'] ?? null) || $message['external_ref'] === '') {
            throw new \InvalidArgumentException('message.external_ref must be a non-empty string');
        }
        $idempotencyKey ??= $message['external_ref'];
        ResponseReader::requireNonEmpty('idempotencyKey', $idempotencyKey);
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
     * Poll a send until it finished, or until `$timeoutSeconds` passed. Returns
     * the last operation read: check {@see isFinished()} before reading
     * `message.status`. A 429 or 5xx read is waited out against the deadline;
     * other refusals throw {@see RuntimeProblemException}.
     *
     * @return array<string, mixed>
     */
    public function waitFor(string $tenantId, string $jobId, int $timeoutSeconds = 60, float $intervalSeconds = 1.0): array
    {
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('jobId', $jobId);
        $poller = new self($this->client->withoutRetry(), $this->sleep);
        $deadline = microtime(true) + max(0, $timeoutSeconds);
        $interval = max(0.5, $intervalSeconds);
        $last = null;
        while (true) {
            $transient = null;
            try {
                $last = $poller->operation($tenantId, $jobId);
                if (self::isFinished($last)) {
                    return $last;
                }
                $delay = $interval;
            } catch (RuntimeProblemException $problem) {
                if ($problem->httpStatus !== 429 && $problem->httpStatus < 500) {
                    throw $problem;
                }
                $transient = $problem;
                $delay = (float) max($interval, $problem->retryAfterSeconds ?? 0);
            } catch (NetworkExceptionInterface $transport) {
                $transient = $transport;
                $delay = $interval;
            }
            if (microtime(true) + $delay > $deadline) {
                if ($last === null) {
                    throw $transient ?? new \LogicException('unreachable: no read and no error');
                }

                return $last;
            }
            ($this->sleep)($delay);
            $interval = min($interval * 1.5, 15.0);
        }
    }

    /** @param array<string, mixed> $operation an operation status from {@see send()}, {@see operation()} or {@see waitFor()} */
    public static function isFinished(array $operation): bool
    {
        return in_array($operation['status'] ?? null, self::TERMINAL_JOB_STATUSES, true);
    }

    /**
     * What to do with a send: null while it has no `error` (submitted, or not
     * finished yet: check {@see isFinished()} first), otherwise the
     * {@see RuntimeProblemException} decision for its `error.code` (email.md
     * "Errors"). `review_and_resubmit`: for `email.send_uncertain` the message
     * may have gone out, so check the Sent folder first; for any other code
     * nothing was sent, so send again with a new `external_ref` and
     * `retry_of`. `fix_setup`: the person must reconnect. `ask_operator`:
     * contact support.
     *
     * @param array<string, mixed> $operation
     */
    public static function decisionFor(array $operation): ?string
    {
        $error = $operation['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }
        $code = $error['code'] ?? null;
        if (!is_string($code) || $code === '') {
            return RuntimeProblemException::ASK_OPERATOR;
        }

        return (new RuntimeProblemException(409, $code))->decision();
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
