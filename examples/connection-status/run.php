<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionStatusJourney;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/ConnectionStatusJourney.php';
require __DIR__ . '/MockRuntimeTransport.php';

$tenantId = 'tenant_local_fixture';
$transport = new MockRuntimeTransport($tenantId);
$factory = new HttpFactory();
$client = new Client(
    $transport,
    $factory,
    $factory,
    'https://runtime.example.test',
    'app_local_fixture',
    'key_local_fixture',
    'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
    RetryPolicy::disabled(),
);
$journey = new ConnectionStatusJourney($client, $tenantId);

$cursor = null;
do {
    $page = $journey->listConnections(2, $cursor);
    foreach ($page->connections as $connection) {
        printf("%s: %s -> %s\n", $connection->id, $connection->runtimeStatus, $connection->recommendedAction());
    }
    $cursor = $page->nextCursor;
} while ($cursor !== null);

$reconciled = $journey->reconcileCallbackHint('conn_reconnect', 'connected');
printf(
    "callback hint matched: %s; authoritative action: %s\n",
    $reconciled->hintMatches ? 'yes' : 'no',
    $reconciled->recommendedAction(),
);
