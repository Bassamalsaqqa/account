<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Expenses\ExpenseDetail;
use App\Livewire\Pages\Expenses\ExpenseForm;
use App\Livewire\Pages\Expenses\ExpenseIndex;
use App\Models\Check;
use App\Models\Expense;
use App\Models\PostingBatch;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Money\CheckFinancialSourceResolver;
use App\Services\Money\CheckHistory;
use App\Services\Phase7\Phase7History;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class Phase7Correction02Test extends Phase7TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function form(string $method = 'cash'): Testable
    {
        $currency = $method === 'cash' ? 'ILS' : 'USD';
        $form = Livewire::test(ExpenseForm::class)
            ->set('categoryId', $this->operatingCategory->id)
            ->set('description', 'Correction 02 receipt')
            ->set('expenseDate', '2026-10-02')
            ->set('amount', '51.25')
            ->set('currencyCode', $currency)
            ->set('exchangeRate', $currency === 'ILS' ? '1' : '3.5')
            ->set('paymentMethod', $method);
        if ($method === 'check') {
            $form->set('drawnMoneyAccountId', $this->usdBankAccount->id)
                ->set('checkNumber', 'C02-ATTACHMENT')->set('bankName', 'QA Bank')->set('dueDate', '2026-10-03');
        } else {
            $form->set('moneyAccountId', $method === 'cash' ? $this->cashAccount->id : $this->usdBankAccount->id);
        }

        return $form->set('attachment', UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'));
    }

    private function financialState(): array
    {
        return [Expense::count(), Check::count(), PostingBatch::count(),
            DB::table('document_sequences')->orderBy('id')->get()->toJson()];
    }

    public static function methods(): array
    {
        return [['cash'], ['bank'], ['check']];
    }

    public function test_landed_authorization_is_checked_before_upload_and_has_zero_effects(): void
    {
        $this->activate($this->customActor(['money.expense.manage']));
        $before = $this->financialState();
        $this->form()->set('classification', 'landed_cost')->call('save')->assertForbidden();
        $this->assertSame($before, $this->financialState());
        $this->assertSame([], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
    }

    public static function failures(): array
    {
        return [['cash', false], ['check', false], ['cash', true], ['check', true]];
    }

    #[DataProvider('failures')]
    public function test_unexpected_or_revoked_authority_after_storage_cleans_file_and_propagates(string $method, bool $authorization): void
    {
        $form = $this->form($method);
        $before = $this->financialState();
        $storedPath = null;
        $armed = true;
        DB::listen(function () use (&$armed, &$storedPath, $authorization): void {
            $files = Storage::disk('local')->allFiles('expenses/'.$this->company->id);
            if ($armed && $files !== []) {
                $armed = false;
                $storedPath = $files[0];
                throw $authorization ? new AuthorizationException('Controlled stale permission failure') : new RuntimeException('Controlled post-storage failure');
            }
        });
        try {
            $form->instance()->save();
            $this->fail('Unexpected exceptions must propagate after cleanup.');
        } catch (AuthorizationException|RuntimeException $e) {
            $this->assertStringContainsString('Controlled', $e->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertNotNull($storedPath, 'Failure must occur after permanent upload.');
        Storage::disk('local')->assertMissing($storedPath);
        $this->assertSame($before, $this->financialState());
        $this->assertSame([], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
    }

    public function test_inactive_settlement_account_domain_failure_cleans_permanent_upload(): void
    {
        $form = $this->form();
        $this->cashAccount->update(['is_active' => false]);
        $before = $this->financialState();
        $form->call('save')->assertHasErrors('payment');
        $this->assertSame($before, $this->financialState());
        $this->assertSame([], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
    }

    #[DataProvider('methods')]
    public function test_success_and_exact_retry_keep_only_canonical_owned_attachment(string $method): void
    {
        $form = $this->form($method)->call('save')->assertHasNoErrors();
        $expense = Expense::sole();
        $path = $expense->attachment_path;
        $this->assertNotNull($path);
        $this->assertSame('receipt.pdf', $expense->attachment_name);
        $this->assertSame('application/pdf', $expense->attachment_mime);
        $this->assertSame(102400, $expense->attachment_size);
        Storage::disk('local')->assertExists($path);
        $this->get(route('attachments.expenses.download', $expense->public_id))->assertOk();
        app(Phase7History::class)->validate($expense);
        if ($method === 'check') {
            $check = Check::sole();
            $this->assertSame($expense->id, app(CheckFinancialSourceResolver::class)->resolve($check)->sourceModel()->id);
            app(CheckHistory::class)->validate($check);
        }
        $before = $this->financialState();
        $secondPath = null;
        DB::listen(function () use ($path, &$secondPath): void {
            foreach (Storage::disk('local')->allFiles('expenses/'.$this->company->id) as $file) {
                if ($file !== $path) {
                    $secondPath = $file;
                }
            }
        });
        $form->set('attachment', UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'))
            ->call('save')->assertHasNoErrors();
        $this->assertNotNull($secondPath, 'Retry must physically create a distinct upload.');
        Storage::disk('local')->assertMissing($secondPath);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([$path], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
        $this->assertSame($before, $this->financialState());
        $this->assertSame($expense->id, Expense::sole()->id);
        $this->assertSame($path, $expense->fresh()->attachment_path);
        app(Phase7History::class)->validate($expense->fresh());
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_check_retry_with_changed_financial_intent_is_not_accepted_and_keeps_original_file(): void
    {
        $form = $this->form('check')->call('save')->assertHasNoErrors();
        $path = Expense::sole()->attachment_path;
        $before = $this->financialState();
        $form->set('amount', '52.25');
        try {
            $form->instance()->save();
            $this->fail('Changed intent must still conflict.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different business intent', $e->getMessage());
        }
        $this->assertSame($before, $this->financialState());
        $this->assertSame([$path], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
    }

    public function test_landed_classification_capability_is_fresh_and_operating_remains_usable(): void
    {
        $reader = $this->customActor(['money.expense.manage', 'purchasing.cost.view', 'purchasing.landed_cost.manage']);
        $this->activate($reader);
        $form = $this->form()->assertSee('value="landed_cost"', false);
        $reader->roles()->firstOrFail()->revokePermissionTo('purchasing.cost.view');
        $form->call('$refresh')->assertDontSee('value="landed_cost"', false)->assertSee('value="operating"', false);
        $form->call('save')->assertHasNoErrors();
        $this->assertSame('operating', Expense::sole()->classification);
    }

    public static function localesAndStates(): array
    {
        return [['ar', false], ['en', false], ['ar', true], ['en', true]];
    }

    #[DataProvider('localesAndStates')]
    public function test_index_and_detail_use_frozen_category_identity_with_live_id_filter(string $locale, bool $reverse): void
    {
        $this->operatingCategory->update(['name_ar' => 'مصروف قديم', 'name_en' => 'Original category']);
        $this->form()->call('save')->assertHasNoErrors();
        $expense = Expense::sole();
        $this->operatingCategory->update(['name_ar' => 'مصروف جديد', 'name_en' => 'Renamed category']);
        if ($reverse) {
            app(ReverseExpenseAction::class)->execute($expense, $this->owner, 'Correction', '2026-10-03');
        }
        app()->setLocale($locale);
        $old = $locale === 'ar' ? 'مصروف قديم' : 'Original category';
        $new = $locale === 'ar' ? 'مصروف جديد' : 'Renamed category';
        $index = Livewire::test(ExpenseIndex::class)->set('categoryId', $this->operatingCategory->id)->assertSee($expense->expense_number);
        preg_match('/<tbody[^>]*>(.*?)<\/tbody>/s', $index->html(), $match);
        $this->assertStringContainsString($old, $match[1]);
        $this->assertStringNotContainsString($new, $match[1]);
        $index->assertViewHas('expenses', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $expense->id);
        Livewire::test(ExpenseDetail::class, ['publicId' => $expense->public_id])->assertSee($old)->assertDontSee($new);
    }

    public function test_snapshot_language_fallback_and_landed_financial_redaction_remain_server_side(): void
    {
        $this->operatingCategory->update(['name_en' => null]);
        $this->form()->call('save')->assertHasNoErrors();
        $old = $this->operatingCategory->name_ar;
        $this->operatingCategory->update(['name_ar' => 'Renamed master']);
        $landed = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id, 'classification' => 'landed_cost', 'expense_date' => '2026-10-02',
            'description' => 'Restricted freight', 'currency_code' => 'ILS', 'exchange_rate' => '1', 'amount' => '987.65',
            'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'c02-redaction',
        ]);
        $this->activate($this->customActor(['money.expense.view']));
        app()->setLocale('en');
        $index = Livewire::test(ExpenseIndex::class)->assertSee($old)->assertDontSee('987.65');
        $index->assertViewHas('expenses', fn ($rows) => $rows->every(fn ($row) => $row->id !== $landed->id || $row->amount === null));
        $this->assertStringNotContainsString('987.65', json_encode($index->snapshot));
    }
}
