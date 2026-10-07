<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\QuickBooksInvoiceJourney;
use Ezee\IntegrationRuntime\Examples\QuickBooksInvoice\RuntimeIdentity;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../examples/quickbooks-invoice/QuickBooksInvoiceJourney.php';

final class NeverCalledExampleTransport implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('contract test does not send HTTP requests');
    }
}

/** @phpstan-import-type InvoiceRequest from QuickBooksInvoiceJourney */
final class QuickBooksInvoiceExampleContractTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function contents(string $relativePath): string
    {
        $contents = file_get_contents(self::repositoryRoot() . '/' . $relativePath);
        self::assertIsString($contents, "unable to read {$relativePath}");

        return $contents;
    }

    private static function section(string $source, string $start, string $end): string
    {
        $startAt = strpos($source, $start);
        self::assertNotFalse($startAt, "missing contract marker {$start}");
        $endAt = strpos($source, $end, $startAt + strlen($start));
        self::assertNotFalse($endAt, "missing contract boundary {$end}");

        return substr($source, $startAt, $endAt - $startAt);
    }

    /** @return InvoiceRequest */
    private function exampleRequest(): array
    {
        $factory = new HttpFactory();
        $client = new Client(
            new NeverCalledExampleTransport(),
            $factory,
            $factory,
            'https://runtime.example.test',
            'app_erp_example',
            'key_example',
            self::SECRET,
            RetryPolicy::disabled(),
        );
        $journey = new QuickBooksInvoiceJourney(
            $client,
            new RuntimeIdentity('app_erp_example', 'key_example', 'tenant_example_001'),
        );

        return $journey->buildInvoiceRequest(
            'conn_qb_001',
            'erp-invoice-1001',
            'qb-customer-12',
            'qb-item-34',
            'qb-tax-code-56',
            '100.00',
            'taxable',
        );
    }

    public function testExampleRoutesAndResponsesExistInCurrentPublicOpenApi(): void
    {
        $openApi = self::contents('openapi/integration-runtime.openapi.yaml');

        $connect = self::section(
            $openApi,
            '  /v1/connections/connect-session:',
            '  /v1/connections/oauth/callback:',
        );
        self::assertStringContainsString('operationId: createConnectSession', $connect);
        self::assertStringContainsString('$ref: "#/components/schemas/CreateConnectSessionRequest"', $connect);
        self::assertStringContainsString('"201":', $connect);

        $execute = self::section(
            $openApi,
            '  /v1/operations/execute:',
            '  /v1/sync-jobs:',
        );
        self::assertStringContainsString('operationId: executeOperation', $execute);
        self::assertStringContainsString('$ref: "#/components/schemas/OperationsExecuteRequest"', $execute);
        self::assertStringContainsString('"202":', $execute);
        self::assertStringContainsString('"409":', $execute);
        self::assertStringContainsString('erp.quickbooks.invoice.created v4', $execute);
        self::assertStringContainsString('historical v3 jobs remain replayable', $execute);
        self::assertStringContainsString('TaxExcluded calculation and tax_declarations', $execute);

        $poll = self::section(
            $openApi,
            '  /v1/sync-jobs/{id}:',
            '  /v1/sync-jobs/{id}/replay:',
        );
        self::assertStringContainsString('operationId: getSyncJob', $poll);
        self::assertMatchesRegularExpression(
            '/name: tenant_id\R\s+required: true/',
            $poll,
        );
        self::assertStringContainsString('"200":', $poll);
    }

    public function testExampleTopLevelRequestMatchesCurrentOpenApiSchema(): void
    {
        $openApi = self::contents('openapi/integration-runtime.openapi.yaml');
        $schema = self::section(
            $openApi,
            '    OperationsExecuteRequest:',
            '    OperationsExecuteResult:',
        );
        $request = $this->exampleRequest();

        foreach (
            [
                'tenant_id',
                'provider_key',
                'connection_id',
                'recipe_key',
                'recipe_version',
                'trigger_type',
                'object_type',
                'internal_object_id',
                'payload',
            ] as $required
        ) {
            self::assertArrayHasKey($required, $request);
            self::assertStringContainsString("        - {$required}", $schema);
        }
        self::assertSame('quickbooks', $request['provider_key']);
        self::assertSame('erp.quickbooks.invoice.created', $request['recipe_key']);
        self::assertSame(3, $request['recipe_version']);
        self::assertSame('invoice', $request['object_type']);
    }

    public function testHistoricalV3ExampleMatchesRegisteredRuntimeValidatorAndRecipe(): void
    {
        $validator = self::contents('src/recipes/quickbooks-tax-envelope.ts');
        $recipe = self::contents('src/recipes/erp.quickbooks.invoice-created.v3.ts');
        $request = $this->exampleRequest();
        $payload = $request['payload'];
        $invoice = $payload['invoice'];
        $lines = $invoice['Line'];

        self::assertSame('TaxExcluded', $invoice['GlobalTaxCalculation']);
        self::assertNotSame('', $invoice['CustomerRef']['value']);
        self::assertArrayNotHasKey('customer', $payload);
        self::assertArrayNotHasKey('items', $payload);
        foreach (['Id', 'SyncToken', 'sparse', 'CurrencyRef', 'ExchangeRate'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $invoice);
        }

        $declarations = $payload['tax_declarations'];
        self::assertCount(count($lines), $declarations);
        foreach ($lines as $index => $line) {
            self::assertSame('SalesItemLineDetail', $line['DetailType']);
            self::assertNotSame('', $line['SalesItemLineDetail']['ItemRef']['value']);
            self::assertNotSame('', $line['SalesItemLineDetail']['TaxCodeRef']['value']);
            self::assertSame($index, $declarations[$index]['line_index']);
            self::assertSame('taxable', $declarations[$index]['treatment']);
        }

        // Drift guards against the executable runtime sources, not an example-
        // owned fixture. A changed pilot contract forces this example to be
        // reviewed alongside the canonical validator/recipe.
        self::assertStringContainsString(
            'const TREATMENTS = new Set(["taxable", "zero_rated", "exempt", "not_applicable"]);',
            $validator,
        );
        self::assertStringContainsString('for (const field of ["Id", "SyncToken", "sparse"])', $validator);
        self::assertStringContainsString('for (const field of ["CurrencyRef", "ExchangeRate"])', $validator);
        self::assertStringContainsString('GlobalTaxCalculation', $validator);
        self::assertStringContainsString('recipeKey: "erp.quickbooks.invoice.created"', $recipe);
        self::assertStringContainsString('operationKey: "quickbooks.invoice.create"', $recipe);
        self::assertStringContainsString('version: 3', $recipe);
    }

    public function testCallbackHandoffFieldsMatchCurrentRuntimeImplementation(): void
    {
        $service = self::contents('src/auth/connect-session-service.ts');

        self::assertStringContainsString('connection_id: connectionRow.id', $service);
        self::assertStringContainsString('status: "connected"', $service);
        self::assertStringContainsString('status: "already_connected"', $service);
        self::assertStringContainsString('...(row.state ? { state: row.state } : {})', $service);
        self::assertStringContainsString('{ status: "failed", reason:', $service);
    }
}
