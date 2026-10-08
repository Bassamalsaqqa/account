<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Spatie\Permission\Models\Role;

final class UiCsvIntegrationTest extends Phase8TestCase
{
    public function test_export_fetches_all_chunks_and_preserves_exact_decimal_and_safe_snapshot_text(): void
    {
        $query = new class
        {
            public array $calls = [];

            public function execute(Company $company, array $filters): ReportResult
            {
                app(ReportingGuard::class)->authorize($company, null, ['reports.sales.view', 'sales.invoice.view']);
                $page = $filters['page'];
                $this->calls[] = $page;
                $rows = [];
                for ($i = ($page - 1) * 100 + 1; $i <= min($page * 100, 101); $i++) {
                    $rows[] = ['document_number' => $i === 1 ? ' =WEBSERVICE("bad")' : 'INV-'.$i, 'business_date' => '2026-10-08', 'currency_code' => 'JOD', 'gross_sales_base' => '0.001000', 'net_sales_base' => '0.001000'];
                }

                return new ReportResult('sales.summary', $filters, [], $rows, ['base_currency_code' => 'ILS'], ['current_page' => $page, 'per_page' => 100, 'total' => 101, 'last_page' => 2]);
            }
        };
        app()->instance(SalesSummaryReportQuery::class, $query);
        $response = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('INV-101', $csv);
        $this->assertStringContainsString('0.001000', $csv);
        $this->assertStringContainsString("' =WEBSERVICE", $csv);
        $this->assertSame([1, 1, 2], $query->calls);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_permission_revocation_between_chunks_stops_the_stream(): void
    {
        $query = new class
        {
            public int $calls = 0;

            public function execute(Company $company, array $filters): ReportResult
            {
                app(ReportingGuard::class)->authorize($company, null, ['reports.sales.view', 'sales.invoice.view']);
                $this->calls++;
                if ($this->calls === 2) {
                    Role::where('company_id', $company->id)->where('name', 'Owner')->firstOrFail()->revokePermissionTo('reports.sales.view');
                }

                return new ReportResult('sales.summary', $filters, [], [['document_number' => 'VISIBLE', 'business_date' => '2026-10-08', 'currency_code' => 'ILS', 'gross_sales_base' => '1.000000']], ['base_currency_code' => 'ILS'], ['current_page' => 1, 'per_page' => 100, 'total' => 101, 'last_page' => 2]);
            }
        };
        app()->instance(SalesSummaryReportQuery::class, $query);
        $response = $this->get(route('reports.export', ['reportKey' => 'sales.summary']))->assertOk();
        $bufferLevel = ob_get_level();
        try {
            $response->streamedContent();
            $this->fail('Revoked export must stop.');
        } catch (StreamedResponseException $exception) {
            $this->assertInstanceOf(AuthorizationException::class, $exception->getPrevious() ?? $exception->getInnerException());
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    }

    public function test_malformed_filters_return_422_before_stream_headers(): void
    {
        $response = $this->get(route('reports.export', [
            'reportKey' => 'sales.summary',
            'filters' => ['customer_id' => 'not-an-id'],
        ]));
        $response->assertStatus(422);
    }

    public function test_missing_required_selection_returns_422_before_stream_headers(): void
    {
        $response = $this->get(route('reports.export', [
            'reportKey' => 'customers.statement',
            'filters' => [],
        ]));
        $response->assertStatus(422);
    }
}
