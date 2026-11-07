<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DedicatedPaymentPlan
{
    /** @return array{payments: list<array<string, mixed>>, blockers: list<string>, links: list<array{label: string, url: string}>, snapshot: array<string, mixed>} */
    public function inspect(int $company, int $document, bool $vendor, User $actor, bool $lock = false): array
    {
        $prefix = $vendor ? 'vendor' : 'customer';
        $foreign = $prefix.'_payment_id';
        $source = $vendor ? 'purchase_id' : 'sales_invoice_id';
        $read = function (string $table, array $columns, callable $scope) use ($company, $lock) {
            $q = DB::table($table)->where('company_id', $company)->select($columns)->orderBy('id')->limit(2001);
            $scope($q);
            if ($lock) {
                $q->lockForUpdate();
            }
            $rows = $q->get();
            if ($rows->count() > 2000) {
                throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
            }

            return $rows;
        };
        $allocations = $read($prefix.'_payment_allocations', ['id', $foreign, $source, 'application_event_id', 'settlement_base_value'], fn ($q) => $q->whereIn($foreign, DB::table($prefix.'_payment_allocations')->select($foreign)->where('company_id', $company)->where($source, $document)));
        $payments = $read($prefix.'_payments', ['id', 'public_id', 'payment_number', 'amount', 'amount_base', 'currency_code', 'money_account_id', 'check_id', 'payment_date', 'is_reversed', 'posting_batch_id', 'reversal_posting_batch_id'], fn ($q) => $q->whereIn('id', $allocations->pluck($foreign)));
        $events = $read($prefix.'_payment_application_events', ['id', $foreign, 'application_date', 'applied_at', 'reversed_at', 'posting_batch_id', 'reversal_posting_batch_id', 'request_hash'], fn ($q) => $q->whereIn($foreign, $payments->pluck('id')));
        $checks = $read('checks', ['id', 'public_id', 'status', 'currency_code', 'amount'], fn ($q) => $q->whereIn('id', $payments->pluck('check_id')->filter()));
        $accounts = $read('money_accounts', ['id', 'name_ar', 'name_en', 'currency_code', 'is_active'], fn ($q) => $q->whereIn('id', $payments->pluck('money_account_id')));
        $batches = $payments->pluck('posting_batch_id')->merge($events->pluck('posting_batch_id'))->filter();
        $posting = $read('posting_batches', ['id', 'status', 'posting_date', 'reversal_of_id', 'reversed_by_batch_id'], fn ($q) => $q->whereIn('id', $batches));
        $postingLines = $read('posting_lines', ['id', 'posting_batch_id', 'ledger_account_id', 'debit_base', 'credit_base', 'transaction_amount', 'transaction_currency_code', 'exchange_rate'], fn ($q) => $q->whereIn('posting_batch_id', $batches));
        $plan = [];
        $blockers = [];
        $links = [];
        foreach ($payments as $payment) {
            if ($payment->is_reversed) {
                continue;
            }
            $view = $vendor ? 'purchasing.cost.view' : 'money.receipt.view';
            $reverse = $vendor ? 'money.vendor_payment.reverse' : 'money.receipt.reverse';
            if (! $actor->can($view) || ! $actor->can($reverse)) {
                $blockers[] = __('corrections.dependency_restricted');

                continue;
            }
            app(SalesActorGuard::class)->lockAndAuthorize($company, $actor, $view);
            app(SalesActorGuard::class)->lockAndAuthorize($company, $actor, $reverse);
            $links[] = ['label' => $payment->payment_number, 'url' => route($vendor ? 'vendor-payments.show' : 'payments.show', $payment->public_id)];
            $all = $allocations->where($foreign, $payment->id);
            $initial = BigDecimal::zero();
            foreach ($all->whereNull('application_event_id') as $allocation) {
                $initial = $initial->plus((string) $allocation->settlement_base_value);
            }
            $dedicated = $all->every(fn ($a) => (int) $a->{$source} === $document)
                && $initial->isEqualTo((string) $payment->amount_base)
                && $events->where($foreign, $payment->id)->every(fn ($e) => $e->applied_at !== null);
            if ($payment->check_id !== null && $actor->can('money.check.view')) {
                app(SalesActorGuard::class)->lockAndAuthorize($company, $actor, 'money.check.view');
                $check = $checks->firstWhere('id', $payment->check_id);
                if ($check !== null) {
                    $links[] = ['label' => __('corrections.check'), 'url' => route('money.checks.show', $check->public_id)];
                }
            }
            if ($payment->check_id !== null || ! $dedicated) {
                $blockers[] = __('corrections.'.($payment->check_id !== null ? 'check_payment' : 'shared_payment'), ['number' => $payment->payment_number]);

                continue;
            }
            $account = $accounts->firstWhere('id', $payment->money_account_id);
            $plan[] = ['id' => (int) $payment->id, 'number' => $payment->payment_number, 'amount' => (string) $payment->amount,
                'inverse' => (string) BigDecimal::of((string) $payment->amount)->multipliedBy($vendor ? '1' : '-1'),
                'currency' => $payment->currency_code, 'account' => app()->getLocale() === 'ar' ? $account?->name_ar : ($account->name_en ?? $account?->name_ar),
                'url' => route($vendor ? 'vendor-payments.show' : 'payments.show', $payment->public_id)];
        }

        return ['payments' => $plan, 'blockers' => $blockers, 'links' => $links, 'snapshot' => compact('allocations', 'payments', 'events', 'checks', 'accounts', 'posting', 'postingLines')];
    }

    /** @param list<array<string, mixed>> $payments */
    public function reverse(array $payments, int $company, bool $vendor, User $actor, string $reason, string $date): void
    {
        foreach (array_reverse($payments) as $payment) {
            $model = $vendor ? VendorPayment::class : CustomerPayment::class;
            $action = $vendor ? ReverseVendorPaymentAction::class : ReverseCustomerPaymentAction::class;
            app($action)->execute($model::where('company_id', $company)->findOrFail($payment['id']), $actor, $reason, $date);
        }
    }
}
