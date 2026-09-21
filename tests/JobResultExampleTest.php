<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\JobResult\JobResultPoller;
use Ezee\IntegrationRuntime\Examples\JobResult\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../examples/job-result/JobResultPoller.php';
require_once __DIR__ . '/../examples/job-result/MockRuntimeTransport.php';

final class JobResultExampleTest extends TestCase
{
    public function testMockConsumerPollsTheOwningTenantAndReturnsTheExactResult(): void
    {
        $transport = new MockRuntimeTransport('tenant_php_result');
        $factory = new HttpFactory();
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.example.test',
            'app_erp_example',
            'key_example',
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
            RetryPolicy::disabled(),
        );
        $sleeps = [];
        $poller = new JobResultPoller(
            $client,
            'tenant_php_result',
            static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        self::assertSame([
            'page' => 1,
            'items' => [['id' => 'synthetic-1', 'label' => 'mock result']],
            'next_cursor' => null,
        ], $poller->poll('job_result_mock_001'));
        self::assertSame([0.05], $sleeps);
        self::assertCount(2, $transport->requests());

        $nonces = [];
        foreach ($transport->requests() as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('/v1/sync-jobs/job_result_mock_001', $request->getUri()->getPath());
            self::assertSame('tenant_id=tenant_php_result', $request->getUri()->getQuery());
            self::assertSame('app_erp_example', $request->getHeaderLine('X-IR-App-Id'));
            self::assertNotSame('', $request->getHeaderLine('X-IR-Signature'));
            $nonces[] = $request->getHeaderLine('X-IR-Nonce');
        }
        self::assertCount(2, array_unique($nonces));
    }

    public function testConsumerToleratesAnUnknownFutureResultStatusWhenTheObjectIsUsable(): void
    {
        $transport = new MockRuntimeTransport(
            'tenant_php_future_result',
            'job_result_mock_001',
            'available_incremental',
        );
        $factory = new HttpFactory();
        $client = new Client(
            $transport,
            $factory,
            $factory,
            'https://runtime.example.test',
            'app_erp_example',
            'key_example',
            'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
            RetryPolicy::disabled(),
        );
        $poller = new JobResultPoller(
            $client,
            'tenant_php_future_result',
            static function (float $seconds): void {},
        );

        self::assertSame([
            'page' => 1,
            'items' => [['id' => 'synthetic-1', 'label' => 'mock result']],
            'next_cursor' => null,
        ], $poller->poll('job_result_mock_001'));
    }
}
