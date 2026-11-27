<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\CommandReceipt;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class SalesDocumentDiscountWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_create_sales_invoice_draft_with_percentage_document_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
                ['item_description' => 'Item 2', 'quantity' => '1', 'unit_price' => '200.00'],
            ],
        ]);

        $this->assertSame('document', $invoice->discount_scope);
        $this->assertSame('percent', $invoice->discount_type);
        $this->assertSame('10.000000', (string) $invoice->discount_value);
        $this->assertSame('subtotal', $invoice->discount_basis);
        $this->assertSame(DocumentDiscountIntent::VERSION_CANONICAL, $invoice->discount_calculation_version);
        $this->assertSame(2, $invoice->currency_minor_units);

        $lines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('10.000000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('20.000000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('270.000000', (string) $invoice->grand_total_currency);
    }

    public function test_create_sales_invoice_draft_with_fixed_document_discount_vector_1(): void
    {
        // Vector 1 Benchmark: 350.00 ILS total basis, 17.00 ILS fixed discount
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '17.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
                ['item_description' => 'Item 2', 'quantity' => '1', 'unit_price' => '200.00'],
                ['item_description' => 'Item 3', 'quantity' => '1', 'unit_price' => '50.00'],
            ],
        ]);

        $lines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertSame('4.860000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('9.710000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('2.430000', (string) $lines[2]->allocated_document_discount);

        $totalAllocated = BigDecimal::of($lines[0]->allocated_document_discount)
            ->plus($lines[1]->allocated_document_discount)
            ->plus($lines[2]->allocated_document_discount);
        $this->assertSame('17.000000', (string) $totalAllocated);
        $this->assertSame('333.000000', (string) $invoice->grand_total_currency);
        $this->assertSame('subtotal', $invoice->discount_basis);
        $this->assertSame(DocumentDiscountIntent::VERSION_CANONICAL, $invoice->discount_calculation_version);
    }

    public function test_update_sales_invoice_draft_modifying_document_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Service A', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('10.000000', (string) $invoice->lines()->first()->allocated_document_discount);
        $this->assertSame('90.000000', (string) $invoice->grand_total_currency);

        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'discount_type' => 'fixed',
            'discount_value' => '20.00',
            'lines' => [
                ['item_description' => 'Service A', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('20.000000', (string) $updated->lines()->first()->allocated_document_discount);
        $this->assertSame('80.000000', (string) $updated->grand_total_currency);
    }

    public function test_update_omitted_discount_preserves_existing_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('fixed', $invoice->discount_type);
        $this->assertSame('15.000000', (string) $invoice->discount_value);
        $this->assertSame('document', $invoice->discount_scope);

        // Update omitting discount_type and discount_value
        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'notes' => 'Updated notes without discount fields',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('fixed', $updated->discount_type);
        $this->assertSame('15.000000', (string) $updated->discount_value);
        $this->assertSame('document', $updated->discount_scope);
        $this->assertSame('15.000000', (string) $updated->lines()->first()->allocated_document_discount);
        $this->assertSame('85.000000', (string) $updated->grand_total_currency);
    }

    public function test_update_explicit_clear_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        // Explicit clear via discount_type => none
        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'discount_type' => 'none',
            'discount_value' => '0',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertNull($updated->discount_type);
        $this->assertSame('0.000000', (string) $updated->discount_value);
        $this->assertSame('100.000000', (string) $updated->grand_total_currency);
        $this->assertSame('0.000000', (string) $updated->lines()->first()->allocated_document_discount);
    }

    public function test_reject_positive_header_discount_with_line_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.invalid_discount_scope'));

        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'discount_scope' => 'line',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);
    }

    public function test_discount_commands_require_lines_and_reject_both_scope_mismatches(): void
    {
        $lines = [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00']];
        $input = [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-11-25', 'lines' => $lines,
        ];
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, $input);
        $quote = $this->quote($input);
        foreach ([
            fn (array $patch) => app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, $patch),
            fn (array $patch) => app(UpdateQuotationAction::class)->execute($quote, $this->owner, $patch),
        ] as $update) {
            foreach ([
                [['discount_type' => 'fixed', 'discount_value' => '5'], 'sales.discount_change_requires_lines'],
                [['discount_type' => 'fixed', 'discount_value' => '5', 'discount_scope' => 'line', 'lines' => $lines], 'sales.invalid_discount_scope'],
                [['discount_type' => 'none', 'discount_value' => '0', 'discount_scope' => 'document',
                    'lines' => [$lines[0] + ['discount_type' => 'fixed', 'discount_value' => '5']]], 'sales.manual_line_discount_forbidden'],
            ] as [$patch, $key]) {
                try {
                    $update($patch);
                    $this->fail('Invalid discount patch saved.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame(__($key), $exception->getMessage());
                }
            }
        }
        $this->assertSame('100.000000', $invoice->fresh()->grand_total_currency);
        $this->assertSame('100.000000', $quote->fresh()->grand_total_currency);
    }

    public function test_invoice_editor_can_keep_discount_but_cannot_remove_it_without_permission(): void
    {
        $lines = [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00']];
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-11-25', 'lines' => $lines, 'discount_type' => 'fixed', 'discount_value' => '10.00',
        ]);
        $editor = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $editor->id, 'status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        setPermissionsTeamId($this->company->id);
        $editor->givePermissionTo(['sales.invoice.edit_draft', 'sales.invoice.change_price']);
        app(CompanyContext::class)->setCompany($this->company, $editor);
        $this->actingAs($editor);

        $action = app(UpdateSalesInvoiceDraftAction::class);
        $same = $action->execute($invoice, $editor, ['lines' => $lines]);
        $this->assertSame('90.000000', $same->grand_total_currency);

        try {
            $action->execute($same, $editor, ['discount_type' => 'none', 'discount_value' => '10.00', 'lines' => $lines]);
            $this->fail('The invoice discount was removed without permission.');
        } catch (AuthorizationException) {
            $this->assertSame('90.000000', $invoice->fresh()->grand_total_currency);
        }
    }

    public function test_forms_reuse_edit_precision_and_resolve_precision_for_new_currency(): void
    {
        $jod = Currency::where('code', 'JOD')->firstOrFail();
        $jod->update(['minor_units' => 0]);
        $input = [
            'customer_id' => $this->customer->id, 'currency_code' => 'JOD', 'exchange_rate' => '1',
            'issue_date' => '2026-11-25', 'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '15.25']],
        ];
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, $input);
        $quote = $this->quote($input);

        $jod->update(['minor_units' => 6]);
        app(CurrencyMinorUnitsResolver::class)->flush();
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['currency_minor_units' => null]);
        $this->assertSame(3, $invoice->fresh()->resolvedCurrencyMinorUnits());
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['currency_minor_units' => 0]);

        foreach ([InvoiceForm::class => $invoice, QuotationForm::class => $quote] as $component => $document) {
            Livewire::test($component, ['publicId' => $document->public_id])
                ->assertSet('currency_minor_units', 0)
                ->set('currency_code', 'ILS')->assertSet('currency_minor_units', 2)
                ->set('currency_code', 'JOD')->assertSet('currency_minor_units', 0);
            Livewire::test($component)
                ->assertSet('currency_minor_units', 2)
                ->set('currency_code', 'JOD')->assertSet('currency_minor_units', 6);
        }
    }

    public function test_pre_feature_create_and_update_receipts_replay_through_invoice_form(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-11-25',
            'lines' => [['item_description' => 'Legacy Item', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);
        $lineId = $draft->lines()->sole()->id;

        foreach (['create', 'update'] as $operation) {
            $editing = $operation === 'update';
            $form = $editing
                ? Livewire::test(InvoiceForm::class, ['publicId' => $draft->public_id])->set('due_date', null)->set('warehouse_id', null)
                : Livewire::test(InvoiceForm::class)
                    ->set('customer_id', $this->customer->id)
                    ->set('warehouse_id', null)
                    ->set('issue_date', '2026-11-25')
                    ->set('due_date', null)
                    ->set('notes', null)
                    ->set('terms', null)
                    ->set('document_locale', 'en')
                    ->set('lines.0.item_description', 'Legacy Item')
                    ->set('lines.0.unit_price', '100.00');
            $key = $form->get($editing ? 'updateRequestKey' : 'createRequestKey');
            $legacyCanonical = [
                'currency_code' => 'ILS',
                'customer_id' => $this->customer->id,
                'document_locale' => 'en',
                'due_date' => null,
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-11-25',
                'lines' => [[
                    'discount_type' => null,
                    'discount_value' => '0.000000',
                    'item_description' => 'Legacy Item',
                    'product_id' => null,
                    'product_unit_id' => null,
                    'quantity' => '1.000000',
                    'tax_rate_id' => null,
                    'unit_price' => '100.000000',
                ]],
                'notes' => null,
                'terms' => null,
                'warehouse_id' => null,
            ];
            if ($editing) {
                $legacyCanonical = ['__target_invoice_id' => $draft->id] + $legacyCanonical;
            }
            ksort($legacyCanonical);
            // Independently transcribed sorted 3d82f1c command envelope.
            $hash = hash('sha256', json_encode($legacyCanonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $receipt = CommandReceipt::create([
                'company_id' => $this->company->id,
                'user_id' => $this->owner->id,
                'action' => 'sales_invoice.'.$operation,
                'idempotency_key' => $key,
                'target_invoice_id' => $editing ? $draft->id : null,
                'sales_invoice_id' => $draft->id,
                'request_hash' => $hash,
            ]);

            $form->call('saveDraft')->assertHasNoErrors()->assertRedirect(route('invoices.show', $draft->public_id));
            $this->assertSame($hash, $receipt->fresh()->request_hash);
            $this->assertSame($lineId, $draft->lines()->sole()->id);
            $this->assertSame(1, SalesInvoice::where('company_id', $this->company->id)->count());

            try {
                $commands = app(SalesInvoiceCommands::class);
                $changed = ['notes' => 'Changed payload', 'customer_id' => $this->customer->id, 'currency_code' => 'ILS',
                    'exchange_rate' => '1', 'issue_date' => '2026-11-25',
                    'lines' => [['item_description' => 'Legacy Item', 'quantity' => '1', 'unit_price' => '100']]];
                if ($editing) {
                    $commands->update($draft, $this->owner, $changed, $key);
                } else {
                    $commands->create($this->company, $this->owner, $changed, $key);
                }
                $this->fail('A legacy receipt must reject a changed payload.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
    }

    public function test_invoice_form_digest_versions_document_discount_and_matches_legacy_when_absent(): void
    {
        $form = new InvoiceForm;
        $form->customer_id = $this->customer->id;
        $form->currency_code = 'ILS';
        $form->exchange_rate = '1.0000000000';
        $form->issue_date = '2026-11-25';
        $form->lines = [
            [
                'product_id' => null,
                'product_unit_id' => null,
                'item_description' => 'Item 1',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
            ],
        ];

        // When discount is none: computePayloadDigest matches computeLegacyPayloadDigest
        $form->discount_type = 'none';
        $form->discount_value = '0';
        $this->assertSame($form->computeLegacyPayloadDigest(), $form->computePayloadDigest());

        // When discount is active: computePayloadDigest is versioned (different from legacy)
        $form->discount_type = 'fixed';
        $form->discount_value = '10.00';
        $this->assertNull($form->computeLegacyPayloadDigest());
        $this->assertNotEmpty($form->computePayloadDigest());
    }

    public function test_quotation_conversion_preserves_document_discount_and_frozen_precision(): void
    {
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.000',
            'lines' => [
                ['item_description' => 'Quote Item 1', 'quantity' => '1', 'unit_price' => '100.000'],
                ['item_description' => 'Quote Item 2', 'quantity' => '1', 'unit_price' => '100.000'],
            ],
        ]);

        $this->assertSame(3, $quote->currency_minor_units);
        $this->assertSame('10.000000', (string) $quote->discount_value);

        $quote->transition(Quotation::STATUS_SENT, $this->owner);
        $quote->transition(Quotation::STATUS_ACCEPTED, $this->owner);

        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);

        $this->assertSame('document', $invoice->discount_scope);
        $this->assertSame('fixed', $invoice->discount_type);
        $this->assertSame('10.000000', (string) $invoice->discount_value);
        $this->assertSame(3, $invoice->currency_minor_units);

        $invLines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertSame('5.000000', (string) $invLines[0]->allocated_document_discount);
        $this->assertSame('5.000000', (string) $invLines[1]->allocated_document_discount);
        $this->assertSame('190.000000', (string) $invoice->grand_total_currency);
    }

    #[DataProvider('returnVectors')]
    public function test_frozen_precision_preserved_in_exact_exclusive_tax_return_vectors(
        int $scale, array $partial, array $partialBase, array $final, array $finalBase
    ): void {
        $currency = Currency::updateOrCreate(['code' => 'BHD'], ['name' => 'Dinar', 'symbol' => 'BD', 'minor_units' => $scale, 'active' => true]);
        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'BHD', 'enabled' => true, 'is_base' => false]);
        $tax = TaxRate::create([
            'company_id' => $this->company->id, 'code' => 'RETURN-VAT', 'name_ar' => 'ضريبة', 'name_en' => 'Return tax',
            'rate' => '16', 'calculation' => TaxRate::CALC_EXCLUSIVE, 'active' => true,
        ]);
        $invoice = $this->invoice(rate: '1.3333333333', currency: 'BHD', extra: [
            'discount_type' => 'fixed', 'discount_value' => '2.00',
            'lines' => [['item_description' => 'Service', 'quantity' => '3', 'unit_price' => '10.00', 'tax_rate_id' => $tax->id]],
        ]);
        $this->assertSame($scale, $invoice->currency_minor_units);
        $line = $invoice->lines()->sole();
        $currency->update(['minor_units' => $scale === 2 ? 6 : 2]);
        app(CurrencyMinorUnitsResolver::class)->flush();

        foreach ([['1', $partial, $partialBase], ['2', $final, $finalBase]] as [$quantity, $expected, $expectedBase]) {
            $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'sales_invoice_id' => $invoice->id,
                'issue_date' => '2026-11-25',
                'lines' => [['sales_invoice_line_id' => $line->id, 'quantity' => $quantity]],
            ]);
            $posted = app(PostSalesReturnAction::class)->execute($draft, $this->owner);
            foreach ([$draft, $posted] as $document) {
                $this->assertSame($scale, $document->currency_minor_units);
                $returnLine = $document->lines()->sole();
                foreach (['subtotal', 'discount', 'tax', 'total'] as $index => $field) {
                    $this->assertSame($expected[$index], $returnLine->getAttribute('line_'.$field));
                    $this->assertSame($expectedBase[$index], $returnLine->getAttribute('line_'.$field.'_base'));
                }
                foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $index => $field) {
                    $this->assertSame($expected[$index], $document->getAttribute($field.'_currency'));
                    $this->assertSame($expectedBase[$index], $document->getAttribute($field.'_base'));
                }
            }
        }
    }

    public static function returnVectors(): array
    {
        return [
            'zero decimals' => [0,
                ['11.000000', '1.000000', '1.000000', '11.000000'], ['14.666667', '1.333333', '1.333333', '14.666667'],
                ['19.000000', '1.000000', '3.000000', '21.000000'], ['25.333333', '1.333334', '4.000000', '28.000000'],
            ],
            'two decimals' => [2,
                ['10.010000', '0.670000', '1.490000', '10.830000'], ['13.346667', '0.893333', '1.986667', '14.440000'],
                ['19.990000', '1.330000', '2.990000', '21.650000'], ['26.653333', '1.773334', '3.986666', '28.866667'],
            ],
            'three decimals' => [3,
                ['10.001000', '0.667000', '1.493000', '10.827000'], ['13.334667', '0.889333', '1.990667', '14.436000'],
                ['19.999000', '1.333000', '2.987000', '21.653000'], ['26.665333', '1.777334', '3.982666', '28.870667'],
            ],
            'six decimals' => [6,
                ['10.000001', '0.666667', '1.493333', '10.826667'], ['13.333335', '0.888889', '1.991111', '14.435556'],
                ['19.999999', '1.333333', '2.986667', '21.653333'], ['26.666665', '1.777778', '3.982222', '28.871111'],
            ],
        ];
    }

    public function test_sales_return_meaningful_fixtures_through_canonical_posting_conserves_exact_residuals(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '10', 'unit_price' => '10.00'],
            ],
        ]);

        $posted = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $this->assertSame('90.000000', (string) $posted->grand_total_currency);
        $invLine = $posted->lines->firstOrFail();

        // 1. Partial return 4 out of 10
        $retDraft1 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-26',
            'lines' => [
                ['sales_invoice_line_id' => $invLine->id, 'quantity' => '4'],
            ],
        ]);

        $retPosted1 = app(PostSalesReturnAction::class)->execute($retDraft1, $this->owner);
        $this->assertSame('36.000000', (string) $retPosted1->grand_total_currency);

        // 2. Remaining return 6 out of 10
        $retDraft2 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-26',
            'lines' => [
                ['sales_invoice_line_id' => $invLine->id, 'quantity' => '6'],
            ],
        ]);

        $retPosted2 = app(PostSalesReturnAction::class)->execute($retDraft2, $this->owner);
        $this->assertSame('54.000000', (string) $retPosted2->grand_total_currency);

        // Exact conservation: 36.00 + 54.00 = 90.00
        $sum = BigDecimal::of((string) $retPosted1->grand_total_currency)
            ->plus((string) $retPosted2->grand_total_currency);
        $this->assertTrue($sum->isEqualTo(BigDecimal::of('90.000000')));
    }

    public function test_fully_discounted_service_invoice_no_journal_lines_guard(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '100.00',
            'lines' => [
                ['item_description' => 'Pure Service', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('0.000000', (string) $draft->grand_total_base);

        // 1. Early preview guard: preview reports blocker
        $commands = app(SalesInvoiceCommands::class);
        $preview = $commands->preview($draft, $this->owner);

        $blockerCodes = array_column($preview['blockers'], 'code');
        $this->assertContains('zero_journal_lines_cannot_post', $blockerCodes);
        $this->assertFalse($preview['can_execute']);

        // 2. Early post guard: posting directly throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.zero_journal_lines_cannot_post'));

        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_migration_down_refuses_rollback_when_discount_history_exists(): void
    {
        // Create draft with document discount
        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $migration = require database_path('migrations/2026_11_25_000001_add_document_discount_to_sales_and_quotations.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot rollback migration: sales, quotation, or return discount or currency precision history exists.');

        $migration->down();
    }

    public function test_migration_clean_rollback_and_remigrate_roundtrip_when_no_history_exists(): void
    {
        $migration = require database_path('migrations/2026_11_25_000001_add_document_discount_to_sales_and_quotations.php');

        $this->assertTrue(Schema::hasColumn('sales_invoices', 'discount_scope'));
        $this->assertTrue(Schema::hasColumn('sales_returns', 'currency_minor_units'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('sales_invoices', 'discount_scope'));
        $this->assertFalse(Schema::hasColumn('sales_returns', 'currency_minor_units'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('sales_invoices', 'discount_scope'));
        $this->assertTrue(Schema::hasColumn('sales_returns', 'currency_minor_units'));
    }

    public function test_legacy_line_discount_scope_preserved_when_no_document_discount_provided(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'lines' => [
                ['item_description' => 'Item with line discount', 'quantity' => '1', 'unit_price' => '100.00', 'discount_type' => 'fixed', 'discount_value' => '15.00'],
            ],
        ]);

        $this->assertSame('line', $invoice->discount_scope);
        $this->assertNull($invoice->discount_type);
        $line = $invoice->lines()->first();
        $this->assertSame('15.000000', (string) $line->manual_discount_amount);
        $this->assertSame('0.000000', (string) $line->allocated_document_discount);
        $this->assertSame('85.000000', (string) $invoice->grand_total_currency);
    }

    public function test_tax_inclusive_line_fails_closed_when_document_discount_specified(): void
    {
        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شامل ضريبة',
            'name_en' => 'Inclusive Tax',
            'rate' => '16.000000',
            'code' => 'INCL-DISCOUNT',
            'calculation' => 'inclusive',
            'active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.tax_inclusive_global_discount_forbidden'));

        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                [
                    'item_description' => 'Inclusive item',
                    'quantity' => '1',
                    'unit_price' => '116.00',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);
    }

    /** @return array<string, array{0: class-string}> */
    public static function salesDiscountDocumentForms(): array
    {
        return [
            'invoice' => [InvoiceForm::class],
            'quotation' => [QuotationForm::class],
        ];
    }

    #[DataProvider('salesDiscountDocumentForms')]
    public function test_blank_and_invalid_non_none_document_discounts_fail_preview_and_save_without_writes(string $component): void
    {
        $table = $component === InvoiceForm::class ? 'sales_invoices' : 'quotations';
        $saveMethod = $component === InvoiceForm::class ? 'saveDraft' : 'save';

        foreach (['fixed' => ['', '   ', '-5', '200.00', 5.5], 'percent' => ['', '   ', '-1', '101', 12.5]] as $type => $invalidValues) {
            foreach ($invalidValues as $invalidValue) {
                $form = Livewire::test($component)
                    ->set('customer_id', $this->customer->id)
                    ->set('currency_code', 'ILS')
                    ->set('exchange_rate', '1.0000000000')
                    ->set('issue_date', '2026-11-25')
                    ->set('lines.0.item_description', 'Service line')
                    ->set('lines.0.quantity', '2')
                    ->set('lines.0.unit_price', '50.00')
                    ->call('recalculate')
                    ->assertSet('documentTotalsValid', true)
                    ->assertSet('previewGrandTotal', '100.00')
                    ->call('toggleDiscountControl')
                    ->set('discount_type', $type)
                    ->set('discount_value', $invalidValue)
                    ->call('recalculate');

                $form->assertSet('documentTotalsValid', false)
                    ->assertSet('previewGrandTotal', '0.00')
                    ->assertSet('previewDiscountTotal', '0.00')
                    ->assertHasErrors(['discount_value']);

                $form->call($saveMethod)
                    ->assertHasErrors(['discount_value']);

                if ($component === InvoiceForm::class) {
                    $form->call('save', true)
                        ->assertHasErrors(['discount_value']);
                }

                $this->assertSame(0, DB::table($table)->where('company_id', $this->company->id)->count());
                $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
                $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
            }
        }
    }

    #[DataProvider('salesDiscountDocumentForms')]
    public function test_legitimate_zero_discount_and_zero_price_complete_line_remain_valid(string $component): void
    {
        $table = $component === InvoiceForm::class ? 'sales_invoices' : 'quotations';
        $saveMethod = $component === InvoiceForm::class ? 'saveDraft' : 'save';

        $form = Livewire::test($component)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-11-25')
            ->set('lines.0.item_description', 'Free consulting item')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '0.00')
            ->call('toggleDiscountControl')
            ->set('discount_type', 'fixed')
            ->set('discount_value', '0')
            ->call('recalculate');

        $form->assertHasNoErrors()
            ->assertSet('documentTotalsValid', true)
            ->assertSet('previewSubtotal', '0.000000')
            ->assertSet('previewGrandTotal', '0.00');

        $form->call($saveMethod)
            ->assertHasNoErrors()
            ->assertRedirect();

        $saved = DB::table($table)->where('company_id', $this->company->id)->first();
        $this->assertNotNull($saved);
        $this->assertNull($saved->discount_type);
        $this->assertTrue(BigDecimal::of((string) $saved->discount_value)->isZero());
    }

    #[DataProvider('salesDiscountDocumentForms')]
    public function test_adding_incomplete_row_invalidates_totals_until_completed_or_removed(string $component): void
    {
        $form = Livewire::test($component)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-11-25')
            ->set('lines.0.item_description', 'Initial service item')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '50.00')
            ->call('recalculate');

        $form->assertSet('documentTotalsValid', true)
            ->assertSet('previewGrandTotal', '100.00');

        $form->call('addLine')
            ->assertSet('documentTotalsValid', false)
            ->assertSet('previewGrandTotal', '0.00');

        $form->set('lines.0.quantity', '3')
            ->call('recalculate')
            ->assertSet('documentTotalsValid', false)
            ->assertSet('previewGrandTotal', '0.00');

        $form->set('lines.1.item_description', 'Additional delivery fee')
            ->set('lines.1.unit_price', '20.00')
            ->call('recalculate')
            ->assertHasNoErrors()
            ->assertSet('documentTotalsValid', true)
            ->assertSet('previewGrandTotal', '170.00');

        $form->call('addLine')
            ->assertSet('documentTotalsValid', false);

        $form->call('removeLine', 2)
            ->assertSet('documentTotalsValid', true)
            ->assertSet('previewGrandTotal', '170.00');
    }
}
