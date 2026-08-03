<?php

// NO declare(strict_types=1) — that omission IS the test.
//
// `declare(strict_types=1)` governs the file where a call is WRITTEN, not the
// file where the callee lives. So the declaration at the top of RetryPolicy.php
// does nothing for a product backend whose own file omits it — which is most
// PHP in the wild. This file reproduces that caller.
//
// With typed promoted parameters, weak-mode coercion ran at the call boundary
// BEFORE the constructor's validation, so `maxAttempts: 1.5` silently became
// `1` — DISABLING RETRIES — and the `>= 1` check never saw the value it was
// meant to catch. Numeric strings were likewise accepted here while the
// TypeScript and Python SDKs rejected them. RetryPolicy therefore takes `mixed`
// and validates before assigning.

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyWeakModeTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): RetryPolicy, string}>
     */
    public static function weaklyTypedPolicies(): iterable
    {
        // The sharp one: 1.5 coerced to 1 turns retries OFF while looking like
        // a request for one-and-a-half attempts.
        yield 'float maxAttempts silently truncating to 1' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 1.5),
            'maxAttempts must be an integer >= 1',
        ];
        yield 'float maxAttempts truncating to a valid 3' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 3.9),
            'maxAttempts must be an integer >= 1',
        ];
        yield 'numeric string maxAttempts' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: '3'),
            'maxAttempts must be an integer >= 1',
        ];
        yield 'numeric string baseDelaySeconds' => [
            static fn(): RetryPolicy => new RetryPolicy(baseDelaySeconds: '1'),
            'baseDelaySeconds must be a finite number >= 0',
        ];
        yield 'numeric string maxDelaySeconds' => [
            static fn(): RetryPolicy => new RetryPolicy(maxDelaySeconds: '60'),
            'maxDelaySeconds must be a finite number >= 0',
        ];
        yield 'bool maxAttempts' => [
            static fn(): RetryPolicy => new RetryPolicy(maxAttempts: true),
            'maxAttempts must be an integer >= 1',
        ];
    }

    #[DataProvider('weaklyTypedPolicies')]
    public function testWeaklyTypedValuesAreRejectedRatherThanCoerced(
        callable $construct,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $construct();
    }

    /** Legitimate values still work from a weak-mode caller. */
    public function testWellTypedValuesStillConstructFromAWeakModeCaller(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            baseDelaySeconds: 2.0,
            maxDelaySeconds: 30.0,
            jitterFraction: 0.5,
        );

        self::assertSame(5, $policy->maxAttempts);
        self::assertSame(2.0, $policy->baseDelaySeconds);
        self::assertSame(30.0, $policy->maxDelaySeconds);
        self::assertSame(0.5, $policy->jitterFraction);

        // int is widened to float for the delay fields — `1` is a natural
        // literal and both other SDKs accept it.
        $widened = new RetryPolicy(baseDelaySeconds: 1, maxDelaySeconds: 30);
        self::assertSame(1.0, $widened->baseDelaySeconds);
        self::assertSame(30.0, $widened->maxDelaySeconds);
    }
}
