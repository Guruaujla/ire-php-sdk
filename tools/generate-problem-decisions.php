<?php

declare(strict_types=1);

/*
 * Writes sdks/problem-decisions.json: the RuntimeProblemException::decision()
 * table, shared with the TypeScript and Python SDKs.
 *
 * The PHP SDK is the reference. This script reads its accounting-code table
 * by reflection and runs decision() over a fixed set of (HTTP status, code)
 * inputs that covers every branch, so the file is the PHP behaviour, never a
 * hand-written copy of it. Each SDK's test suite asserts its own table equals
 * the file (PHP: RuntimeProblemDecisionFixtureTest), so a change in one SDK
 * that is not made in the others fails a test.
 *
 * Deterministic: running it twice yields byte-identical output. Regenerate
 * with `php tools/generate-problem-decisions.php` after changing the PHP table,
 * then mirror the change in the TypeScript and Python SDKs. The inputs name
 * every explicit non-accounting code decision() branches on; add a new one
 * here when decision() gains it. RuntimeProblemDecisionFixtureTest fails when
 * the committed file differs from this script's output.
 *
 * No composer autoload needed, like generate-wire-fixtures.php.
 */

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;

require __DIR__ . '/../src/Exception/RuntimeProblemException.php';

$reflection = new ReflectionClass(RuntimeProblemException::class);
$accountingConstant = $reflection->getReflectionConstant('ACCOUNTING_DECISIONS');
if ($accountingConstant === false) {
    fwrite(STDERR, "RuntimeProblemException::ACCOUNTING_DECISIONS is missing\n");
    exit(1);
}
/** @var array<string, string> $table */
$table = $accountingConstant->getValue();
$decisions = [];
foreach ($reflection->getReflectionConstants() as $constant) {
    if ($constant->isPublic() && is_string($constant->getValue())) {
        $decisions[$constant->getName()] = $constant->getValue();
    }
}

// Every branch of decision() outside the accounting table, then every code in it.
$inputs = [
    [409, 'accounting.some_future_code'],
    [400, 'accounting.some_future_code'],
    [400, 'accounting.provider_refused'],
    [409, 'accounting.provider_refused'],
    [429, 'accounting.provider_refused'],
    [502, 'accounting.provider_refused'],
    [429, 'rate_limit.quota_exceeded'],
    [429, 'rate_limit.shield_shed'],
    [503, 'tenant_enablement_unavailable'],
    [401, 'hmac.nonce_reused'],
    [401, 'hmac.signature_invalid'],
    [401, 'http.401'],
    [403, 'provider.operation_disabled'],
    [403, 'tenant.scope_mismatch'],
    [409, 'recipe_version_retired'],
    [409, 'idempotency.conflict'],
    [409, 'oauth.lifecycle_busy'],
    [503, 'oauth.lifecycle_busy'],
    [409, 'connection.recovery_pending'],
    [503, 'connection.recovery_pending'],
    [500, 'internal_error'],
    [503, 'oauth.disconnect_retryable'],
    [409, 'oauth.disconnect_retryable'],
    [400, 'tenant_enablement_unavailable'],
    [409, 'hmac.nonce_reused'],
    [400, 'recipe_version_retired'],
    [502, 'http.502'],
    [400, 'request.validation_failed'],
    [404, 'connection.not_found'],
    [422, 'something.else'],
    [200, 'http.200'],
];
foreach (array_keys($table) as $code) {
    $inputs[] = [409, $code];
}
$cases = [];
foreach ($inputs as [$status, $code]) {
    $cases[] = ['status' => $status, 'code' => $code, 'decision' => (new RuntimeProblemException($status, $code))->decision()];
}

$json = json_encode([
    '$comment' => 'RuntimeProblem decision() table shared by the PHP, TypeScript and Python SDKs. Generated from the PHP SDK by sdks/php/tools/generate-problem-decisions.php; do not hand-edit. Each SDK test asserts its own table equals this file.',
    'decisions' => $decisions,
    'accountingDecisions' => $table,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

// Optional first argument: write there instead (the drift test writes to a temp file).
$target = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : __DIR__ . '/../../problem-decisions.json';
file_put_contents($target, $json . "\n");
fwrite(STDOUT, 'wrote ' . count($cases) . ' cases to ' . $target . "\n");
