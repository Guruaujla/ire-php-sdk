<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\JobResult\JobResultPoller;
use Ezee\IntegrationRuntime\Examples\JobResult\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/JobResultPoller.php';
require __DIR__ . '/MockRuntimeTransport.php';

if ((getenv('IR_EXAMPLE_MODE') ?: 'mock') !== 'mock') {
    throw new RuntimeException('This example is intentionally mock-only.');
}

$tenantId = 'tenant_mock_001';
$transport = new MockRuntimeTransport($tenantId);
$factory = new HttpFactory();
$client = new Client(
    $transport,
    $factory,
    $factory,
    'https://runtime.mock.invalid',
    'app_erp_mock',
    'key_mock_process_only',
    rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
    RetryPolicy::disabled(),
);
$poller = new JobResultPoller($client, $tenantId, static function (float $seconds): void {});
$result = $poller->poll('job_result_mock_001');

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
echo "Mock-only result retrieved; no provider, credential, or network call occurred.\n";
