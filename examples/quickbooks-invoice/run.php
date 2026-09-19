<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\IdempotencyConflictException;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\MockRuntimeTransport;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\PendingConnectAttempt;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\QuickBooksInvoiceJourney;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\RuntimeIdentity;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\UnverifiedAuthorizationFailure;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/QuickBooksInvoiceJourney.php';
require __DIR__ . '/MockRuntimeTransport.php';

if ((getenv('IR_EXAMPLE_MODE') ?: 'mock') !== 'mock') {
    throw new RuntimeException(
        'This example is intentionally mock-only. Copy the journey into an application after operator review; '
        . 'do not point this script at staging or production.',
    );
}

// Mock mode mints an ephemeral signing secret for this process. A product must
// inject its operator-issued secret from a secret manager; never commit or print it.
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$identity = new RuntimeIdentity(
    getenv('IR_APP_ID') ?: 'app_erp_mock',
    getenv('IR_KEY_ID') ?: 'key_mock_process_only',
    getenv('IR_TENANT_ID') ?: 'tenant_mock_001',
);
$transport = new MockRuntimeTransport(
    tenantId: $identity->tenantId,
    appId: $identity->appId,
);
$factory = new HttpFactory();
$client = new Client(
    $transport,
    $factory,
    $factory,
    'https://runtime.mock.invalid',
    $identity->appId,
    $identity->keyId,
    $secret,
    RetryPolicy::disabled(),
);
$journey = new QuickBooksInvoiceJourney(
    $client,
    $identity,
    sleep: static function (float $seconds): void {},
);

$attempt = PendingConnectAttempt::create($identity->tenantId);
$connect = $journey->createConnectSession(
    'https://erp.example.test/integrations/quickbooks/return',
    $attempt,
    'operator-user-42',
);

// In a real app, return/redirect this URL to the human operator. Never fetch it
// server-side and never claim connection success before the callback returns.
echo "1. Hand the operator this mock authorization URL: {$connect->authorizeUrl}\n";

// The mock stands in for: browser -> Intuit consent -> runtime callback ->
// registered product return_url. The product validates state and result.
$productReturn = 'https://erp.example.test/integrations/quickbooks/return?'
    . http_build_query([
        'status' => 'connected',
        'connection_id' => 'conn_mock_quickbooks_001',
        'state' => $attempt->opaqueState,
    ]);
$connection = $journey->acceptAuthorizationReturn($productReturn, $attempt);
if ($connection instanceof UnverifiedAuthorizationFailure) {
    throw new RuntimeException($connection->message);
}
echo "2. Accepted connection result {$connection->status} for {$connection->connectionId}.\n";

$request = $journey->buildInvoiceRequest(
    $connection->connectionId,
    'erp-invoice-1001',
    'qb-customer-ref-from-selected-company',
    'qb-item-ref-from-selected-company',
    'qb-tax-code-ref-reviewed-for-taxable-line',
    '100.00',
    'taxable',
);
$submission = $journey->submitInvoice($request);
echo "3. Runtime accepted job {$submission->jobId}; polling its tenant-scoped URL.\n";

$result = $journey->pollToTerminal($submission);
echo "4. Poll result: {$result->kind} ({$result->runtimeStatus}).\n";
if ($result->kind !== 'completed') {
    throw new RuntimeException(
        "Mock journey did not complete its configured app/tenant job: {$result->message}",
    );
}

$replay = $journey->submitInvoice($submission->request);
echo "5. Exact same-body/same-key replay resolved to job {$replay->jobId}.\n";

$changed = $journey->buildInvoiceRequest(
    $connection->connectionId,
    'erp-invoice-1001',
    'qb-customer-ref-from-selected-company',
    'qb-item-ref-from-selected-company',
    'qb-tax-code-ref-reviewed-for-taxable-line',
    '101.00',
    'taxable',
);
try {
    $journey->submitInvoice($changed);
    throw new RuntimeException('mock contract error: changed payload unexpectedly succeeded');
} catch (IdempotencyConflictException $exception) {
    echo "6. Changed payload with the same key stopped at {$exception->statusCode} {$exception->problemCode}; key unchanged.\n";
}

echo "Mock journey complete: no OAuth consent, provider request, credential, invoice write, or network call occurred.\n";
