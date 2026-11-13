<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Tests\Support\Clients;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * sdks/problem-decisions.json is the decision() table the TypeScript and
 * Python SDKs assert against. It is generated from this SDK
 * (tools/generate-problem-decisions.php); this test fails when the PHP table
 * changes without regenerating it, which would let the other SDKs drift.
 */
#[CoversClass(RuntimeProblemException::class)]
final class RuntimeProblemDecisionFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../problem-decisions.json';

    public function testAccountingTableEqualsTheSharedFixture(): void
    {
        $fixture = Clients::decodeFile(self::FIXTURE);
        $table = (new \ReflectionClass(RuntimeProblemException::class))->getReflectionConstant('ACCOUNTING_DECISIONS');
        self::assertNotFalse($table);
        self::assertSame($table->getValue(), $fixture['accountingDecisions'] ?? null);
    }

    public function testDecisionConstantsEqualTheSharedFixture(): void
    {
        $fixture = Clients::decodeFile(self::FIXTURE);
        $constants = [];
        foreach ((new \ReflectionClass(RuntimeProblemException::class))->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && is_string($constant->getValue())) {
                $constants[$constant->getName()] = $constant->getValue();
            }
        }
        self::assertSame($constants, $fixture['decisions'] ?? null);
    }

    public function testCommittedFixtureIsByteEqualToTheGeneratorOutput(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'problem-decisions-');
        self::assertIsString($temp);
        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/generate-problem-decisions.php')
                . ' ' . escapeshellarg($temp) . ' 2>&1';
            exec($command, $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
            self::assertSame(
                file_get_contents(self::FIXTURE),
                file_get_contents($temp),
                'sdks/problem-decisions.json is stale: run php tools/generate-problem-decisions.php and mirror the change in the TS and Python SDKs',
            );
        } finally {
            @unlink($temp);
        }
    }

    public function testDecisionsForDisconnectAndLifecycleCodes(): void
    {
        self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, (new RuntimeProblemException(503, 'oauth.disconnect_retryable'))->decision());
        self::assertSame(RuntimeProblemException::RETRY_SAME_REQUEST, (new RuntimeProblemException(409, 'oauth.lifecycle_busy'))->decision());
        self::assertSame(RuntimeProblemException::ASK_OPERATOR, (new RuntimeProblemException(409, 'connection.recovery_pending'))->decision());
    }

    public function testEveryFixtureCaseDecidesTheSame(): void
    {
        $cases = Clients::obj(Clients::decodeFile(self::FIXTURE), 'cases');
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            $case = Clients::obj(['c' => $case], 'c');
            $status = $case['status'] ?? null;
            self::assertIsInt($status);
            $code = Clients::str($case, 'code');
            self::assertSame(Clients::str($case, 'decision'), (new RuntimeProblemException($status, $code))->decision(), $status . ' ' . $code);
        }
    }
}
