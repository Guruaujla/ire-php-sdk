<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * Provider-neutral accounting: one integration for QuickBooks today and other
 * accounting providers later. No method takes a provider; the runtime resolves
 * the tenant's accounting connection and the ERP-to-provider mappings.
 *
 * Payloads carry ERP ids, ERP codes and decimal-string money only. Their
 * shapes are in docs-site/content/accounting-operations.md, with a
 * validator-checked example per operation in accounting-operations.json.
 *
 * Idempotency is the caller's job: derive the key once from the business
 * event, store it with the ERP document, and send the SAME key and payload on
 * every retry. An exact retry returns the original job; a different payload
 * under the same key is refused. Writes to an existing document carry the
 * ERP's own last revision and before-state; a mismatch is refused with
 * accounting.revision_conflict and must not be retried blindly.
 */
final class Accounting
{
    /** Neutral operation keys, in the runtime's catalog order. */
    public const OPERATIONS = [
        'accounting.customer.upsert',
        'accounting.item.upsert',
        'accounting.invoice.create',
        'accounting.invoice.update',
        'accounting.invoice.void',
        'accounting.payment.create',
        'accounting.refund.create',
        'accounting.credit_memo.create',
        'accounting.credit_memo.apply',
    ];

    /** Document types the refresh read covers. */
    public const REFRESH_DOCUMENT_TYPES = ['invoice', 'payment'];

    /** Provider record types the onboarding list, match and bind cover. */
    public const RECORD_ENTITIES = ['customer', 'item', 'tax_code'];

    public function __construct(private readonly Client $client) {}

    /**
     * Build the exact operation body without sending it, e.g. to persist it
     * before the first attempt so a retry can resend identical bytes.
     * `$connectionId` is optional: without it the runtime uses the tenant's
     * one active accounting connection.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function buildRequest(
        string $operation,
        string $tenantId,
        string $erpId,
        string $idempotencyKey,
        array $payload,
        ?string $connectionId = null,
    ): array {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unsupported accounting operation: ' . $operation);
        }
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        if ($erpId === '' || strlen($erpId) > 128) {
            throw new \InvalidArgumentException('erpId must be the ERP document id (1-128 characters)');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('idempotencyKey must be a stable 1-128 character business-event key');
        }
        if ($payload === []) {
            throw new \InvalidArgumentException('payload must not be empty');
        }
        $request = [
            'tenant_id' => $tenantId,
            'operation' => $operation,
            'erp_id' => $erpId,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
        ];
        if ($connectionId !== null) {
            ResponseReader::requireNonEmpty('connectionId', $connectionId);
            $request['connection_id'] = $connectionId;
        }

        return $request;
    }

    /**
     * Submit a request built by {@see buildRequest()} (or loaded from where you
     * persisted it). Use this to retry: same array, freshly signed. Poll the
     * receipt with {@see status()}.
     *
     * @param array<string, mixed> $request
     */
    public function submit(array $request): OperationReceipt
    {
        return OperationReceipt::fromArray(ResponseReader::json($this->client->send('POST', '/v1/accounting/operations', $request), [202]));
    }

    /** @param array<string, mixed> $payload */
    public function execute(string $operation, string $tenantId, string $erpId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->submit(self::buildRequest($operation, $tenantId, $erpId, $idempotencyKey, $payload, $connectionId));
    }

    /** @param array<string, mixed> $payload `customer` {name, email?, currency} */
    public function upsertCustomer(string $tenantId, string $erpCustomerId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.customer.upsert', $tenantId, $erpCustomerId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `item` {name, type, income_account, unit_price, description?} */
    public function upsertItem(string $tenantId, string $erpItemId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.item.upsert', $tenantId, $erpItemId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `invoice`, `expected_totals` */
    public function createInvoice(string $tenantId, string $erpInvoiceId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.invoice.create', $tenantId, $erpInvoiceId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `change_id`, `invoice`, `expected_totals`, `before` */
    public function updateInvoice(string $tenantId, string $erpInvoiceId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.invoice.update', $tenantId, $erpInvoiceId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `void_id`, `invoice` with its `expected_revision` and `before_totals` */
    public function voidInvoice(string $tenantId, string $erpInvoiceId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.invoice.void', $tenantId, $erpInvoiceId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `payment`, `invoice` {erp_id, expected_revision, expected_balance} */
    public function createPayment(string $tenantId, string $erpPaymentId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.payment.create', $tenantId, $erpPaymentId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `refund`, `expected_totals`, `original_invoice`, `original_payment` */
    public function createRefund(string $tenantId, string $erpRefundId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.refund.create', $tenantId, $erpRefundId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `credit_memo`, `expected_totals`, `original_invoice` */
    public function createCreditMemo(string $tenantId, string $erpCreditMemoId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.credit_memo.create', $tenantId, $erpCreditMemoId, $idempotencyKey, $payload, $connectionId);
    }

    /** @param array<string, mixed> $payload `application`, `credit_memo` {erp_id}, `invoice` with its `before_totals` */
    public function applyCreditMemo(string $tenantId, string $erpApplicationId, string $idempotencyKey, array $payload, ?string $connectionId = null): OperationReceipt
    {
        return $this->execute('accounting.credit_memo.apply', $tenantId, $erpApplicationId, $idempotencyKey, $payload, $connectionId);
    }

    /**
     * Status of an accepted operation: `queued`, `processing`, `completed` with
     * the neutral `result` (provider id, revision, totals, line ids), or
     * a terminal failure (`failed`, `dead_lettered`, ...) with a neutral
     * `error.code`.
     *
     * @return array<string, mixed>
     */
    public function status(string $tenantId, string $jobId): array
    {
        ResponseReader::requireNonEmpty('jobId', $jobId);

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            '/v1/accounting/operations/' . rawurlencode($jobId),
            ['tenant_id' => $tenantId],
        )));
    }

    /**
     * Providers the app can connect, their capabilities and mapping kinds, and
     * the tenant's accounting `connection` (`connection_state` connected, none
     * or multiple).
     *
     * @return array<string, mixed>
     */
    public function providers(string $tenantId): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query('/v1/accounting/providers', ['tenant_id' => $tenantId])));
    }

    /**
     * The tenant's one active accounting connection id, for the per-connection
     * reads below. Throws when the tenant has none or several (writes are
     * refused then too; disconnect the extra connection).
     */
    public function connectionId(string $tenantId): string
    {
        $body = $this->providers($tenantId);
        $connection = $body['connection'] ?? null;
        $id = is_array($connection) ? ($connection['connection_id'] ?? null) : null;
        if (($body['connection_state'] ?? null) !== 'connected' || ! is_string($id) || $id === '') {
            $state = $body['connection_state'] ?? null;
            throw new \UnexpectedValueException('Tenant has no single accounting connection (state: ' . (is_string($state) ? $state : 'unknown') . ').');
        }

        return $id;
    }

    /**
     * A document's current provider state from a fresh provider read: its new
     * `revision`, `status`, `total_amount` and `balance`. Use it after
     * accounting.revision_conflict or to pick up a payment made elsewhere.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $tenantId, string $connectionId, string $documentType, string $erpId): array
    {
        if (! in_array($documentType, self::REFRESH_DOCUMENT_TYPES, true)) {
            throw new \InvalidArgumentException('documentType must be one of ' . implode(', ', self::REFRESH_DOCUMENT_TYPES));
        }

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::connectionPath($connectionId) . '/documents/' . $documentType . '/' . rawurlencode($erpId),
            ['tenant_id' => $tenantId],
        )));
    }

    /**
     * Record the ERP line ids of an invoice created earlier through
     * quickbooks(), so update, refund and credit memo can name its lines. Send
     * every line, either as `{erp_line_id, provider_line_id}` or exactly as it
     * was created (`erp_line_id`, `item`, `tax_code`, `amount`, ...).
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed> `provider_id`, `lines`, `outcome` recorded|unchanged
     */
    public function link(string $tenantId, string $connectionId, string $erpInvoiceId, array $lines, ?string $providerId = null): array
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('lines must list every line of the invoice');
        }
        $body = ['tenant_id' => $tenantId, 'lines' => $lines];
        if ($providerId !== null) {
            $body['provider_id'] = $providerId;
        }

        return ResponseReader::json($this->client->send(
            'PUT',
            self::connectionPath($connectionId) . '/documents/invoice/' . rawurlencode($erpInvoiceId) . '/link',
            $body,
        ));
    }

    /**
     * Look up one provider record of a mapping kind (tax code, payment method,
     * account) by its exact provider id or exact name, e.g. to confirm the
     * record an admin picked before {@see setMapping()}. Pass exactly one of
     * `$providerRef` or `$name`. To offer a list, use {@see referenceOptions()}.
     *
     * @return array<string, mixed>
     */
    public function referenceData(string $tenantId, string $connectionId, string $kind, ?string $providerRef = null, ?string $name = null): array
    {
        if (($providerRef === null || $providerRef === '') === ($name === null || $name === '')) {
            throw new \InvalidArgumentException('pass exactly one of providerRef or name');
        }

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::connectionPath($connectionId) . '/reference-data/' . rawurlencode($kind),
            ['tenant_id' => $tenantId, 'provider_ref' => $providerRef, 'name' => $name],
        )));
    }

    /**
     * One page of the provider records a code kind (payment_method,
     * deposit_account, refund_account, income_account) can point at, for an
     * ERP mapping dropdown (`items` with kind, provider_ref, name, active, email;
     * `next_cursor`). Only records the write path accepts for the kind are
     * listed. Inactive records are included; offer only active ones.
     *
     * @return array<string, mixed>
     */
    public function referenceOptions(string $tenantId, string $connectionId, string $kind, ?string $cursor = null, ?int $limit = null): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::connectionPath($connectionId) . '/reference-data/' . rawurlencode($kind) . '/options',
            ['tenant_id' => $tenantId, 'cursor' => $cursor, 'limit' => $limit],
        )));
    }

    /** @return array<string, mixed> */
    public function getMapping(string $tenantId, string $connectionId, string $kind, string $erpCode): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(self::mappingPath($connectionId, $kind, $erpCode), ['tenant_id' => $tenantId])));
    }

    /**
     * Map an ERP code (tax code, payment method, account) to a provider
     * record. `$treatment` applies to tax codes only.
     *
     * @return array<string, mixed>
     */
    public function setMapping(string $tenantId, string $connectionId, string $kind, string $erpCode, string $providerRef, ?string $treatment = null): array
    {
        $body = ['tenant_id' => $tenantId, 'provider_ref' => $providerRef];
        if ($treatment !== null) {
            $body['treatment'] = $treatment;
        }

        return ResponseReader::json($this->client->send('PUT', self::mappingPath($connectionId, $kind, $erpCode), $body));
    }

    public function deleteMapping(string $tenantId, string $connectionId, string $kind, string $erpCode): void
    {
        $response = $this->client->send('DELETE', ResponseReader::query(self::mappingPath($connectionId, $kind, $erpCode), ['tenant_id' => $tenantId]));
        if ($response->getStatusCode() !== 204) {
            ResponseReader::json($response, [204]);
        }
    }

    /**
     * Per-connection sync settings: `sync_start_date`, enabled `record_types`
     * and the `revision` to send back on update. The provider's closing date
     * is read live on each write, not stored here.
     *
     * @return array<string, mixed>
     */
    public function settings(string $tenantId, string $connectionId): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(self::connectionPath($connectionId) . '/settings', ['tenant_id' => $tenantId])));
    }

    /**
     * @param list<string> $recordTypes the record types to sync, from invoice, payment, credit_memo, refund
     * @return array<string, mixed>
     */
    public function updateSettings(string $tenantId, string $connectionId, array $recordTypes, int $expectedRevision): array
    {
        return ResponseReader::json($this->client->send('PUT', self::connectionPath($connectionId) . '/settings', [
            'tenant_id' => $tenantId,
            'record_types' => $recordTypes,
            'expected_revision' => $expectedRevision,
        ]));
    }

    /**
     * One page of provider customers, items or tax codes for onboarding
     * (`records` with provider_ref, name, active, email, sku; `next_cursor`).
     * Inactive records are included. It does not say which ERP id a record
     * is bound to; {@see matchRecords()} candidates carry `bound_erp_id`.
     *
     * @return array<string, mixed>
     */
    public function listRecords(string $tenantId, string $connectionId, string $entity, ?string $cursor = null, ?int $limit = null): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::recordsPath($connectionId, $entity),
            ['tenant_id' => $tenantId, 'cursor' => $cursor, 'limit' => $limit],
        )));
    }

    /**
     * Find the provider record for each ERP record (by email, SKU or name).
     * `matched` is safe to bind; `suggested` needs a person to choose.
     *
     * @param list<array<string, mixed>> $records each {erp_id, name, email?, sku?}
     * @return array<string, mixed>
     */
    public function matchRecords(string $tenantId, string $connectionId, string $entity, array $records): array
    {
        return ResponseReader::json($this->client->send('POST', self::recordsPath($connectionId, $entity) . '/match', ['tenant_id' => $tenantId, 'records' => $records]));
    }

    /**
     * Bind ERP records to provider records, so writes reuse them instead of
     * creating duplicates.
     *
     * @param list<array<string, mixed>> $bindings each {erp_id, provider_ref, treatment?}
     * @return array<string, mixed>
     */
    public function bindRecords(string $tenantId, string $connectionId, string $entity, array $bindings): array
    {
        return ResponseReader::json($this->client->send('POST', self::recordsPath($connectionId, $entity) . '/bind', ['tenant_id' => $tenantId, 'bindings' => $bindings]));
    }

    private static function connectionPath(string $connectionId): string
    {
        ResponseReader::requireNonEmpty('connectionId', $connectionId);

        return '/v1/accounting/connections/' . rawurlencode($connectionId);
    }

    private static function mappingPath(string $connectionId, string $kind, string $erpCode): string
    {
        ResponseReader::requireNonEmpty('erpCode', $erpCode);

        return self::connectionPath($connectionId) . '/mappings/' . rawurlencode($kind) . '/' . rawurlencode($erpCode);
    }

    private static function recordsPath(string $connectionId, string $entity): string
    {
        if (! in_array($entity, self::RECORD_ENTITIES, true)) {
            throw new \InvalidArgumentException('entity must be one of ' . implode(', ', self::RECORD_ENTITIES));
        }

        return self::connectionPath($connectionId) . '/records/' . $entity;
    }
}
