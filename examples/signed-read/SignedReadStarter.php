<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Examples\SignedRead;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\RetryPolicy;
use Ezee\IntegrationRuntime\Signer;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/** Backend-only source starter. Injected transports are an offline test seam. */
final class SignedReadStarter
{
    private const BODY_LIMIT = 65536;

    private const RESULT_LIMIT = 32768;

    /** Actual cURL HTTP transport: no redirects, retry middleware, or proxy env. */
    public static function http(): HttpClient
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('starter.transport_unavailable');
        }
        $sink = Utils::streamFor('');
        $bytes = 0;
        $boundedSink = FnStream::decorate($sink, ['write' => static function (string $chunk) use ($sink, &$bytes): int {
            if ($bytes + strlen($chunk) > self::BODY_LIMIT) {
                throw new \RuntimeException('starter.response_unverified');
            }
            $bytes += strlen($chunk);

            return $sink->write($chunk);
        }]);

        return new HttpClient(['handler' => new CurlHandler, 'timeout' => 10, 'connect_timeout' => 10, 'allow_redirects' => false, 'http_errors' => false, 'proxy' => '', 'sink' => $boundedSink]);
    }

    private static function token(mixed $value, int $max = 200, bool $dots = false): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9'.($dots ? '._' : '_').'-]{1,'.$max.'}\z/', $value) === 1;
    }

    /**
     * @param  array<string,string>  $env
     * @return array{origin:string,app:string,key:string,secret:string,tenant:string,job:?string}
     */
    private static function config(array $env): array
    {
        $raw = $env['IR_BASE_URL'] ?? '';
        $url = parse_url($raw);
        if ($url === false) {
            throw new \InvalidArgumentException('starter.config_invalid');
        }
        $scheme = strtolower($url['scheme'] ?? '');
        $host = strtolower($url['host'] ?? '');
        $port = $url['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');
        $known = in_array($origin, ['https://api.integrationengine.ca', 'https://staging.integrationengine.ca'], true);
        $local = ($env['IR_ALLOW_LOCAL'] ?? null) === '1' && in_array($scheme, ['http', 'https'], true) && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && $port !== 0;
        $app = $env['IR_APP_ID'] ?? '';
        $key = $env['IR_KEY_ID'] ?? '';
        $tenant = $env['IR_TENANT_ID'] ?? '';
        $secret = $env['IR_SERVICE_KEY_SECRET'] ?? '';
        $job = $env['IR_JOB_ID'] ?? null;
        if ((! $known && ! $local) || ! in_array($raw, [$origin, $origin.'/'], true) || ! self::token($app, 128, true) || in_array($app, ['.', '..'], true) || ! self::token($key, 128) || ! self::token($tenant) || ($job !== null && ! self::token($job)) || $secret === '') {
            throw new \InvalidArgumentException('starter.config_invalid');
        }
        Signer::decodeSecret($secret);

        return compact('origin', 'app', 'key', 'secret', 'tenant', 'job');
    }

    private static function integer(mixed $value): bool
    {
        return is_int($value) && abs($value) <= 9007199254740991;
    }

    /**
     * @param  array{origin:string,app:string,key:string,secret:string,tenant:string,job:?string}  $selected
     * @return array<string,mixed>|null
     */
    private static function job(mixed $body, array $selected): ?array
    {
        if (! $body instanceof \stdClass) {
            return null;
        }
        $data = get_object_vars($body);
        if (($data['app_id'] ?? null) !== $selected['app'] || ($data['tenant_id'] ?? null) !== $selected['tenant'] || ! self::token($data['id'] ?? null) || ($selected['job'] !== null && ($data['id'] ?? null) !== $selected['job']) || ! self::token($data['status'] ?? null, 128, true) || ! self::token($data['operation_key'] ?? null, 200, true) || ! self::integer($data['created_at'] ?? null) || ! array_key_exists('completed_at', $data) || ($data['completed_at'] !== null && ! self::integer($data['completed_at']))) {
            return null;
        }
        $job = array_intersect_key($data, array_flip(['id', 'app_id', 'tenant_id', 'status', 'operation_key', 'created_at', 'completed_at']));
        if ($selected['job'] !== null) {
            $status = $data['result_status'] ?? null;
            $result = $data['result'] ?? null;
            if (! self::token($status, 128, true) || ! array_key_exists('result', $data) || ($status === 'none' && $result !== null) || ($status === 'available' && (! $result instanceof \stdClass || strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::RESULT_LIMIT))) {
                return null;
            }
            $job['result_status'] = $status;
            $job['result'] = $status === 'available' ? $result : null;
        }

        return $job;
    }

    private static function problemCode(mixed $body, ResponseInterface $response): ?string
    {
        $media = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if ($media !== 'application/problem+json' || $response->getStatusCode() < 400 || ! $body instanceof \stdClass) {
            return null;
        }
        $data = get_object_vars($body);
        foreach (['detail', 'correlation_id', 'instance'] as $field) {
            if (! array_key_exists($field, $data) || ($data[$field] !== null && ! is_string($data[$field]))) {
                return null;
            }
        }

        $code = $data['code'] ?? null;

        return is_string($code) && ($data['status'] ?? null) === $response->getStatusCode() && is_string($data['type'] ?? null) && is_string($data['title'] ?? null) && is_string($data['request_id'] ?? null) && self::token($code, 128, true) ? $code : null;
    }

    /**
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    public static function run(array $env, ?ClientInterface $http = null): array
    {
        try {
            $selected = self::config($env);
        } catch (\Throwable) {
            // SDK config errors can include caller values. Never print them.
            return ['outcome' => 'configuration_invalid', 'code' => 'starter.config_invalid'];
        }
        $path = $selected['job'] !== null ? '/v1/sync-jobs/'.$selected['job'].'?tenant_id='.$selected['tenant'] : '/v1/sync-jobs?tenant_id='.$selected['tenant'].'&limit=1';
        $report = ['mode' => $selected['job'] !== null ? 'job' : 'list', 'method' => 'GET', 'path' => $path, 'http_status' => null, 'elapsed_ms' => 0, 'request_id' => null, 'correlation_id' => null, 'problem_code' => null, 'outcome' => 'unknown', 'job' => null];
        $started = hrtime(true);
        try {
            $factory = new HttpFactory;
            $client = new Client($http ?? self::http(), $factory, $factory, $selected['origin'], $selected['app'], $selected['key'], $selected['secret'], RetryPolicy::disabled());
            $response = $client->send('GET', $path);
            $report['http_status'] = $response->getStatusCode();
            $report['outcome'] = 'unverified';
            foreach (['request_id' => 'X-Request-Id', 'correlation_id' => 'X-IR-Correlation-Id'] as $field => $header) {
                $value = $response->getHeaderLine($header);
                $report[$field] = self::token($value, 200, true) ? $value : null;
            }
            $stream = $response->getBody();
            try {
                $text = $stream->read(self::BODY_LIMIT + 1);
                if (strlen($text) > self::BODY_LIMIT || ! $stream->eof()) {
                    throw new \RuntimeException('starter.response_unverified');
                }
                $body = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
            } finally {
                $stream->close();
            }
            if ($response->getStatusCode() !== 200) {
                $code = self::problemCode($body, $response);
                if ($code !== null) {
                    $report['outcome'] = 'http_refused';
                    $report['problem_code'] = $code;
                }
            } elseif ($selected['job'] !== null) {
                $job = self::job($body, $selected);
                if ($job !== null) {
                    $report['outcome'] = 'job_observed';
                    $report['job'] = $job;
                }
            } elseif ($body instanceof \stdClass && isset($body->jobs) && is_array($body->jobs) && count($body->jobs) <= 1 && property_exists($body, 'next_cursor') && ($body->next_cursor === null || is_string($body->next_cursor))) {
                if ($body->jobs === []) {
                    $report['outcome'] = 'empty_list';
                } else {
                    $job = self::job($body->jobs[0], $selected);
                    if ($job !== null) {
                        $report['outcome'] = 'job_observed';
                        $report['job'] = $job;
                    }
                }
            }
        } catch (\Throwable) {
            // No raw errors, response bodies, headers or service-key values.
        }
        $report['elapsed_ms'] = max(0, (int) round((hrtime(true) - $started) / 1000000));

        return $report;
    }
}
