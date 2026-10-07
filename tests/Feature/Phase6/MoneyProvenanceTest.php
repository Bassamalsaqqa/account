<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Actions\Money\EnsureMoneyFoundationAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Money\CustomerPaymentHistory;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;

final class MoneyProvenanceTest extends Phase6TestCase
{
    public static function namespaces(): array
    {
        return [['money_transfer'], ['check_event'], ['customer_payment'], ['customer_payment_application']];
    }

    #[DataProvider('namespaces')]
    public function test_balanced_orphan_canonical_sources_are_not_owned_money_history(string $type): void
    {
        $check = $this->check();
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $batch = (array) DB::table('posting_batches')->find($check->customerPayment->posting_batch_id);
        $originalId = $batch['id'];
        unset($batch['id']);
        $batch['public_id'] = (string) Str::ulid();
        $batch['source_type'] = $type;
        $batch['source_id'] = 99999999;
        $batch['idempotency_key'] = 'rogue-'.Str::uuid();
        $id = DB::table('posting_batches')->insertGetId($batch);
        foreach (DB::table('posting_lines')->where('posting_batch_id', $originalId)->get() as $line) {
            $values = (array) $line;
            unset($values['id']);
            $values['posting_batch_id'] = $id;
            DB::table('posting_lines')->insert($values);
        }
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $report = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('orphan/duplicate', implode(' ', $report->violations));
    }

    public function test_duplicate_clearance_batch_and_damaged_control_provenance_are_detected(): void
    {
        $check = $this->check();
        $this->event($check, 'deposit');
        $event = $this->event($check->fresh(), 'clear');
        $batch = (array) DB::table('posting_batches')->find($event->posting_batch_id);
        unset($batch['id']);
        $batch['public_id'] = (string) Str::ulid();
        $batch['idempotency_key'] = 'duplicate-clear';
        $id = DB::table('posting_batches')->insertGetId($batch);
        foreach (DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->get() as $line) {
            $values = (array) $line;
            unset($values['id']);
            $values['posting_batch_id'] = $id;
            DB::table('posting_lines')->insert($values);
        }
        $this->assertFalse(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        DB::table('posting_lines')->where('posting_batch_id', $id)->delete();
        DB::table('posting_batches')->where('id', $id)->delete();
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        DB::table('ledger_accounts')->where('system_key', 'checks_in_hand')->update(['normal_balance' => 'credit']);
        $this->assertFalse(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_legitimate_opening_cash_and_ap_do_not_require_money_source_equality(): void
    {
        app(PostOpeningBalancesAction::class)->execute(company: $this->company, date: now(),
            assetBalances: [['account_id' => $this->ilsCashAccount->ledger_account_id, 'amount' => '50']],
            liabilityBalances: [['account_id' => LedgerAccount::where('system_key', 'accounts_payable')->sole()->id, 'amount' => '25']], postedBy: $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_catalog_upgrade_preserves_every_non_owner_grant_and_sequence(): void
    {
        $role = Role::where('company_id', $this->company->id)->where('name', 'Purchasing')->sole();
        $role->syncPermissions(['vendors.view']);
        $before = DB::table('role_has_permissions')->where('role_id', $role->id)->orderBy('permission_id')->get()->toJson();
        $sequences = DocumentSequence::orderBy('id')->get()->toJson();
        app(CompanyContext::class)->clear();
        app(EnsureMoneyFoundationAction::class)->execute($this->company);
        app(EnsureMoneyFoundationAction::class)->execute($this->company);
        $this->activate($this->owner);
        $this->assertSame($before, DB::table('role_has_permissions')->where('role_id', $role->id)->orderBy('permission_id')->get()->toJson());
        $this->assertSame($sequences, DocumentSequence::orderBy('id')->get()->toJson());
        foreach (['money.transfer.create', 'money.transfer.reverse', 'money.check.incoming.manage', 'money.check.outgoing.manage'] as $permission) {
            $this->assertTrue($this->owner->hasPermissionTo($permission));
        }
        $this->assertSame(0, DB::table('money_transfers')->count());
        $this->assertSame(0, DB::table('checks')->count());
    }

    public static function checkDirections(): array
    {
        return [['incoming'], ['outgoing']];
    }

    #[DataProvider('checkDirections')]
    public function test_check_initial_settlement_and_payment_request_have_one_exact_allocation_intent(string $direction): void
    {
        $incoming = $direction === 'incoming';
        $document = $incoming ? $this->invoice() : $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $data = $this->checkIntent($direction, [[($incoming ? 'sales_invoice_id' : 'purchase_id') => $document->id, 'allocated_amount' => '100']]);
        if ($incoming) {
            $data['party_id'] = $document->customer_id;
        }
        $check = app($incoming ? ReceiveCheckAction::class : IssueCheckAction::class)->execute($this->company, $this->owner, $data);
        $payment = $incoming ? $check->customerPayment : $check->vendorPayment;
        $this->assertTrue($document->fresh()->calculateOutstanding()->isZero());
        $this->assertSame('100.000000', $payment->allocations->sole()->allocated_amount);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $payload = $check->getAttribute('request_payload');
        $payload['allocations'] = [];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        DB::table('checks')->where('id', $check->id)->update(['request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => $hash]);
        DB::table('check_events')->where('check_id', $check->id)->update(['request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => $hash]);
        app($incoming ? CustomerPaymentHistory::class : VendorPaymentPostedIntegrityValidator::class)->validate($payment->fresh());
        $this->assertFalse(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
