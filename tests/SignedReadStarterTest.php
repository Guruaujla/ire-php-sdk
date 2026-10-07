<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Examples\SignedRead\SignedReadStarter;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

require_once __DIR__ . '/../examples/signed-read/SignedReadStarter.php';

/** Offline transport: it never opens a socket. */
final class OfflineReadTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }
}
final class SignedReadStarterTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8'; // Published test vector.

    /** @return array<string,string> */
    private function env(): array
    {
        return ['IR_BASE_URL' => 'https://api.integrationengine.ca', 'IR_APP_ID' => 'app_erp', 'IR_KEY_ID' => 'key_test', 'IR_SERVICE_KEY_SECRET' => self::SECRET, 'IR_TENANT_ID' => 'tenant_a'];
    }

    /** @return array<string,mixed> */
    private function job(): array
    {
        return ['id' => 'job_a', 'app_id' => 'app_erp', 'tenant_id' => 'tenant_a', 'status' => 'future_status', 'operation_key' => 'example.read', 'created_at' => 1700000000000, 'completed_at' => null, 'result_status' => 'none', 'result' => null];
    }

    private function response(mixed $body, int $status = 200, string $media = 'application/json'): Response
    {
        return new Response($status, ['Content-Type' => $media, 'X-Request-Id' => 'req_actual', 'X-IR-Correlation-Id' => 'corr_actual'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function test_one_signed_empty_list_read_has_no_job_proof(): void
    {
        $transport = new OfflineReadTransport($this->response(['jobs' => [], 'next_cursor' => null, 'private' => 'PRIVATE BODY']));
        $report = SignedReadStarter::run($this->env(), $transport);
        self::assertSame('empty_list', $report['outcome']);
        self::assertNull($report['job']);
        self::assertSame(200, $report['http_status']);
        self::assertSame('req_actual', $report['request_id']);
        self::assertSame('corr_actual', $report['correlation_id']);
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.integrationengine.ca/v1/sync-jobs?tenant_id=tenant_a&limit=1', (string) $request->getUri());
        self::assertSame('app_erp', $request->getHeaderLine('X-IR-App-Id'));
        self::assertNotSame('', $request->getHeaderLine('X-IR-Signature'));
        self::assertSame('', (string) $request->getBody());
        self::assertStringNotContainsString(self::SECRET, json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_exact_job_preserves_unknown_status_and_null_result(): void
    {
        $transport = new OfflineReadTransport($this->response($this->job()));
        $report = SignedReadStarter::run([...$this->env(), 'IR_JOB_ID' => 'job_a'], $transport);
        self::assertSame('job_observed', $report['outcome']);
        self::assertSame($this->job(), $report['job']);
        self::assertSame('/v1/sync-jobs/job_a', $transport->requests[0]->getUri()->getPath());
        self::assertCount(1, $transport->requests);
    }

    public function test_published_result_is_selected_and_unknown_publication_hidden(): void
    {
        foreach (['available', 'future_publication'] as $status) {
            $transport = new OfflineReadTransport($this->response([...$this->job(), 'result_status' => $status, 'result' => (object) ['records' => []], 'payload' => 'PRIVATE PAYLOAD']));
            $report = SignedReadStarter::run([...$this->env(), 'IR_JOB_ID' => 'job_a'], $transport);
            self::assertSame('job_observed', $report['outcome']);
            $job = $report['job'];
            self::assertIsArray($job);
            self::assertSame($status, $job['result_status']);
            self::assertEquals($status === 'available' ? (object) ['records' => []] : null, $job['result']);
            self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
        }
    }

    public function test_problem_details_status_and_unknown_code_are_reported_once_without_raw_errors(): void
    {
        foreach ([401, 403, 429, 500] as $status) {
            $transport = new OfflineReadTransport($this->response(['type' => 'urn:runtime:test-problem', 'title' => 'Runtime test problem', 'status' => $status, 'detail' => 'PRIVATE ' . self::SECRET, 'code' => 'runtime.test_problem', 'request_id' => 'req_actual', 'correlation_id' => null, 'instance' => null], $status, 'application/problem+json'));
            $report = SignedReadStarter::run($this->env(), $transport);
            self::assertSame('http_refused', $report['outcome']);
            self::assertSame($status, $report['http_status']);
            self::assertSame('runtime.test_problem', $report['problem_code']);
            self::assertCount(1, $transport->requests);
            self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
        }
    }

    public function test_non_problems_and_redirects_are_unverified_without_following(): void
    {
        foreach ([$this->response(['code' => 'fake'], 403), $this->response(['code' => 'fake'], 403, 'application/problem+json'), new Response(403, ['Content-Type' => 'text/html'], 'PRIVATE HTML'), new Response(302, ['Location' => 'https://evil.example'])] as $response) {
            $transport = new OfflineReadTransport($response);
            $report = SignedReadStarter::run($this->env(), $transport);
            self::assertSame('unverified', $report['outcome']);
            self::assertNull($report['problem_code']);
            self::assertCount(1, $transport->requests);
            self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
        }
    }

    public function test_foreign_and_malformed_jobs_cannot_produce_observed_data(): void
    {
        foreach ([['app_id' => 'foreign'], ['tenant_id' => 'foreign'], ['id' => 'foreign'], ['result_status' => 'available', 'result' => []], ['result' => (object) ['unexpected' => true]], ['result_status' => 'available', 'result' => (object) ['value' => str_repeat('x', 32768)]]] as $override) {
            $report = SignedReadStarter::run([...$this->env(), 'IR_JOB_ID' => 'job_a'], new OfflineReadTransport($this->response([...$this->job(), ...$override])));
            self::assertSame('unverified', $report['outcome']);
            self::assertNull($report['job']);
        }
    }

    public function test_origin_validation_runs_before_any_request(): void
    {
        foreach (['https://evil.example', 'https://user:pass@api.integrationengine.ca', 'https://api.integrationengine.ca?x=1', 'https://api.integrationengine.ca#x', 'https://api.integrationengine.ca/prefix', 'https://api.integrationengine.ca/../', 'http://127.0.0.1:8787'] as $origin) {
            $transport = new OfflineReadTransport($this->response([]));
            self::assertSame('configuration_invalid', SignedReadStarter::run([...$this->env(), 'IR_BASE_URL' => $origin], $transport)['outcome']);
            self::assertCount(0, $transport->requests);
        }
    }

    public function test_local_flag_allows_only_loopback(): void
    {
        $transport = new OfflineReadTransport($this->response(['jobs' => [], 'next_cursor' => null]));
        self::assertSame('empty_list', SignedReadStarter::run([...$this->env(), 'IR_BASE_URL' => 'http://127.0.0.1:8787', 'IR_ALLOW_LOCAL' => '1'], $transport)['outcome']);
        self::assertSame('configuration_invalid', SignedReadStarter::run([...$this->env(), 'IR_BASE_URL' => 'http://192.168.1.2:8787', 'IR_ALLOW_LOCAL' => '1'], $transport)['outcome']);
        self::assertCount(1, $transport->requests);
    }

    public function test_invalid_configuration_never_leaks_or_requests(): void
    {
        foreach ([['IR_TENANT_ID' => '../tenant'], ['IR_JOB_ID' => 'job/foreign'], ['IR_APP_ID' => str_repeat('a', 129)], ['IR_SERVICE_KEY_SECRET' => 'PRIVATE INVALID SECRET!'], ['IR_KEY_ID' => '']] as $override) {
            $transport = new OfflineReadTransport($this->response([]));
            self::assertSame(['outcome' => 'configuration_invalid', 'code' => 'starter.config_invalid'], SignedReadStarter::run([...$this->env(), ...$override], $transport));
            self::assertCount(0, $transport->requests);
        }
    }

    public function test_body_size_and_malformed_text_are_bounded_and_hidden(): void
    {
        foreach (['PRIVATE NOT JSON', str_repeat('x', 65537)] as $body) {
            $report = SignedReadStarter::run($this->env(), new OfflineReadTransport(new Response(200, [], $body)));
            self::assertSame('unverified', $report['outcome']);
            self::assertNull($report['job']);
            self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
        }
    }

    public function test_timeout_before_response_is_unknown_and_sanitized(): void
    {
        $transport = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('PRIVATE TIMEOUT');
            }
        };
        $report = SignedReadStarter::run($this->env(), $transport);
        self::assertSame('unknown', $report['outcome']);
        self::assertNull($report['http_status']);
        self::assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_real_transport_refuses_redirects_and_bounds_transfer_and_body(): void
    {
        $http = SignedReadStarter::http();
        self::assertSame(10, $http->getConfig('timeout'));
        self::assertSame(10, $http->getConfig('connect_timeout'));
        self::assertFalse($http->getConfig('allow_redirects'));
        self::assertFalse($http->getConfig('http_errors'));
        self::assertSame('', $http->getConfig('proxy'));
        $sink = $http->getConfig('sink');
        self::assertInstanceOf(StreamInterface::class, $sink);
        self::assertSame(65536, $sink->write(str_repeat('x', 65536)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('starter.response_unverified');
        $sink->write('x');
    }

    public function test_a_list_cannot_display_multiple_rows_or_foreign_scope(): void
    {
        foreach ([['jobs' => [$this->job(), $this->job()], 'next_cursor' => null], ['jobs' => [[...$this->job(), 'tenant_id' => 'foreign']], 'next_cursor' => null]] as $body) {
            $report = SignedReadStarter::run($this->env(), new OfflineReadTransport($this->response($body)));
            self::assertSame('unverified', $report['outcome']);
            self::assertNull($report['job']);
        }
    }
}
