<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Internal;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Psr\Http\Message\ResponseInterface;

/** @internal Decode a JSON response or throw the runtime problem it carries. */
final class ResponseReader
{
    /**
     * @param list<int> $expected
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response, array $expected = [200]): array
    {
        try {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        $status = $response->getStatusCode();
        if (! in_array($status, $expected, true)) {
            $problem = is_array($body) ? self::stringKeys($body) : null;
            $code = isset($problem['code']) && is_string($problem['code']) ? $problem['code'] : 'http.' . $status;
            $requestId = isset($problem['request_id']) && is_string($problem['request_id']) ? $problem['request_id'] : null;
            $retryAfter = trim($response->getHeaderLine('Retry-After'));

            throw new RuntimeProblemException(
                $status,
                $code,
                $requestId,
                $problem,
                preg_match('/\A\d{1,9}\z/', $retryAfter) === 1 ? (int) $retryAfter : null,
            );
        }
        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw new \UnexpectedValueException('Integration Runtime response must be a JSON object.');
        }

        return self::stringKeys($body);
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @param array<string, string|int|bool|null> $query */
    public static function query(string $path, array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return $pairs === [] ? $path : $path . '?' . implode('&', $pairs);
    }

    public static function requireNonEmpty(string $name, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($name . ' must not be empty');
        }
    }
}
