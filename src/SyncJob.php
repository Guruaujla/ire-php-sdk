<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * One read of GET /v1/sync-jobs/{id}. `status` is an open enum: treat a value
 * you do not recognise as non-terminal and keep the raw body for support.
 */
final readonly class SyncJob
{
    /** Statuses after which the runtime does no more automatic work. */
    public const TERMINAL = ['completed', 'failed', 'dead_lettered', 'cancelled', 'manually_resolved'];

    /**
     * @param array<string, mixed>|null $result published recipe result, when the recipe declares one
     * @param array<string, mixed>      $raw    the full response body
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $operationKey,
        public ?string $externalObjectId,
        public ?string $lastErrorCode,
        public ?array $result,
        public array $raw,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $id = $body['id'] ?? null;
        $status = $body['status'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($status)) {
            throw new \UnexpectedValueException('Sync job response is missing id or status.');
        }
        $string = static fn(string $key): ?string => isset($body[$key]) && is_string($body[$key]) ? $body[$key] : null;
        $result = $body['result'] ?? null;

        return new self(
            $id,
            $status,
            $string('operation_key'),
            $string('external_object_id'),
            $string('last_error_code'),
            is_array($result) ? self::object($result) : null,
            $body,
        );
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * The QuickBooks ID of the document this job wrote: the published
     * `result.external_id`, falling back to `external_object_id`. Null until
     * completed, and always null for Invoice create v4 (it publishes no result).
     */
    public function externalId(): ?string
    {
        $published = $this->result['external_id'] ?? null;
        if (is_string($published) && $published !== '') {
            return $published;
        }

        return $this->externalObjectId;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
