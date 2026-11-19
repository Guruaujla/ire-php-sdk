<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\OutboxDecision;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Independent (clean-room) tests: OutboxDecision and Accounting::buildRequest's
 * erpCreatedAt (accounting-operations.md "Sync start date" and "Reliable
 * delivery: the outbox pattern").
 */
#[CoversClass(OutboxDecision::class)]
#[CoversClass(Accounting::class)]
final class OutboxDecisionTest extends TestCase
{
    private const CREATES = [
        'accounting.invoice.create',
        'accounting.payment.create',
        'accounting.refund.create',
        'accounting.credit_memo.create',
        'accounting.credit_memo.apply',
        'accounting.prepayment.create',
        'accounting.prepayment.refund',
    ];

    private const EXPECTED = [
        'retry_same_request' => 'pending',
        'fix_setup' => 'pending',
        'fix_request' => 'failed',
        'review_and_resubmit' => 'failed',
        'do_not_sync' => 'skipped',
        'stop_and_reconcile' => 'needs_person',
        'ask_operator' => 'needs_person',
        'fix_credentials' => 'needs_person',
    ];

    /** @return array<string, mixed> */
    private static function build(string $operation, ?\DateTimeInterface $erpCreatedAt = null): array
    {
        return Accounting::buildRequest($operation, 'ten_1', 'INV-1', 'invoice:INV-1:create', ['invoice' => ['issue_date' => '2026-11-12']], null, $erpCreatedAt);
    }

    // ── buildRequest erpCreatedAt ──────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function creates(): array
    {
        $out = [];
        foreach (self::CREATES as $op) {
            $out[$op] = [$op];
        }

        return $out;
    }

    #[DataProvider('creates')]
    public function testCreateFormatsRfc3339InItsOwnTimezone(string $operation): void
    {
        $at = new \DateTimeImmutable('2026-11-16 23:30:00', new \DateTimeZone('America/Toronto'));
        $request = self::build($operation, $at);
        // The local calendar date (16th) is kept, never converted to UTC (17th).
        self::assertSame('2026-11-16T23:30:00-05:00', $request['erp_created_at']);
    }

    public function testFixedOffsetsAndUtc(): void
    {
        $plus = new \DateTimeImmutable('2026-11-17T03:00:00+05:30');
        self::assertSame('2026-11-17T03:00:00+05:30', self::build('accounting.invoice.create', $plus)['erp_created_at']);
        $utc = new \DateTimeImmutable('2026-11-17T00:00:00Z');
        self::assertContains(self::build('accounting.invoice.create', $utc)['erp_created_at'], ['2026-11-17T00:00:00+00:00', '2026-11-17T00:00:00Z']);
        $mutable = new \DateTime('2026-11-18 09:12:00', new \DateTimeZone('-05:00'));
        self::assertSame('2026-11-18T09:12:00-05:00', self::build('accounting.payment.create', $mutable)['erp_created_at']);
    }

    public function testMatchesTheRuntimeRfc3339Shape(): void
    {
        $value = self::build('accounting.refund.create', new \DateTimeImmutable('2026-11-18 09:12:45.678', new \DateTimeZone('Asia/Kolkata')))['erp_created_at'];
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value);
        self::assertStringStartsWith('2026-11-18T09:12:45', $value);
    }

    public function testOmittedWhenNotGiven(): void
    {
        self::assertArrayNotHasKey('erp_created_at', self::build('accounting.invoice.create'));
    }

    public function testEveryNonCreateOperationThrows(): void
    {
        $nonCreates = array_values(array_diff(Accounting::OPERATIONS, self::CREATES));
        self::assertNotSame([], $nonCreates);
        foreach ($nonCreates as $op) {
            try {
                self::build($op, new \DateTimeImmutable('2026-11-18T09:12:00-05:00'));
                self::fail($op . ' accepted erpCreatedAt');
            } catch (\InvalidArgumentException) {
                // expected
            }
            self::assertArrayNotHasKey('erp_created_at', self::build($op));
        }
    }

    // ── OutboxDecision ─────────────────────────────────────────────────────

    public function testConstants(): void
    {
        self::assertSame(
            ['pending', 'failed', 'skipped', 'needs_person'],
            [OutboxDecision::PENDING, OutboxDecision::FAILED, OutboxDecision::SKIPPED, OutboxDecision::NEEDS_PERSON],
        );
    }

    public function testFromDecision(): void
    {
        foreach (self::EXPECTED as $decision => $state) {
            self::assertSame($state, OutboxDecision::fromDecision($decision), $decision);
        }
        foreach (['', 'retry', 'something_new', 'PENDING'] as $unknown) {
            self::assertSame(OutboxDecision::NEEDS_PERSON, OutboxDecision::fromDecision($unknown));
        }
    }

    public function testForJobErrorFollowsTheSharedTableAndIsNeverPending(): void
    {
        $fixture = Clients::decodeFile(__DIR__ . '/../../problem-decisions.json');
        $table = $fixture['accountingDecisions'] ?? [];
        self::assertIsArray($table);
        self::assertNotSame([], $table);
        foreach ($table as $code => $decision) {
            self::assertIsString($decision);
            $expected = self::EXPECTED[$decision];
            // A same-key resend returns the same failed job: nothing is pending.
            if ($expected === OutboxDecision::PENDING) {
                $expected = OutboxDecision::FAILED;
            }
            self::assertSame($expected, OutboxDecision::forJobError((string) $code), (string) $code);
        }
        self::assertSame(OutboxDecision::FAILED, OutboxDecision::forJobError('accounting.document_unlinked'));
        self::assertSame(OutboxDecision::SKIPPED, OutboxDecision::forJobError('accounting.before_sync_start'));
        self::assertSame(OutboxDecision::NEEDS_PERSON, OutboxDecision::forJobError('accounting.write_uncertain'));
        self::assertSame(OutboxDecision::NEEDS_PERSON, OutboxDecision::forJobError('accounting.brand_new_refusal'));
    }

    public function testForRuntimeProblemBelow500FollowsItsDecision(): void
    {
        self::assertSame('pending', OutboxDecision::for(new RuntimeProblemException(409, 'accounting.mapping_missing')));
        self::assertSame('failed', OutboxDecision::for(new RuntimeProblemException(409, 'accounting.tax_mismatch')));
        self::assertSame('failed', OutboxDecision::for(new RuntimeProblemException(400, 'request.validation_failed')));
        self::assertSame('needs_person', OutboxDecision::for(new RuntimeProblemException(409, 'accounting.idempotency_conflict')));
        self::assertSame('needs_person', OutboxDecision::for(new RuntimeProblemException(401, 'hmac.signature_invalid')));
        self::assertSame('pending', OutboxDecision::for(new RuntimeProblemException(429, 'rate_limited')));
    }

    public function testBeforeSyncStartDependsOnWhatWasCompared(): void
    {
        $refused = static fn(?string $compared) => new RuntimeProblemException(
            409,
            'accounting.before_sync_start',
            null,
            ['code' => 'accounting.before_sync_start', 'record_type' => 'invoice'] + ($compared === null ? [] : ['compared' => $compared]),
        );
        self::assertSame('failed', OutboxDecision::for($refused('document_date')));
        self::assertSame('skipped', OutboxDecision::for($refused('erp_created_at')));
        self::assertSame('skipped', OutboxDecision::for($refused(null)));
        self::assertSame('skipped', OutboxDecision::for(new RuntimeProblemException(409, 'accounting.before_sync_start')));
    }

    public function testAny5xxIsPending(): void
    {
        foreach ([500, 502, 503, 504, 599] as $status) {
            foreach (['internal_error', '', 'accounting.provider_unavailable', 'accounting.brand_new'] as $code) {
                self::assertSame('pending', OutboxDecision::for(new RuntimeProblemException($status, $code)), $status . ' ' . $code);
            }
        }
    }

    public function testInvalidArgumentIsFailed(): void
    {
        try {
            self::build('accounting.invoice.update', new \DateTimeImmutable('2026-11-18T09:12:00-05:00'));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('failed', OutboxDecision::for($e));
        }
        self::assertSame('failed', OutboxDecision::for(new \InvalidArgumentException('x')));
    }

    public function testAnyOtherThrowableIsPending(): void
    {
        self::assertSame('pending', OutboxDecision::for(new \RuntimeException('network down')));
        self::assertSame('pending', OutboxDecision::for(new \UnexpectedValueException('bad body')));
        self::assertSame('pending', OutboxDecision::for(new \Error('fatal')));
        self::assertSame('pending', OutboxDecision::for(new \TypeError('type')));
    }
}
