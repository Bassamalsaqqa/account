<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\UpdateCompanyMemberRoleAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Domain\Sales\Documents\DocumentData;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\PdfRendererService;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase5E\Phase5ETestCase;

class DocumentPresentationTest extends Phase5ETestCase
{
    private function invoice(string $currency = 'USD', string $price = '100', int $count = 1): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل تاريخي', 'name_en' => 'Historical customer', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => $currency, 'exchange_rate' => $currency === 'ILS' ? '1' : '3.50',
            'issue_date' => '2026-10-01', 'lines' => array_fill(0, $count, ['product_id' => null,
                'item_description' => 'وصف طويل مختلط Product Y / carton 12 × bottles مستند', 'quantity' => '1', 'unit_price' => $price]),
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_receipt_original_legs_keep_exact_currencies_and_exclude_later_applications(): void
    {
        $invoice = $this->invoice();
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '660', 'exchange_rate' => '1',
            'idempotency_key' => 'p9-receipt', 'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        $builder = app(DocumentDataBuilder::class);
        $before = $builder->build($payment);
        $this->assertSame('USD', $before->lines[0]['currency_code']);
        $this->assertSame('100.000000', $before->lines[0]['total']);
        $this->assertSame('ILS', $before->lines[0]['payment_currency_code']);
        $this->assertSame('330.000000', $before->lines[0]['payment_currency_amount']);
        $this->assertSame('ILS', $before->lines[0]['base_currency_code']);
        $this->assertSame('330.000000', $before->lines[0]['settlement_base_value']);
        $second = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-02',
            'lines' => [['product_id' => null, 'item_description' => 'Private later invoice', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $second = app(PostSalesInvoiceAction::class)->execute($second, $this->owner);
        app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-04', 'idempotency_key' => 'p9-later',
            'allocations' => [['sales_invoice_id' => $second->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']],
        ]);
        $this->assertSame($before->toArray(), $builder->build($payment->fresh())->toArray());
        $this->assertSame([], $builder->build($payment->fresh())->applications);
        $private = $builder->build($payment->fresh(), includeApplications: true);
        $this->assertCount(1, $private->applications);
        $this->assertSame($second->invoice_number, $private->applications[0]['item_description']);
        $this->assertSame('330.000000', $private->applications[0]['payment_currency_amount']);
    }

    public function test_actual_bilingual_pdf_rendering_preserves_historical_facts_and_writes_no_economics(): void
    {
        $invoice = $this->invoice('JOD', '3.123', 60);
        $original = $invoice->customer_snapshot['name_ar'];
        $invoice->customer->update(['name_ar' => 'Renamed customer', 'name_en' => 'Renamed customer']);
        $this->company->update(['name_ar' => 'Renamed company']);
        $snapshot = $this->economicSnapshot();
        $directory = base_path('.ai/delegations/phase9-implementation/pdf');
        File::ensureDirectoryExists($directory);
        foreach (['ar', 'en'] as $locale) {
            $data = app(DocumentDataBuilder::class)->build($invoice->fresh(), forPdf: true, locale: $locale);
            $html = app(DocumentRenderer::class)->html($data);
            $this->assertStringNotContainsString('Renamed customer', $html);
            $this->assertStringNotContainsString('Renamed company', $html);
            $this->assertStringContainsString('JOD', $html);
            $this->assertStringContainsString('3.123', $html);
            $bytes = app(PdfRendererService::class)->renderDocument($data);
            $this->assertStringStartsWith('%PDF-', $bytes);
            File::put($directory.'/invoice-'.$locale.'.pdf', $bytes);
            File::put($directory.'/invoice-'.$locale.'.html', $html);
        }
        $this->assertSame($original, $invoice->fresh()->customer_snapshot['name_ar']);
        $this->assertSame($snapshot, $this->economicSnapshot());
        $this->assertSame([], File::directories(storage_path('app/private/document-render')));
    }

    public function test_private_route_intersects_source_output_and_fresh_membership(): void
    {
        $invoice = $this->invoice('ILS');
        foreach ([['sales.invoice.view'], ['sales.document.pdf']] as $permissions) {
            $actor = $this->customActor($permissions);
            $this->activate($actor);
            $this->get(route('pdf.invoice', ['publicId' => $invoice->public_id, 'format' => 'print']))->assertForbidden();
        }
        $actor = $this->customActor(['sales.invoice.view', 'sales.document.pdf']);
        $this->activate($actor);
        $actor->load('roles', 'permissions');
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        $this->get(route('pdf.invoice', ['publicId' => $invoice->public_id, 'format' => 'print']))->assertRedirect();
    }

    public function test_oversized_document_refuses_before_template_delivery(): void
    {
        $data = new DocumentData('sales_invoice', 'ar', ['name' => 'Company'], ['name' => 'Customer'], ['number' => 'N'], array_fill(0, 501, []));
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentRenderer::class)->html($data);
    }

    public function test_all_five_sales_outputs_render_both_languages_without_economic_writes(): void
    {
        $invoice = $this->invoice('USD', '100');
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-01',
            'terms' => str_repeat('شروط تجارية Commercial terms. ', 70),
            'lines' => [['item_description' => 'عرض Quotation', 'quantity' => '2', 'unit_price' => '10', 'discount_type' => 'percent', 'discount_value' => '10']],
        ]);
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '1']],
        ]);
        $return = app(PostSalesReturnAction::class)->execute($return, $this->owner);
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $this->jodCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '5.123', 'exchange_rate' => '4.95', 'idempotency_key' => 'p9-jod', 'allocations' => [],
        ]);
        $statement = app(CustomerStatementQuery::class)->execute($invoice->customer, '2026-10-01', '2026-10-09');
        $before = $this->economicSnapshot();
        foreach (['ar', 'en'] as $locale) {
            $documents = array_map(fn ($source) => app(DocumentDataBuilder::class)->build($source, forPdf: true, locale: $locale, includeApplications: true), [$invoice, $quote, $return, $payment]);
            $documents[] = app(DocumentDataBuilder::class)->statement($statement, $locale);
            foreach ($documents as $data) {
                $html = app(DocumentRenderer::class)->html($data);
                $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
                $bytes = app(PdfRendererService::class)->renderDocument($data);
                $this->assertStringStartsWith('%PDF-', $bytes);
                File::put(base_path('.ai/delegations/phase9-implementation/pdf/'.$data->type.'-'.$locale.'.pdf'), $bytes);
            }
        }
        $this->assertSame($before, $this->economicSnapshot());
        $this->assertSame($invoice->invoice_number, app(DocumentDataBuilder::class)->build($return)->document['original_reference']);
    }

    public function test_owner_defaults_upgrade_and_protected_delegation(): void
    {
        $service = app(CompanyRoleService::class);
        $admin = Role::where('company_id', $this->company->id)->where('name', 'Administrator')->firstOrFail();
        $this->assertSame([], $admin->permissions()->whereIn('name', $service::PHASE9_PERMISSIONS)->pluck('name')->all());
        $before = $admin->permissions()->orderBy('name')->pluck('name')->all();
        $service->upgradeSalesCatalog($this->company);
        $this->assertSame($before, $admin->permissions()->orderBy('name')->pluck('name')->all());
        $actor = $this->customActor(['settings.roles.manage', 'settings.users.manage']);
        $role = $actor->roles()->first();
        app(UpdateRolePermissionsAction::class)->execute($this->company, $role, 'settings.documents.manage', true, $this->owner);
        $this->activate($actor);
        try {
            app(UpdateRolePermissionsAction::class)->execute($this->company, $role, 'catalogs.publish', true, $actor);
            $this->fail('Ordinary role management escalated publication authority.');
        } catch (AuthorizationException) {
            $this->assertFalse($role->fresh()->hasPermissionTo('catalogs.publish'));
        }
        $membership = CompanyUser::where('company_id', $this->company->id)->where('user_id', $actor->id)->firstOrFail();
        $this->expectException(AuthorizationException::class);
        app(UpdateCompanyMemberRoleAction::class)->execute($this->company, $membership, $role->name, $actor);
    }

    public function test_carton_units_tax_discount_and_stored_fx_survive_master_changes(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '100', 'unit_cost' => '10']]]);
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();
        $carton = ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'unit_id' => $cartonUnit->id, 'conversion_to_base' => '12', 'active' => true]);
        $tax = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, [
            'code' => 'VAT16', 'name_ar' => 'ضريبة', 'name_en' => 'VAT', 'rate' => '16', 'calculation' => 'exclusive', 'active' => true,
            'sales_tax_account_id' => LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_output')->value('id'),
        ]);
        $customer = $this->invoice()->customer;
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => '2026-10-05',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $carton->id, 'item_description' => 'Carton / كرتون', 'quantity' => '2', 'unit_price' => '100', 'discount_type' => 'percent', 'discount_value' => '10', 'tax_rate_id' => $tax->id]],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $before = app(DocumentDataBuilder::class)->build($invoice, forPdf: true, locale: 'en')->toArray();
        $cartonUnit->update(['name_en' => 'RENAMED UNIT']);
        $carton->update(['conversion_to_base' => '24', 'default_sale_price_base' => '999']);
        $tax->update(['rate' => '20']);
        $this->product->update(['name_en' => 'RENAMED PRODUCT']);
        $after = app(DocumentDataBuilder::class)->build($invoice->fresh(), forPdf: true, locale: 'en');
        $this->assertSame($before, $after->toArray());
        $this->assertSame('2.000000', $after->lines[0]['quantity']);
        $this->assertSame('Carton', $after->lines[0]['unit_name']);
        $this->assertSame('208.800000', $after->document['grand_total']);
        $this->assertSame('28.800000', $after->document['tax_total']);
        $this->assertSame('20.000000', $after->document['discount_total']);
        $this->assertSame('3.5000000000', $after->document['exchange_rate']);
    }

    /** @return array<string, string> */
    private function economicSnapshot(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'sales_invoice_lines', 'customer_payments', 'customer_payment_allocations', 'document_sequences'] as $table) {
            $result[$table] = hash('sha256', json_encode(DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
