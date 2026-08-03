<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime;

/**
 * How {@see Client::send()} retries a 429 (issue #134).
 *
 * The PHP twin of the TypeScript SDK's `RetryPolicy` interface and the Python
 * SDK's `RetryPolicy` dataclass: the same four fields, the same defaults, and
 * the same algorithm, so the three SDKs behave identically on a rate limit.
 *
 * Immutable by construction (readonly promoted properties) and validated in the
 * constructor, so a typo fails where the client is wired up rather than during
 * a rate-limit incident.
 */
final class RetryPolicy
{
    /**
     * Ceiling on the longest sleep a policy may produce, in seconds — JS
     * `setTimeout`'s maximum delay (2^31-1 ms, about 24.855 days). Shared by
     * all three SDKs so they accept an identical set of policies; see the
     * constructor.
     */
    public const MAX_TOTAL_DELAY_SECONDS = 2147483.647;

    /** TOTAL attempts including the first; 1 disables retry. */
    public readonly int $maxAttempts;

    /** Backoff base used when `Retry-After` is absent or unparseable. */
    public readonly float $baseDelaySeconds;

    /**
     * Cap on the delay BEFORE jitter — the longest single sleep is
     * $maxDelaySeconds * (1 + $jitterFraction).
     */
    public readonly float $maxDelaySeconds;

    /** Additive jitter fraction — see {@see Client::send()}. */
    public readonly float $jitterFraction;

    /**
     * Parameters are `mixed` ON PURPOSE — do not "tidy" them into typed
     * promoted properties.
     *
     * `declare(strict_types=1)` at the top of this file governs calls MADE from
     * this file; it does not bind callers. A product backend whose own file
     * omits the declaration — which is most PHP in the wild — gets weak-mode
     * coercion at the call boundary, BEFORE this constructor runs. With typed
     * parameters that meant:
     *
     *   - `maxAttempts: 1.5` silently became `1`, DISABLING RETRIES, and the
     *     `>= 1` check below never saw the 1.5 it was supposed to catch;
     *   - `baseDelaySeconds: "1"` and `maxAttempts: "3"` were accepted, while
     *     the TypeScript SDK (`Number.isFinite("1")` is false) and the Python
     *     SDK (an explicit isinstance check) both reject them.
     *
     * Taking `mixed` and validating first is the only way to see what the
     * caller actually passed. The values are then assigned to the typed
     * readonly properties above, so the public shape is unchanged.
     *
     * @param mixed $maxAttempts       TOTAL attempts including the first; 1 disables retry
     * @param mixed $baseDelaySeconds  backoff base used when `Retry-After` is absent/unparseable
     * @param mixed $maxDelaySeconds   cap on the delay BEFORE jitter
     * @param mixed $jitterFraction    additive jitter fraction
     *
     * @throws \InvalidArgumentException when a value has the wrong type or is out of range
     */
    public function __construct(
        mixed $maxAttempts = 3,
        mixed $baseDelaySeconds = 1.0,
        mixed $maxDelaySeconds = 60.0,
        mixed $jitterFraction = 0.25,
    ) {
        // is_int() rejects bool, float and numeric string alike — matching
        // TypeScript's Number.isInteger (false for `true` and for "3") and
        // Python's explicit bool/int check.
        if (! is_int($maxAttempts) || $maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'maxAttempts must be an integer >= 1 (got %s)',
                var_export($maxAttempts, true),
            ));
        }
        $floats = [];
        foreach ([
            'baseDelaySeconds' => $baseDelaySeconds,
            'maxDelaySeconds' => $maxDelaySeconds,
            'jitterFraction' => $jitterFraction,
        ] as $name => $value) {
            // int is accepted and widened — `1` is a natural literal and both
            // other SDKs take it. bool and string are not: is_int/is_float are
            // false for both.
            if (is_int($value)) {
                $value = (float) $value;
            }
            if (! is_float($value) || ! is_finite($value) || $value < 0) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must be a finite number >= 0 (got %s)',
                    $name,
                    var_export($value, true),
                ));
            }
            $floats[$name] = $value;
        }
        $baseDelaySeconds = $floats['baseDelaySeconds'];
        $maxDelaySeconds = $floats['maxDelaySeconds'];
        $jitterFraction = $floats['jitterFraction'];

        // Validate the COMPUTED worst case, not just the fields. Each can be
        // finite while their product is not: maxDelaySeconds = PHP_FLOAT_MAX
        // with jitterFraction = 1.0 passes every check above and then evaluates
        // to INF on the first 429, where the three SDKs fail three DIFFERENT
        // ways. A policy that validates and then cannot work is worse than one
        // that never validated — the failure lands during a rate-limit
        // incident.
        //
        // The bound is JS setTimeout's ceiling (2^31-1 ms) in seconds. PHP has
        // no such limit, but the three SDKs must accept the SAME policies: a
        // config every SDK takes and only two honour is exactly the divergence
        // this package exists to avoid.
        $worstCase = $maxDelaySeconds * (1 + $jitterFraction);
        if (! is_finite($worstCase) || $worstCase > self::MAX_TOTAL_DELAY_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'maxDelaySeconds * (1 + jitterFraction) must be <= %s seconds (got %s)',
                var_export(self::MAX_TOTAL_DELAY_SECONDS, true),
                var_export($worstCase, true),
            ));
        }

        $this->maxAttempts = $maxAttempts;
        $this->baseDelaySeconds = $baseDelaySeconds;
        $this->maxDelaySeconds = $maxDelaySeconds;
        $this->jitterFraction = $jitterFraction;
    }

    /**
     * Opt out of retrying.
     *
     * This is exactly `new self(maxAttempts: 1)` — the opt-out is an ordinary
     * policy value, not a `null`/`false` special case, so {@see Client::send()}
     * keeps a single code path.
     */
    public static function disabled(): self
    {
        return new self(maxAttempts: 1);
    }
}
