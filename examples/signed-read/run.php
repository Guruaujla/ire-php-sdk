<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Examples\SignedRead\SignedReadStarter;

$autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
if (! is_file($autoload)) {
    echo json_encode(['outcome' => 'configuration_invalid', 'code' => 'starter.dependencies_missing'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}
require $autoload;
require __DIR__.'/SignedReadStarter.php';
$env = [];
foreach (['IR_BASE_URL', 'IR_APP_ID', 'IR_KEY_ID', 'IR_SERVICE_KEY_SECRET', 'IR_TENANT_ID', 'IR_JOB_ID', 'IR_ALLOW_LOCAL'] as $name) {
    $value = getenv($name);
    if ($value !== false) {
        $env[$name] = $value;
    }
}
$report = SignedReadStarter::run($env);
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['outcome'] === 'configuration_invalid' ? 2 : (in_array($report['outcome'], ['empty_list', 'job_observed'], true) ? 0 : 1));
