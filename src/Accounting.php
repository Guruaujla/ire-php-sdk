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

    /** Record types {@see startSnapshot()} and {@see latestSnapshot()} accept. */
    public const SNAPSHOT_ENTITIES = ['customer', 'item'];

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
     * refused then too; disconnect the extra connection with
     * {@see Connections::disconnect()}, passing its stored provider_account_id).
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
     * `$source`: `live` (the default) pages the provider; `snapshot` pages a
     * ready snapshot ({@see startSnapshot()}), in name order. Records read
     * from a snapshot carry no email or SKU (null). `$q` searches a ready
     * snapshot of customers or items: an exact email on customers when it
     * contains `@`, otherwise a name prefix (at least 3 characters). A
     * cursor only works with the source that issued it.
     *
     * @return array<string, mixed>
     */
    public function listRecords(string $tenantId, string $connectionId, string $entity, ?string $cursor = null, ?int $limit = null, ?string $source = null, ?string $q = null): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::recordsPath($connectionId, $entity),
            ['tenant_id' => $tenantId, 'cursor' => $cursor, 'limit' => $limit, 'source' => $source, 'q' => $q],
        )));
    }

    /**
     * Find the provider record for each ERP record (by email, SKU or name).
     * `matched` is safe to bind; `suggested` needs a person to choose.
     *
     * `$source` (customers and items): `auto` (the server default) matches
     * against a ready snapshot under 24 hours old, up to 1,000 records per
     * call, and otherwise the provider (up to 100 per call); while a
     * snapshot is still being pulled it answers 409
     * `accounting.snapshot_not_ready`. `snapshot` and `live` force one source.
     * The response names the `source` it used and the snapshot's `as_of`.
     *
     * @param list<array<string, mixed>> $records each {erp_id, name, email?, sku?}
     * @return array<string, mixed>
     */
    public function matchRecords(string $tenantId, string $connectionId, string $entity, array $records, ?string $source = null): array
    {
        $body = ['tenant_id' => $tenantId, 'records' => $records];
        if ($source !== null) {
            $body['source'] = $source;
        }

        return ResponseReader::json($this->client->send('POST', self::recordsPath($connectionId, $entity) . '/match', $body));
    }

    /**
     * Bind ERP records to provider records, so writes reuse them instead of
     * creating duplicates. A row refused with `accounting.mapping_conflict`
     * because the ERP id is already bound to another record carries that
     * record as `bound_provider_ref`. After a reconnect, an ERP id can be
     * bound again to a different record.
     *
     * @param list<array<string, mixed>> $bindings each {erp_id, provider_ref, treatment?}
     * @return array<string, mixed>
     */
    public function bindRecords(string $tenantId, string $connectionId, string $entity, array $bindings): array
    {
        return ResponseReader::json($this->client->send('POST', self::recordsPath($connectionId, $entity) . '/bind', ['tenant_id' => $tenantId, 'bindings' => $bindings]));
    }

    /**
     * Start a snapshot: a background job that reads every customer or item
     * from the provider once, so matching and searching read it instead of
     * the provider. Returns the running snapshot if there is one, or a ready
     * unexpired one, unless `$refresh` is true (allowed once per 15 minutes
     * per connection and record type). The job's `sync_job.completed`
     * webhook fires when it is ready, or `sync_job.dead_lettered` when the
     * pull failed ({@see latestSnapshot()} then shows `failed` with
     * `error_code`). Match against it with `$source = 'snapshot'`: `auto`
     * uses it only while `as_of` is under 24 hours old. Customers and items
     * only.
     *
     * @return array<string, mixed> {snapshot_id, entity, status, job_id, record_count, provider_calls, waiting_on, as_of, expires_at, created_at, error_code}
     */
    public function startSnapshot(string $tenantId, string $connectionId, string $entity, bool $refresh = false): array
    {
        $body = ['tenant_id' => $tenantId];
        if ($refresh) {
            $body['refresh'] = true;
        }

        return ResponseReader::json($this->client->send('POST', self::snapshotPath($connectionId, $entity), $body), [200, 202]);
    }

    /**
     * The latest snapshot for this connection and record type. Treat a
     * status other than `ready` as not ready. 404
     * `accounting.snapshot_not_found` when none was taken.
     *
     * @return array<string, mixed> same shape as {@see startSnapshot()}
     */
    public function latestSnapshot(string $tenantId, string $connectionId, string $entity): array
    {
        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::snapshotPath($connectionId, $entity) . '/latest',
            ['tenant_id' => $tenantId],
        )));
    }

    /**
     * Bind up to 5,000 customers or items in one background job. Every row
     * is verified with the provider and stored like {@see bindRecords()};
     * each row succeeds or fails on its own. One bind job runs per
     * connection at a time. The job's `sync_job.completed` (or
     * `sync_job.dead_lettered`) webhook fires when it ends; then read
     * {@see bindJobResults()}.
     *
     * @param list<array<string, mixed>> $bindings each {erp_id, provider_ref}
     * @return array<string, mixed> {job_id, entity, status, row_count, bound_count, refused_count, not_attempted_count, provider_calls, waiting_on, error_code, created_at, ended_at}
     */
    public function startBindJob(string $tenantId, string $connectionId, string $entity, array $bindings): array
    {
        return ResponseReader::json($this->client->send('POST', self::recordsPath($connectionId, $entity) . '/bind-jobs', ['tenant_id' => $tenantId, 'bindings' => $bindings]), [202]);
    }

    /**
     * One page (up to 500 rows, request order) of a bind job's results,
     * with the job's counts and status. Each row is `bound`, `refused`
     * (with `code`, and `bound_provider_ref` on a `mapping_conflict`) or
     * `not_attempted` (send it again in a new job). Follow `next_cursor`
     * until it is null. Results are kept 7 days after the job ends.
     *
     * @return array<string, mixed>
     */
    public function bindJobResults(string $tenantId, string $connectionId, string $entity, string $jobId, ?string $cursor = null): array
    {
        ResponseReader::requireNonEmpty('jobId', $jobId);

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            self::recordsPath($connectionId, $entity) . '/bind-jobs/' . rawurlencode($jobId) . '/results',
            ['tenant_id' => $tenantId, 'cursor' => $cursor],
        )));
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

    /** Snapshots cover customers and items only. */
    private static function snapshotPath(string $connectionId, string $entity): string
    {
        if (! in_array($entity, self::SNAPSHOT_ENTITIES, true)) {
            throw new \InvalidArgumentException('entity must be one of ' . implode(', ', self::SNAPSHOT_ENTITIES));
        }

        return self::recordsPath($connectionId, $entity) . '/snapshots';
    }

    private static function recordsPath(string $connectionId, string $entity): string
    {
        if (! in_array($entity, self::RECORD_ENTITIES, true)) {
            throw new \InvalidArgumentException('entity must be one of ' . implode(', ', self::RECORD_ENTITIES));
        }

        return self::connectionPath($connectionId) . '/records/' . $entity;
    }
}
