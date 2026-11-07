<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Livewire\Pages\Expenses\ExpenseDetail;
use App\Livewire\Pages\Money\TransferDetail;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Sales\PaymentDetail;
use App\Models\Check;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\MoneyTransfer;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Money\MoneyActorGuard;
use Illuminate\Database\Eloquent\Model;

/** Bounded, authorized read plan. Raw attributes stay in a digest, never in the client. */
final class StandaloneReversalReview
{
    /** @return array<string, mixed> */
    public function build(object $page, string $kind, int $id, int $companyId, bool $lock = true): array
    {
        $class = get_class($page);
        if ($class === EmployeeDetail::class) {
            abort_unless(in_array($kind, ['advance', 'salary', 'salary_payment'], true), 403);
        }
        $kind = match ($class) {
            PaymentDetail::class => 'customer',
            \App\Livewire\Pages\Purchasing\PaymentDetail::class => 'vendor',
            ExpenseDetail::class => 'expense',
            TransferDetail::class => 'transfer',
            default => $kind,
        };
        [$model, $permission, $method, $relations] = match ($kind) {
            'customer' => [CustomerPayment::class, 'money.receipt.reverse', 'reversePayment', ['allocations']],
            'vendor' => [VendorPayment::class, 'money.vendor_payment.reverse', 'reversePayment', ['allocations']],
            'expense' => [Expense::class, 'money.expense.reverse', 'reverse', ['landedAllocations']],
            'transfer' => [MoneyTransfer::class, 'money.transfer.reverse', 'reverse', []],
            'advance' => [EmployeeAdvance::class, 'payroll.advance.manage', 'reverseAdvance', ['allocations']],
            'salary' => [SalaryEntry::class, 'payroll.salary.reverse', 'reverseSalaryEntry', ['advanceAllocations', 'paymentAllocations']],
            'salary_payment' => [SalaryPayment::class, 'payroll.salary.reverse', 'reverseSalaryPayment', ['allocations']],
            default => abort(404),
        };
        $guard = app(MoneyActorGuard::class);
        $guard->authorize($companyId, $permission);
        $guard->authorize($companyId, match ($kind) {
            'customer' => 'money.receipt.view', 'expense' => 'money.expense.view',
            'transfer' => 'money.transfer.view', 'vendor' => 'purchasing.cost.view',
            default => 'employees.view',
        });
        if (in_array($kind, ['salary', 'salary_payment'])) {
            $guard->authorize($companyId, 'payroll.salary.view');
        }
        if ($kind === 'vendor') {
            $guard->authorize($companyId, 'purchasing.cost.view');
        }
        $query = $model::where('company_id', $companyId);
        if (in_array($kind, ['advance', 'salary', 'salary_payment'])) {
            abort_unless($class === EmployeeDetail::class, 403);
            $employee = Employee::withTrashed()->where('company_id', $companyId)->where('public_id', $page->publicId)->firstOrFail();
            $query->where('employee_id', $employee->id)->whereKey($id);
        } elseif (in_array($kind, ['customer', 'vendor'])) {
            $query->whereKey($page->payment->id);
        } else {
            $query->where('public_id', $page->publicId);
        }
        $record = $query->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($kind === 'expense' && $record->classification === Expense::CLASSIFICATION_LANDED_COST) {
            $guard->authorize($companyId, 'purchasing.cost.view');
            $guard->authorize($companyId, 'purchasing.landed_cost.manage');
        }
        $digest = [$record->getAttributes()];
        $recovery = null;
        if ($record->check_id && auth()->user()->hasPermissionTo('money.check.view')) {
            $check = Check::where('company_id', $companyId)->find($record->check_id);
            if ($check) {
                $recovery = route('money.checks.show', $check->public_id);
            }
        }
        $rows = [$this->row($record)];
        if (isset($employee)) {
            $rows[] = ['record' => __('payroll.employee'), 'account' => $employee->name];
        }
        foreach (['moneyAccount', 'fromAccount', 'toAccount'] as $accountRelation) {
            if (method_exists($record, $accountRelation)) {
                $account = $record->$accountRelation()->when($lock, fn ($q) => $q->lockForUpdate())->first();
                if ($account) {
                    abort_unless((int) $account->company_id === $companyId, 403);
                    $digest[] = $account->getAttributes();
                    $rows[] = ['record' => __('standalone.fields.'.$accountRelation), 'account' => (string) ($account->getAttribute('name_'.app()->getLocale()) ?? $account->name_ar), 'currency_code' => (string) $account->currency_code];
                }
            }
        }
        if (in_array($kind, ['customer', 'vendor'])) {
            $rows[] = ['record' => __('standalone.credit'), 'amount' => $record->unallocated_amount, 'currency_code' => $record->currency_code];
        }
        $blocked = $record->check_id !== null;
        if (in_array($kind, ['customer', 'vendor'])) {
            $events = ($kind === 'customer' ? CustomerPaymentApplicationEvent::class : VendorPaymentApplicationEvent::class)::where('company_id', $companyId)->where($kind === 'customer' ? 'customer_payment_id' : 'vendor_payment_id', $record->id)->orderBy('id')->limit(201)->when($lock, fn ($q) => $q->lockForUpdate())->get();
            abort_if($events->count() > 200, 422, __('standalone.too_many'));
            foreach ($events as $event) {
                $digest[] = $event->getAttributes();
                $rows[] = $this->row($event);
            }
        }
        foreach ($relations as $relation) {
            $related = $record->$relation()->orderBy('id')->limit(201)->when($lock, fn ($q) => $q->lockForUpdate())->get();
            abort_if($related->count() > 200, 422, __('standalone.too_many'));
            foreach ($related as $item) {
                abort_unless((int) $item->company_id === $companyId, 403);
                $digest[] = $item->getAttributes();
                $rows[] = $this->row($item);
                $allocationRow = array_key_last($rows);
                foreach (['salesInvoice', 'purchase', 'salaryEntry', 'salaryPayment', 'employeeAdvance', 'applicationEvent'] as $link) {
                    if (! method_exists($item, $link)) {
                        continue;
                    }
                    $linked = $item->$link()->when($lock, fn ($q) => $q->lockForUpdate())->first();
                    if ($linked) {
                        abort_unless((int) $linked->company_id === $companyId, 403);
                        $digest[] = $linked->getAttributes();
                        if (in_array($link, ['salesInvoice', 'purchase'])) {
                            $rows[$allocationRow]['document_currency_code'] = (string) $linked->currency_code;
                            $rows[$allocationRow]['payment_currency_code'] = (string) $record->currency_code;
                        }
                        // Document values are exposed only with their own read permission.
                        $read = match ($link) {
                            'salesInvoice' => 'sales.invoice.view', 'purchase' => 'purchasing.purchase.view',
                            'salaryEntry', 'salaryPayment' => 'payroll.salary.view',
                            default => $permission,
                        };
                        if (auth()->user()->hasPermissionTo($read)) {
                            $rows[] = $this->row($linked);
                        }
                    }
                }
                if (($kind === 'advance' && $item->status === 'active') || ($kind === 'salary' && $relation === 'paymentAllocations' && $item->status === 'active') || ($kind === 'expense' && $item->status === 'locked')) {
                    $blocked = true;
                }
            }
        }

        return ['actor' => auth()->id(), 'kind' => $kind, 'id' => (int) $record->id, 'method' => $method, 'digest' => hash('sha256', serialize($digest)), 'rows' => $rows, 'blocked' => $blocked, 'recovery' => $recovery];
    }

    /** @return array<string, string> */
    private function row(Model $record): array
    {
        $fields = ['invoice_number', 'purchase_number', 'application_date', 'reversed_at', 'applied_at', 'payment_number', 'expense_number', 'transfer_number', 'advance_number', 'salary_number', 'status', 'is_reversed', 'currency_code', 'amount', 'net_payable', 'from_currency_code', 'from_amount', 'to_currency_code', 'to_amount', 'sales_invoice_id', 'purchase_id', 'salary_entry_id', 'salary_payment_id', 'employee_advance_id', 'application_event_id', 'allocated_amount', 'payment_currency_amount', 'allocated_base'];
        $result = ['record' => __('standalone.fields.record').' #'.$record->getKey()];
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);
            if ($value !== null) {
                $result[$field] = $field === 'is_reversed' ? ($value ? __('sales.reversed') : __('money.posted')) : (string) $value;
            }
        }

        return $result;
    }
}
