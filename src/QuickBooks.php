<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

use Ezee\IntegrationRuntime\Internal\ResponseReader;

/**
 * Typed entry points for the live QuickBooks operations.
 *
 * Each write fills the fixed recipe identity (key, version, trigger, object
 * type) so callers only supply business data. The payload shapes are in
 * docs-site/content/quickbooks-operations.md, with a validator-checked example
 * per operation in quickbooks-operations.json.
 *
 * Idempotency is the caller's job and it is not optional: derive the key once
 * from the business event, store it with the ERP document, and pass the SAME
 * key and payload on every retry. An exact retry returns the original job.
 *
 * @deprecated since 1.3.0, use {@see Accounting}: the same operations with ERP
 *             ids and no provider fields. Kept working; removed no earlier
 *             than 2.0. See accounting-migration.md.
 */
final class QuickBooks
{
    /**
     * Operation key => [recipe_key, recipe_version, trigger_type, object_type].
     * Versions are the current admission versions; a new version is a new
     * contract, never a silent upgrade of an accepted job.
     */
    public const OPERATIONS = [
        'quickbooks.invoice.create' => ['erp.quickbooks.invoice.created', 5, 'erp.invoice.created', 'invoice'],
        'quickbooks.invoice.update_sparse' => ['erp.quickbooks.invoice.updated', 1, 'erp.invoice.updated', 'invoice_update'],
        'quickbooks.invoice.void' => ['erp.quickbooks.invoice.voided', 1, 'erp.invoice.voided', 'invoice_void'],
        'quickbooks.payment.create' => ['erp.quickbooks.payment.created', 2, 'erp.payment.created', 'payment'],
        'quickbooks.refund.create' => ['erp.quickbooks.refund.created', 3, 'erp.refund.created', 'refund_receipt'],
        'quickbooks.credit_memo.create' => ['erp.quickbooks.credit_memo.created', 1, 'erp.credit_memo.created', 'credit_memo'],
        'quickbooks.credit_memo.apply' => ['erp.quickbooks.credit_memo.applied', 1, 'erp.credit_memo.applied', 'credit_application'],
        'quickbooks.customer.upsert' => ['erp.quickbooks.customer.sync', 1, 'erp.customer.sync', 'master_customer_command'],
        'quickbooks.item.upsert' => ['erp.quickbooks.item.sync', 1, 'erp.item.sync', 'master_item_command'],
    ];

    private const MASTER_ENTITIES = ['Customer', 'Item', 'TaxCode', 'TaxRate'];

    public function __construct(private readonly Client $client) {}

    /**
     * Build the exact execute body without sending it, e.g. to persist it
     * before the first attempt so a retry can resend identical bytes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function buildRequest(
        string $operation,
        string $tenantId,
        string $connectionId,
        string $internalObjectId,
        string $idempotencyKey,
        array $payload,
        ?string $correlationId = null,
    ): array {
        if (! isset(self::OPERATIONS[$operation])) {
            throw new \InvalidArgumentException('Unsupported QuickBooks operation: ' . $operation);
        }
        ResponseReader::requireNonEmpty('tenantId', $tenantId);
        ResponseReader::requireNonEmpty('connectionId', $connectionId);
        ResponseReader::requireNonEmpty('internalObjectId', $internalObjectId);
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('idempotencyKey must be a stable 1-128 character business-event key');
        }
        if ($payload === []) {
            throw new \InvalidArgumentException('payload must not be empty');
        }
        [$recipeKey, $version, $trigger, $objectType] = self::OPERATIONS[$operation];
        $request = [
            'tenant_id' => $tenantId,
            'provider_key' => 'quickbooks',
            'connection_id' => $connectionId,
            'recipe_key' => $recipeKey,
            'recipe_version' => $version,
            'trigger_type' => $trigger,
            'object_type' => $objectType,
            'internal_object_id' => $internalObjectId,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
        ];
        if ($correlationId !== null) {
            $request['correlation_id'] = $correlationId;
        }

        return $request;
    }

    /**
     * Submit a request built by {@see buildRequest()} (or loaded from where you
     * persisted it). Use this to retry: same array, freshly signed.
     *
     * @param array<string, mixed> $request
     */
    public function submit(array $request): OperationReceipt
    {
        return OperationReceipt::fromArray(ResponseReader::json($this->client->send('POST', '/v1/operations/execute', $request), [202]));
    }

    /** @param array<string, mixed> $payload */
    public function execute(string $operation, string $tenantId, string $connectionId, string $internalObjectId, string $idempotencyKey, array $payload, ?string $correlationId = null): OperationReceipt
    {
        return $this->submit(self::buildRequest($operation, $tenantId, $connectionId, $internalObjectId, $idempotencyKey, $payload, $correlationId));
    }

    /** @param array<string, mixed> $payload `invoice`, `tax_declarations`, `invoice_verification` */
    public function createInvoice(string $tenantId, string $connectionId, string $erpInvoiceId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.invoice.create', $tenantId, $connectionId, $erpInvoiceId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `original_invoice`, sparse `invoice`, `tax_declarations`, `invoice_verification` */
    public function updateInvoice(string $tenantId, string $connectionId, string $erpChangeId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.invoice.update_sparse', $tenantId, $connectionId, $erpChangeId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `original_invoice`, `invoice_void` {Id, SyncToken} */
    public function voidInvoice(string $tenantId, string $connectionId, string $erpVoidId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.invoice.void', $tenantId, $connectionId, $erpVoidId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `payment`, `linked_invoice` */
    public function createPayment(string $tenantId, string $connectionId, string $erpPaymentId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.payment.create', $tenantId, $connectionId, $erpPaymentId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `refund`, `original_invoice`, `original_payment`, `adjustment_verification`, `allocations`, ... */
    public function createRefund(string $tenantId, string $connectionId, string $erpReturnId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.refund.create', $tenantId, $connectionId, $erpReturnId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `credit_memo`, `original_invoice`, `adjustment_verification`, `allocations`, ... */
    public function createCreditMemo(string $tenantId, string $connectionId, string $erpCreditId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.credit_memo.create', $tenantId, $connectionId, $erpCreditId, $idempotencyKey, $payload);
    }

    /** @param array<string, mixed> $payload `original_invoice`, `credit_application` */
    public function applyCreditMemo(string $tenantId, string $connectionId, string $erpApplicationId, string $idempotencyKey, array $payload): OperationReceipt
    {
        return $this->execute('quickbooks.credit_memo.apply', $tenantId, $connectionId, $erpApplicationId, $idempotencyKey, $payload);
    }

    /**
     * Create or update a Customer or Item. `$command` is the `payload.master`
     * object; its `command_id` becomes `internal_object_id`.
     *
     * @param array<string, mixed> $command
     */
    public function upsertMaster(string $tenantId, string $connectionId, array $command): OperationReceipt
    {
        $entity = $command['entity_type'] ?? null;
        $commandId = $command['command_id'] ?? null;
        $company = $command['expected_company_id'] ?? null;
        if (($entity !== 'Customer' && $entity !== 'Item') || ! is_string($commandId) || $commandId === '' || ! is_string($company) || $company === '') {
            throw new \InvalidArgumentException('master command requires entity_type Customer|Item, command_id and expected_company_id');
        }

        return $this->execute(
            $entity === 'Customer' ? 'quickbooks.customer.upsert' : 'quickbooks.item.upsert',
            $tenantId,
            $connectionId,
            $commandId,
            self::masterIdempotencyKey($company, $entity, $commandId),
            ['master' => $command],
        );
    }

    public static function masterIdempotencyKey(string $companyId, string $entity, string $commandId): string
    {
        return 'master:' . $companyId . ':' . $entity . ':' . $commandId;
    }

    /**
     * Read one current master record by exact name or provider ID.
     *
     * @return array<string, mixed>
     */
    public function findMaster(string $tenantId, string $connectionId, string $entity, ?string $name = null, ?string $externalId = null): array
    {
        if (! in_array($entity, self::MASTER_ENTITIES, true)) {
            throw new \InvalidArgumentException('entity must be one of ' . implode(', ', self::MASTER_ENTITIES));
        }
        if (($name === null) === ($externalId === null)) {
            throw new \InvalidArgumentException('pass exactly one of name or externalId');
        }

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            '/v1/connections/' . rawurlencode($connectionId) . '/quickbooks/master/' . $entity,
            ['tenant_id' => $tenantId, 'name' => $name, 'external_id' => $externalId],
        )));
    }

    /** @return array<string, mixed> the ERP-to-QuickBooks mapping for one ERP record */
    public function getMasterMapping(string $tenantId, string $connectionId, string $entity, string $erpId, bool $refresh = false): array
    {
        if (! in_array($entity, self::MASTER_ENTITIES, true)) {
            throw new \InvalidArgumentException('entity must be one of ' . implode(', ', self::MASTER_ENTITIES));
        }

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            '/v1/connections/' . rawurlencode($connectionId) . '/quickbooks/master/mappings/' . $entity . '/' . rawurlencode($erpId),
            ['tenant_id' => $tenantId, 'refresh' => $refresh ? '1' : null],
        )));
    }

    /**
     * Balance and paid state of an Invoice or Payment. `$refresh` re-reads
     * QuickBooks (rate-limited); otherwise the last projection is returned.
     *
     * @return array<string, mixed>
     */
    public function status(string $tenantId, string $connectionId, string $entityType, string $externalId, bool $refresh = false): array
    {
        if (! in_array($entityType, ['Invoice', 'Payment'], true)) {
            throw new \InvalidArgumentException('entityType must be Invoice or Payment');
        }

        return ResponseReader::json($this->client->send('GET', ResponseReader::query(
            '/v1/connections/' . rawurlencode($connectionId) . '/quickbooks/status/' . $entityType . '/' . rawurlencode($externalId),
            ['tenant_id' => $tenantId, 'refresh' => $refresh ? '1' : null],
        )));
    }
}
