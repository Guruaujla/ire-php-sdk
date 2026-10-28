<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * A verified delivery (schema_version 1): a `sync_job.*` event with
 * {@see $job}, or `accounting.document.status_changed` with
 * {@see $document}. Dedupe on {@see $deliveryId}; route on {@see $tenantId}.
 * `job` is a snapshot at `occurred_at`; GET the job when ordering or current
 * state matters.
 */
final readonly class WebhookEvent
{
    public const ACCOUNTING_DOCUMENT_STATUS_CHANGED = 'accounting.document.status_changed';

    /**
     * @param array<string, mixed> $job      `data.sync_job`; empty for an accounting document event
     * @param array<string, mixed> $payload  the full verified body
     * @param array<string, mixed> $document `data.document` of an accounting document event; empty otherwise
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
        public array $document = [],
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
        $isDocument = $strings['event_type'] === self::ACCOUNTING_DOCUMENT_STATUS_CHANGED;
        $section = is_array($data) ? ($data[$isDocument ? 'document' : 'sync_job'] ?? null) : null;
        if (! is_array($section)) {
            throw new \UnexpectedValueException('Webhook payload is missing data.' . ($isDocument ? 'document' : 'sync_job'));
        }
        $normalized = [];
        foreach ($section as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return new self(
            $strings['delivery_id'],
            $strings['event_id'],
            $strings['event_type'],
            $strings['occurred_at'],
            $strings['app_id'],
            $strings['tenant_id'],
            $isDocument ? [] : $normalized,
            $body,
            $isDocument ? $normalized : [],
        );
    }

    /** The accounting connection of an accounting document event. */
    public function connectionId(): ?string
    {
        $data = $this->payload['data'] ?? null;

        return is_array($data) && isset($data['connection_id']) && is_string($data['connection_id']) ? $data['connection_id'] : null;
    }

    /** Increases with every change of the document; ignore an event lower than one already applied. */
    public function sequence(): ?int
    {
        $data = $this->payload['data'] ?? null;

        return is_array($data) && isset($data['sequence']) && is_int($data['sequence']) ? $data['sequence'] : null;
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
