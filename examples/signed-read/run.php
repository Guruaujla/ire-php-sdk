<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Examples\SignedRead\SignedReadStarter;

// From an SDK checkout: <sdk>/vendor/autoload.php. Installed as a dependency
// (php vendor/ezee/integration-runtime-sdk/examples/signed-read/run.php): the
// application's own vendor/autoload.php, four levels up.
$autoload = null;
foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    echo json_encode(['outcome' => 'configuration_invalid', 'code' => 'starter.dependencies_missing'], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(2);
}
require $autoload;
// The starter's cURL transport is Guzzle; a Laravel app already has it.
if (! class_exists(\GuzzleHttp\Client::class)) {
    echo json_encode(['outcome' => 'configuration_invalid', 'code' => 'starter.dependencies_missing'], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(2);
}
require __DIR__ . '/SignedReadStarter.php';
$env = [];
foreach (['IR_BASE_URL', 'IR_APP_ID', 'IR_KEY_ID', 'IR_SERVICE_KEY_SECRET', 'IR_TENANT_ID', 'IR_JOB_ID', 'IR_ALLOW_LOCAL'] as $name) {
    $value = getenv($name);
    if ($value !== false) {
        $env[$name] = $value;
    }
}
$report = SignedReadStarter::run($env);
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($report['outcome'] === 'configuration_invalid' ? 2 : (in_array($report['outcome'], ['empty_list', 'job_observed'], true) ? 0 : 1));
