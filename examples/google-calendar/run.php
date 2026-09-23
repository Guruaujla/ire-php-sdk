<?php

declare(strict_types=1);

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\ConnectionStatus\ConnectionStatusJourney;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarReadJourney;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\CalendarRuntimeIdentity;
use Ezee\IntegrationRuntime\Examples\GoogleCalendar\MockRuntimeTransport;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/connection-status/ConnectionStatusJourney.php';
require __DIR__ . '/CalendarReadJourney.php';
require __DIR__ . '/MockRuntimeTransport.php';

if ((getenv('IR_EXAMPLE_MODE') ?: 'mock') !== 'mock') {
    throw new RuntimeException(
        'This example is intentionally mock-only; it cannot provision Calendar consent through the public connect-session route. '
        . 'Do not point it at staging or production.',
    );
}

$identity = new CalendarRuntimeIdentity(
    'app_erp_mock',
    'key_calendar_mock_process_only',
    'tenant_calendar_mock_001',
);
$transport = new MockRuntimeTransport($identity->tenantId, $identity->appId);
$factory = new HttpFactory();
$client = new Client(
    $transport,
    $factory,
    $factory,
    'https://runtime.mock.invalid',
    $identity->appId,
    $identity->keyId,
    rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
    RetryPolicy::disabled(),
);
$connections = new ConnectionStatusJourney($client, $identity->tenantId);
$journey = new CalendarReadJourney(
    $client,
    $identity,
    $connections,
    sleep: static function (float $seconds): void {},
);

// The fixture starts from a synthetic, already-authorized grant. Public connect-session
// currently rejects google-calendar; provisioning/consent is deliberately not demonstrated.
$grant = $journey->getConnectedGrant($transport->grantId());
echo "1. Verified the pre-authorized synthetic grant against authoritative connection and grant reads.\n";

$discovery = $journey->submitDiscovery($grant, 'erp.calendar.discovery.mock.001');
$discoveryPages = $journey->collectDiscoveryPages($grant, $discovery);
$discoveryPage = null;
$calendarId = null;
foreach ($discoveryPages as $page) {
    foreach ($page->items as $item) {
        $itemIdentity = $item['identity'] ?? null;
        $candidate = is_array($itemIdentity) ? ($itemIdentity['calendarId'] ?? null) : null;
        if (is_string($candidate) && $candidate === $transport->calendarId()) {
            $discoveryPage = $page;
            $calendarId = $candidate;
            break 2;
        }
    }
}
if ($discoveryPage === null || $calendarId === null) {
    throw new RuntimeException('Synthetic discovery did not return a selectable calendar.');
}
$resource = $journey->selectResource(
    $grant,
    $discoveryPage->pageId,
    $calendarId,
    'erp.calendar.selection.mock.001',
);
echo "2. Traversed " . count($discoveryPages) . " discovery page(s), then selected an explicit resource binding.\n";

$read = $journey->submitEventRead(
    $grant,
    $resource,
    '2026-03-01T00:00:00Z',
    '2026-04-01T00:00:00Z',
    'erp.calendar.read.mock.001',
);
$pages = $journey->collectEventPages($grant, $resource, $read);
$eventCount = array_sum(array_map(static fn($page): int => count($page->items), $pages));
echo "3. Polled and fetched {$eventCount} event(s) across " . count($pages) . " bounded public page(s).\n";
echo "4. Both discovery and events resumed from empty public pages through opaque runtime handles.\n";
echo "Mock journey complete: no provider request, credential access, remote migration, or live enablement occurred.\n";
