<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * A verified `sync_job.*` delivery (schema_version 1). Dedupe on
 * {@see $deliveryId}; route on {@see $tenantId}. `job` is a snapshot at
 * `occurred_at`; GET the job when ordering or current state matters.
 */
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $job     `data.sync_job`
     * @param array<string, mixed> $payload the full verified body
     */
    public function __construct(
        public string $deliveryId,
        public string $eventId,
        public string $eventType,
        public string $occurredAt,
        public string $appId,
        public string $tenantId,
        public array $job,
        public array $payload,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $strings = [];
        foreach (['delivery_id', 'event_id', 'event_type', 'occurred_at', 'app_id', 'tenant_id'] as $key) {
            $value = $body[$key] ?? null;
            if (! is_string($value) || $value === '') {
                throw new \UnexpectedValueException('Webhook payload is missing ' . $key);
            }
            $strings[$key] = $value;
        }
        $data = $body['data'] ?? null;
        $job = is_array($data) ? ($data['sync_job'] ?? null) : null;
        if (! is_array($job)) {
            throw new \UnexpectedValueException('Webhook payload is missing data.sync_job');
        }
        $normalized = [];
        foreach ($job as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return new self(
            $strings['delivery_id'],
            $strings['event_id'],
            $strings['event_type'],
            $strings['occurred_at'],
            $strings['app_id'],
            $strings['tenant_id'],
            $normalized,
            $body,
        );
    }

    public function jobId(): ?string
    {
        return isset($this->job['id']) && is_string($this->job['id']) ? $this->job['id'] : null;
    }

    public function operationKey(): ?string
    {
        return isset($this->job['operation_key']) && is_string($this->job['operation_key']) ? $this->job['operation_key'] : null;
    }

    public function externalObjectId(): ?string
    {
        return isset($this->job['external_object_id']) && is_string($this->job['external_object_id']) ? $this->job['external_object_id'] : null;
    }
}
