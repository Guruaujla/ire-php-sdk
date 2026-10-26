<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests\Support;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;

final class Clients
{
    /** @param array<mixed> $from */
    public static function str(array $from, string $key): string
    {
        $value = $from[$key] ?? null;
        if (! is_string($value)) {
            throw new \UnexpectedValueException($key . ' is not a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $from
     * @return array<string, mixed>
     */
    public static function obj(array $from, string $key): array
    {
        $value = $from[$key] ?? null;
        if (! is_array($value)) {
            throw new \UnexpectedValueException($key . ' is not an object');
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function decodeFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return self::obj(['root' => $decoded], 'root');
    }

    public const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    public static function client(ScriptedTransport $transport): Client
    {
        $factory = new HttpFactory();

        return new Client($transport, $factory, $factory, 'https://runtime.example.invalid', 'app_erp', 'key_test', self::SECRET, RetryPolicy::disabled());
    }

    /**
     * @param array<mixed>|null     $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, ?array $body, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json', ...$headers], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }
}
