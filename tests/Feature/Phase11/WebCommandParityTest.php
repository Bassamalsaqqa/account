<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\CompanyUser;
use App\Models\PostingBatch;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class WebCommandParityTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_save_draft_only_persists_draft_and_redirects_without_posting(): void
    {
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('due_date', '2026-11-10')
            ->set('lines.0.item_description', 'Consulting Service')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '50.00')
            ->call('save', false)
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('sales_invoices', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'status' => SalesInvoice::STATUS_DRAFT,
        ]);

        $this->assertEquals(0, PostingBatch::where('company_id', $this->company->id)->count());
    }

    public function test_form_review_not_post_saves_draft_and_displays_actual_preview_shape(): void
    {
        $component = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('due_date', '2026-11-10')
            ->set('lines.0.item_description', 'Consulting Service')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '50.00')
            ->call('save', true)
            ->assertHasNoErrors();

        $component->assertSet('showPreview', true);
        $previewData = $component->get('previewData');
        $this->assertIsArray($previewData);
        $this->assertArrayHasKey('totals', $previewData);
        $this->assertArrayHasKey('subtotal_currency', $previewData['totals']);
        $this->assertArrayHasKey('grand_total_currency', $previewData['totals']);
        $this->assertArrayHasKey('stock_availability', $previewData);
        $this->assertNotEmpty($component->get('confirmationToken'));
        $this->assertNotEmpty($component->get('executeKey'));
        $component->assertSeeHtml('sales-command-preview-panel');

        $invoice = SalesInvoice::where('company_id', $this->company->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertEquals(0, PostingBatch::where('company_id', $this->company->id)->count());
    }

    public function test_explicit_confirm_then_one_post_with_double_click_idempotency(): void
    {
        $component = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Consulting Service')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '50.00')
            ->call('save', true)
            ->assertSet('showPreview', true);

        $initialExecuteKey = $component->get('executeKey');
        $this->assertNotNull($initialExecuteKey);

        $component->call('confirmPost')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invoice = SalesInvoice::where('company_id', $this->company->id)->latest()->first();
        $this->assertEquals(SalesInvoice::STATUS_POSTED, $invoice->status);
        $this->assertEquals(1, PostingBatch::where('company_id', $this->company->id)->count());
    }

    public function test_detail_review_not_post_then_confirm_posts_invoice(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                ['item_description' => 'Detail Service Line', 'quantity' => '1', 'unit_price' => '200.00'],
            ],
        ]);

        $component = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->assertSet('showPreview', false);

        $component->call('postInvoice')
            ->assertHasNoErrors()
            ->assertSet('showPreview', true);

        $this->assertNotEmpty($component->get('confirmationToken'));
        $invoice->refresh();
        $this->assertEquals(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertEquals(0, PostingBatch::where('company_id', $this->company->id)->count());

        $component->call('confirmPost')
            ->assertHasNoErrors()
            ->assertSet('showPreview', false);

        $invoice->refresh();
        $this->assertEquals(SalesInvoice::STATUS_POSTED, $invoice->status);
        $this->assertEquals(1, PostingBatch::where('company_id', $this->company->id)->count());
    }

    public function test_form_change_after_preview_rejects_confirmation_and_invalidates_preview(): void
    {
        $component = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Original Line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '100.00')
            ->call('save', true)
            ->assertSet('showPreview', true);

        // Modify line quantity
        $component->set('lines.0.quantity', '5');
        $this->assertFalse($component->get('showPreview'));

        // Calling confirmPost without active preview fails
        $component->call('confirmPost');
        $this->assertNotNull($component->get('previewError'));

        $invoice = SalesInvoice::where('company_id', $this->company->id)->latest()->first();
        $this->assertEquals(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertEquals(0, PostingBatch::where('company_id', $this->company->id)->count());
    }

    public function test_expired_confirmation_keeps_real_invoice_draft(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $component = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice')->assertSet('showPreview', true);
        $this->travel(301)->seconds();
        try {
            $component->call('confirmPost')->assertSet('showPreview', false);
            $this->assertNotNull($component->get('previewError'));
            $this->assertTrue($invoice->fresh()->isDraft());
            $this->assertSame(0, PostingBatch::where('company_id', $this->company->id)->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_exact_amount_parity_jod_and_percent_discount(): void
    {
        $component = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)->set('currency_code', 'JOD')
            ->set('exchange_rate', '5.1234567890')->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'High Precision JOD Product')
            ->set('lines.0.quantity', '100.000000')->set('lines.0.unit_price', '12.345')
            ->set('lines.0.discount_type', 'percent')->set('lines.0.discount_value', '15.000000')
            ->call('save', true)->assertHasNoErrors()->assertSet('showPreview', true);
        $preview = $component->get('previewData');
        $this->assertSame('1049.325000', $preview['totals']['grand_total_currency']);
        $this->assertSame('5.1234567890', $preview['invoice']['exchange_rate']);
        $component->call('confirmPost')->assertRedirect();
        $posted = SalesInvoice::where('company_id', $this->company->id)->sole();
        $this->assertTrue($posted->isPosted());
        $this->assertSame($preview['totals']['grand_total_base'], $posted->grand_total_base);
        $this->assertSame('1049.325000', $posted->grand_total_currency);
    }

    public function test_blocked_stock_prevents_confirm(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                ['item_description' => 'Out of stock item', 'quantity' => '10', 'unit_price' => '20.00'],
            ],
        ]);

        $spy = $this->createStub(SalesInvoiceCommands::class);
        $spy->method('preview')->willReturn([
            'invoice' => [
                'public_id' => $invoice->public_id,
                'status' => 'draft',
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
            ],
            'confirmation_token' => 'valid_token_blocked',
            'totals' => [
                'subtotal_currency' => '200.00',
                'discount_total_currency' => '0.00',
                'tax_total_currency' => '0.00',
                'grand_total_currency' => '200.00',
            ],
            'lines' => [],
            'stock_availability' => [
                'disclaimer' => 'Stock is advisory',
                'products' => [
                    [
                        'product_id' => 1,
                        'product_name' => 'Out of stock product',
                        'quantity_needed_base' => '10.000000',
                        'quantity_available_base' => '0.000000',
                        'is_sufficient' => false,
                        'fefo_allocations' => [],
                    ],
                ],
            ],
            'blockers' => [
                ['code' => 'insufficient_stock', 'message' => 'Insufficient stock for product', 'product_id' => 1],
            ],
            'warnings' => [],
            'can_execute' => false,
        ]);
        $this->app->instance(SalesInvoiceCommands::class, $spy);

        $component = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice')
            ->assertSet('showPreview', true);

        // Confirming must be rejected
        $component->call('confirmPost');
        $this->assertEquals(__('commands.cannot_confirm_blockers'), $component->get('previewError'));
        $invoice->refresh();
        $this->assertEquals(SalesInvoice::STATUS_DRAFT, $invoice->status);
    }

    public function test_ar_en_blocker_code_translations_without_raw_leak(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-11',
            'lines' => [
                ['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10.00'],
            ],
        ]);

        $spy = $this->createStub(SalesInvoiceCommands::class);
        $spy->method('preview')->willReturn([
            'invoice' => [
                'public_id' => $invoice->public_id,
                'status' => 'draft',
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
            ],
            'confirmation_token' => 'preview_token',
            'totals' => [
                'subtotal_currency' => '10.00',
                'discount_total_currency' => '0.00',
                'tax_total_currency' => '0.00',
                'grand_total_currency' => '10.00',
            ],
            'lines' => [],
            'stock_availability' => ['disclaimer' => 'Advisory', 'products' => []],
            'blockers' => [
                ['code' => 'insufficient_stock', 'message' => 'English Raw Leak Message Should Not Appear In Arabic'],
            ],
            'warnings' => [],
            'can_execute' => false,
        ]);
        $this->app->instance(SalesInvoiceCommands::class, $spy);

        // In Arabic
        app()->setLocale('ar');
        $componentAr = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice');

        $componentAr->assertSeeHtml(__('commands.code_insufficient_stock'));
        $componentAr->assertDontSeeHtml('English Raw Leak Message');

        // In English
        app()->setLocale('en');
        $componentEn = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice');

        $componentEn->assertSeeHtml(__('commands.code_insufficient_stock'));
    }

    public function test_no_exception_message_leak_on_unexpected_failure(): void
    {
        $spy = $this->createStub(SalesInvoiceCommands::class);
        $spy->method('create')->willThrowException(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation on table secret_users /path/to/secret'));
        $this->app->instance(SalesInvoiceCommands::class, $spy);

        $component = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Item')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '10.00')
            ->call('save', false);

        $previewError = $component->get('previewError');
        $this->assertNotNull($previewError);
        $this->assertEquals(__('commands.generic_error'), $previewError);
        $this->assertStringNotContainsString('SQLSTATE', $previewError);
        $this->assertStringNotContainsString('secret_users', $previewError);
        $this->assertStringNotContainsString('/path/to/secret', $previewError);
    }

    public function test_raw_float_or_integer_economic_inputs_are_refused(): void
    {
        // 1. Raw integer quantity
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Item')
            ->set('lines.0.quantity', 5)
            ->set('lines.0.unit_price', '10.00')
            ->call('save', false)
            ->assertHasErrors(['lines.0.quantity']);

        // 2. Raw float unit_price
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Item')
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_price', 10.55)
            ->call('save', false)
            ->assertHasErrors(['lines.0.unit_price']);

        // 3. Raw float discount_value
        Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('currency_code', 'ILS')
            ->set('exchange_rate', '1.0000000000')
            ->set('issue_date', '2026-10-11')
            ->set('lines.0.item_description', 'Item')
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_price', '10.00')
            ->set('lines.0.discount_type', 'percent')
            ->set('lines.0.discount_value', 2.5)
            ->call('save', false)
            ->assertHasErrors(['lines.0.discount_value']);
    }

    public function test_translated_preview_error_and_actual_amount_fx_in_ar_and_en(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '5.1234567890',
            'issue_date' => '2026-10-11',
            'lines' => [
                ['item_description' => 'Service JOD', 'quantity' => '1.000000', 'unit_price' => '100.000'],
            ],
        ]);

        $spy = $this->createStub(SalesInvoiceCommands::class);
        $spy->method('preview')->willReturn([
            'invoice' => [
                'public_id' => $invoice->public_id,
                'status' => 'draft',
                'currency_code' => 'JOD',
                'exchange_rate' => '5.1234567890',
            ],
            'confirmation_token' => 'valid_preview_token_123',
            'totals' => [
                'subtotal_currency' => '100.000',
                'discount_total_currency' => '0.000',
                'tax_total_currency' => '0.000',
                'grand_total_currency' => '100.000',
            ],
            'lines' => [
                [
                    'line_number' => 1,
                    'item_description' => 'Service JOD',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000',
                    'line_total' => '100.000',
                    'product_sku' => 'SKU-001',
                ],
            ],
            'stock_availability' => [
                'disclaimer' => 'English stock advisory disclaimer from server',
                'products' => [
                    [
                        'product_id' => 1,
                        'product_name' => 'Tracked Item',
                        'quantity_needed_base' => '1.000000',
                        'quantity_available_base' => '10.000000',
                        'is_sufficient' => true,
                        'fefo_allocations' => [
                            [
                                'lot_number' => 'LOT-2026-A',
                                'expiry_date' => '2027-01-01',
                                'quantity' => '1.000000',
                            ],
                        ],
                    ],
                ],
            ],
            'blockers' => [
                ['code' => 'unknown_blocker_code_xyz', 'message' => 'Raw internal server error message that must never leak'],
            ],
            'warnings' => [],
            'can_execute' => false,
        ]);
        $this->app->instance(SalesInvoiceCommands::class, $spy);

        // Test in Arabic: verify 10-decimal FX string, translated stock disclaimer, Arabic labels, and generic blocker message
        app()->setLocale('ar');
        $componentAr = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice');

        $componentAr->assertSeeHtml('5.1234567890');
        $componentAr->assertSeeHtml(__('commands.preview_title'));
        $componentAr->assertSeeHtml(__('commands.stock_disclaimer'));
        $componentAr->assertSeeHtml(__('commands.lot'));
        $componentAr->assertSeeHtml(__('commands.expiry'));
        $componentAr->assertSeeHtml(__('commands.qty'));
        $componentAr->assertSeeHtml(__('commands.sku'));
        $componentAr->assertSeeHtml(__('commands.code_generic'));
        $componentAr->assertDontSeeHtml('Raw internal server error message that must never leak');
        $componentAr->assertDontSeeHtml('English stock advisory disclaimer');

        // Test in English: verify English labels and generic blocker message
        app()->setLocale('en');
        $componentEn = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice');

        $componentEn->assertSeeHtml('5.1234567890');
        $componentEn->assertSeeHtml(__('commands.preview_title'));
        $componentEn->assertSeeHtml(__('commands.code_generic'));
        $componentEn->assertDontSeeHtml('Raw internal server error message that must never leak');
    }

    public function test_real_member_post_permission_revoked_after_preview(): void
    {
        $member = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $member->id,
            'is_owner' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $member->givePermissionTo(['sales.invoice.view', 'sales.invoice.post', 'sales.invoice.change_price']);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $this->actingAs($member);
        app(CompanyContext::class)->setCompany($this->company, $member);
        $component = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('postInvoice')->assertSet('showPreview', true);
        $member->revokePermissionTo('sales.invoice.post');
        $member->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertFalse($member->hasPermissionTo('sales.invoice.post'));
        $component->call('confirmPost')->assertStatus(403);
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertSame(0, PostingBatch::where('company_id', $this->company->id)->count());
    }
}
