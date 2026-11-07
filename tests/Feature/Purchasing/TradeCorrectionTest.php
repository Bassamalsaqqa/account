<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\DeleteReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Livewire\Pages\Purchasing\PurchaseReturnDetail;
use App\Livewire\Pages\Purchasing\PurchaseReturnIndex;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Livewire\Pages\Sales\ReturnDetail;
use App\Livewire\Pages\Sales\ReturnForm;
use App\Livewire\Pages\Sales\ReturnIndex;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Corrections\CorrectionStockEffects;
use App\Services\Corrections\TradeCorrection;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Phase5D\Phase5DTestCase;
use Tests\Support\DisposableMariaDbSchema;

final class TradeCorrectionTest extends Phase5DTestCase
{
    private function invoice(): SalesInvoice
    {
        $this->createAndPostPurchase();
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'زبون', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-11-01', 'document_locale' => 'ar', 'notes' => 'original notes', 'terms' => 'original terms',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'item_description' => 'زيت', 'quantity' => '2', 'unit_price' => '20']],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    private function salesReturn(SalesInvoice $invoice): SalesReturn
    {
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-11-02', 'reason' => 'original reason', 'notes' => 'original notes',
            'lines' => [['sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);

        return app(PostSalesReturnAction::class)->execute($draft, $this->owner);
    }

    public static function families(): array
    {
        return [['sales_invoice', 'edit'], ['sales_invoice', 'delete'], ['sales_return', 'edit'], ['sales_return', 'delete'], ['purchase_return', 'edit'], ['purchase_return', 'delete']];
    }

    #[DataProvider('families')]
    public function test_each_family_edit_delete_and_durable_retry(string $family, string $mode): void
    {
        if ($family === 'purchase_return') {
            $purchase = $this->createAndPostPurchase();
            $draft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
                'purchase_id' => $purchase->id, 'return_date' => '2026-11-02', 'reason' => 'original reason', 'notes' => 'original notes',
                'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => '1']],
            ]);
            $source = app(PostPurchaseReturnAction::class)->execute($draft, $this->owner);
        } else {
            $source = $this->invoice();
            if ($family === 'sales_return') {
                $source = $this->salesReturn($source);
            }
        }
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, $mode, 'Correction', now($this->company->timezone)->toDateString());
        $this->assertSame([], $preview['blockers']);
        $result = $service->execute($preview['token'], $this->owner);
        $this->assertSame('void', $source->fresh()->status);
        $this->assertSame($mode === 'edit' ? 'draft' : 'void', $result->status);
        $this->assertSame($result->id, $service->execute($preview['token'], $this->owner)->id);
        $receipt = DB::table($family.'_correction_receipts')->first();
        $this->assertSame($source->id, $receipt->source_id);
        $this->assertSame($mode === 'edit' ? $result->id : null, $receipt->replacement_id);
        if ($mode === 'edit') {
            $this->assertSame($source->notes, $result->notes);
            $this->assertSame($source->lines()->first()->quantity, $result->lines()->first()->quantity);
        }
    }

    public function test_stock_effect_review_fails_closed_above_2000_movements(): void
    {
        $purchase = $this->createAndPostPurchase();
        $line = (array) DB::table('purchase_lines')->where('purchase_id', $purchase->id)->first();
        $movement = (array) DB::table('stock_movements')->where('id', $line['stock_movement_id'])->first();
        unset($line['id'], $movement['id']);
        for ($i = 0; $i < 2000; $i++) {
            $movement['public_id'] = (string) Str::ulid();
            $movement['idempotency_key'] = 'bound-test-'.$i;
            $line['stock_movement_id'] = DB::table('stock_movements')->insertGetId($movement);
            $line['public_id'] = (string) Str::ulid();
            $line['line_number'] = $i + 2;
            DB::table('purchase_lines')->insert($line);
        }
        $this->expectException(ValidationException::class);
        app(CorrectionStockEffects::class)->rows($purchase);
    }

    public function test_locale_change_does_not_invalidate_review_with_child_return(): void
    {
        $invoice = $this->invoice();
        $child = $this->salesReturn($invoice);
        app()->setLocale('en');
        $service = app(TradeCorrection::class);
        $preview = $service->preview($invoice, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        app()->setLocale('ar');
        $service->execute($preview['token'], $this->owner);
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('void', $child->fresh()->status);
    }

    public function test_detail_history_reads_do_not_acquire_write_locks(): void
    {
        $invoice = $this->invoice();
        $page = Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])->assertOk();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $page->instance()->correctionHistory();
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'for update'))));
    }

    public function test_sales_invoice_reverses_child_before_parent(): void
    {
        $invoice = $this->invoice();
        $child = $this->salesReturn($invoice);
        $service = app(TradeCorrection::class);
        $preview = $service->preview($invoice, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $this->assertCount(1, $preview['returns']);
        $service->execute($preview['token'], $this->owner);
        $this->assertSame('void', $child->fresh()->status);
        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_added_return_stales_invoice_review(): void
    {
        $invoice = $this->invoice();
        $service = app(TradeCorrection::class);
        $preview = $service->preview($invoice, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $this->salesReturn($invoice);
        $this->expectException(ValidationException::class);
        $service->execute($preview['token'], $this->owner);
    }

    public function test_dedicated_customer_payment_is_disclosed_and_reversed(): void
    {
        $invoice = $this->invoice();
        $account = MoneyAccount::where('company_id', $this->company->id)->where('account_type', 'cash')->firstOrFail();
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $account->id, 'payment_date' => '2026-11-03', 'payment_method' => 'cash',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'amount' => '40', 'idempotency_key' => 'dedicated-correction',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '40']],
        ]);
        $service = app(TradeCorrection::class);
        $preview = $service->preview($invoice, $this->owner, 'edit', 'Correction', now($this->company->timezone)->toDateString());
        $this->assertSame([], $preview['blockers']);
        $this->assertCount(1, $preview['payments']);
        $result = $service->execute($preview['token'], $this->owner);
        $this->assertTrue($payment->fresh()->is_reversed);
        $this->assertSame('draft', $result->status);
    }

    public function test_sales_detail_dialog_reaches_shared_execution(): void
    {
        $invoice = $this->invoice();
        Livewire::test(InvoiceDetail::class, ['publicId' => $invoice->public_id])
            ->call('openCorrection', 'edit')->set('correctionReason', 'Correction')->call('previewCorrection')
            ->assertHasNoErrors()->assertSee(__('corrections.stock_inverse'))->call('executeCorrection')->assertHasNoErrors();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame(1, DB::table('sales_invoice_correction_receipts')->count());
    }

    public function test_sales_return_replacement_opens_editable_canonical_draft(): void
    {
        $source = $this->salesReturn($this->invoice());
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'edit', 'Correction', now($this->company->timezone)->toDateString());
        $replacement = $service->execute($preview['token'], $this->owner);
        Livewire::test(ReturnForm::class, ['publicId' => $replacement->public_id])
            ->assertSet('draftPublicId', $replacement->public_id)->assertSet('lines.0.return_quantity', '1.000000')
            ->set('notes', 'Edited replacement')->call('save', false)->assertHasNoErrors();
        $this->assertSame('Edited replacement', $replacement->fresh()->notes);
        $this->assertSame(2, SalesReturn::count());
    }

    public function test_trade_receipt_migration_refuses_actual_history(): void
    {
        $migration = require database_path('migrations/2026_11_07_000004_create_trade_correction_receipts.php');
        $source = $this->invoice();
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $service->execute($preview['token'], $this->owner);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_revoked_sales_void_permission_blocks_retry(): void
    {
        $source = $this->invoice();
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $service->execute($preview['token'], $this->owner);
        $this->owner->roles()->firstOrFail()->revokePermissionTo('sales.invoice.void');
        $this->expectException(AuthorizationException::class);
        $service->execute($preview['token'], $this->owner);
    }

    public function test_changed_sales_configuration_rolls_back_every_leg(): void
    {
        $source = $this->invoice();
        DB::table('product_units')->where('id', $this->unit->id)->update(['conversion_to_base' => '2']);
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'edit', 'Correction', now($this->company->timezone)->toDateString());
        $before = [DB::table('stock_movements')->count(), DB::table('posting_batches')->count()];
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Invalid replacement configuration accepted');
        } catch (\Throwable) {
            $this->assertSame('posted', $source->fresh()->status);
            $this->assertSame(1, SalesInvoice::count());
            $this->assertSame($before, [DB::table('stock_movements')->count(), DB::table('posting_batches')->count()]);
            $this->assertSame(0, DB::table('sales_invoice_correction_receipts')->count());
        }
    }

    public function test_receipt_migration_roundtrip_uses_its_own_disposable_schema(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $migration = require database_path('migrations/2026_11_07_000004_create_trade_correction_receipts.php');
            $migration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('sales_invoice_correction_receipts'));
            $migration->up();
            $this->assertTrue(DB::getSchemaBuilder()->hasTable('purchase_return_correction_receipts'));
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_two_process_token_race_converges_to_one_replacement(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $payloadPath = null;
        $workers = [];
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            app(CompanyContext::class)->clear();
            $actor = User::factory()->create();
            $this->actingAs($actor);
            $company = app(CreateCompanyAction::class)->execute($actor, ['name_ar' => 'شركة التزامن']);
            app(CompanyContext::class)->setCompany($company, $actor);
            $this->actingAs($actor);
            setPermissionsTeamId($company->id);
            $customer = Customer::create(['company_id' => $company->id, 'name_ar' => 'زبون', 'active' => true, 'created_by' => $actor->id]);
            $source = app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, [
                'customer_id' => $customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => now($company->timezone)->toDateString(),
                'lines' => [['item_description' => 'خدمة', 'quantity' => '1', 'unit_price' => '10']],
            ]);
            $source = app(PostSalesInvoiceAction::class)->execute($source, $actor);
            $preview = app(TradeCorrection::class)->preview($source, $actor, 'edit', 'Correction', now($company->timezone)->toDateString());
            $payloadPath = tempnam(sys_get_temp_dir(), 'correction_race_');
            chmod($payloadPath, 0600);
            file_put_contents($payloadPath, json_encode(['actor' => $actor->id, 'company' => $company->id, 'family' => 'sales_invoice', 'locale' => app()->getLocale(), 'token' => $preview['token']], JSON_THROW_ON_ERROR));
            $environment = array_merge($schema->environment(), ['APP_KEY' => config('app.key')]);
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, 'tests/Support/Corrections/execute-worker.php', $payloadPath], base_path(), $environment);
                $worker->setTimeout(30)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertSame(trim($workers[0]->getOutput()), trim($workers[1]->getOutput()));
            $this->assertSame(1, DB::table('sales_invoice_correction_receipts')->count());
            $this->assertSame(1, SalesInvoice::where('status', 'draft')->count());
            $this->assertSame('void', $source->fresh()->status);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if ($payloadPath !== null) {
                unlink($payloadPath);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_return_lists_and_remaining_details_render_shared_actions(): void
    {
        $invoice = $this->invoice();
        $return = $this->salesReturn($invoice);
        Livewire::test(ReturnIndex::class)->assertSee(__('corrections.edit'));
        Livewire::test(ReturnDetail::class, ['publicId' => $return->public_id])
            ->call('openCorrection', 'delete')->set('correctionReason', 'Correction')->call('previewCorrection')->assertHasNoErrors()
            ->call('executeCorrection')->assertHasNoErrors();
        $this->assertSame('void', $return->fresh()->status);
        $purchase = $this->createAndPostPurchase();
        $draft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $purchase->id, 'return_date' => '2026-11-02',
            'lines' => [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        $purchaseReturn = app(PostPurchaseReturnAction::class)->execute($draft, $this->owner);
        Livewire::test(PurchaseReturnIndex::class)->assertSee(__('corrections.edit'));
        Livewire::test(PurchaseReturnDetail::class, ['publicId' => $purchaseReturn->public_id])
            ->call('openCorrection', 'delete')->set('correctionReason', 'Correction')->call('previewCorrection')->assertHasNoErrors()
            ->call('executeCorrection')->assertHasNoErrors();
        $this->assertSame('void', $purchaseReturn->fresh()->status);
    }

    public function test_ordinary_return_draft_can_be_discarded_but_replacement_is_retained(): void
    {
        $invoice = $this->invoice();
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-11-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        app(DeleteReturnDraftAction::class)->execute($draft, $this->owner);
        $this->assertNull($draft->fresh());
        $source = $this->salesReturn($invoice);
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'edit', 'Correction', now($this->company->timezone)->toDateString());
        $replacement = $service->execute($preview['token'], $this->owner);
        try {
            app(DeleteReturnDraftAction::class)->execute($replacement, $this->owner);
            $this->fail('Provenance draft was deleted');
        } catch (ValidationException) {
            $this->assertSame('draft', $replacement->fresh()->status);
            $this->assertSame(1, DB::table('sales_return_correction_receipts')->count());
        }
    }

    public function test_consumed_return_stock_has_named_blocker_and_permitted_recovery_link(): void
    {
        $invoice = $this->invoice();
        $source = $this->salesReturn($invoice);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-11-03',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'item_description' => 'زيت', 'quantity' => '9', 'unit_price' => '20']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $this->assertNotEmpty($preview['blockers']);
        $this->assertStringContainsString($this->product->name_ar, implode(' ', $preview['blockers']));
        $this->assertSame(route('products.show', $this->product->public_id), $preview['links'][0]['url']);
        try {
            $service->execute($preview['token'], $this->owner);
            $this->fail('Consumed stock was reversed');
        } catch (ValidationException) {
            $this->assertSame('posted', $source->fresh()->status);
        }
    }

    public function test_optional_recovery_read_revocation_clears_cached_preview(): void
    {
        $source = $this->invoice();
        $role = $this->owner->roles()->firstOrFail();
        foreach (['money.expense.view', 'money.check.view', 'inventory.stock.view'] as $permission) {
            $page = Livewire::test(InvoiceDetail::class, ['publicId' => $source->public_id])
                ->call('openCorrection', 'delete')->set('correctionReason', 'Correction')->call('previewCorrection')->assertHasNoErrors();
            $this->assertContains($permission, $page->get('correctionPreview')['presentation_permissions']);
            $role->revokePermissionTo($permission);
            $page->call('$refresh')->assertSet('correctionPreview', null)->assertSet('correctionOpen', false);
            $role->givePermissionTo($permission);
        }
        $this->assertSame('posted', $source->fresh()->status);
    }

    public function test_execution_locks_company_before_source(): void
    {
        $source = $this->invoice();
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'delete', 'Correction', now($this->company->timezone)->toDateString());
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = strtolower($query->sql);
            }
        });
        $service->execute($preview['token'], $this->owner);
        $this->assertNotEmpty($locks);
        $this->assertStringContainsString('`companies`', $locks[0]);
        $this->assertNotEmpty(array_filter($locks, fn ($sql) => str_contains($sql, '`sales_invoices`')));
        $this->assertSame('void', $source->fresh()->status);
    }
}
