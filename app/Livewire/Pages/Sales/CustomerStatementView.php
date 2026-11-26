<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Livewire\FinancialShareManager;
use App\Models\Customer;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerStatementView extends Component
{
    #[Locked]
    public int $companyId;

    #[Locked]
    public Customer $customer;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        abort_unless(auth()->check() && $context->hasCompany(), 403);
        $company = $context->company();
        app(ReportingGuard::class)->authorize($company, requiredPermissions: ['sales.statement.view']);

        $this->companyId = (int) $company->id;
        $this->customer = Customer::where('company_id', $this->companyId)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->fromDate = $this->normalizeDateInput($this->fromDate)
            ?? Carbon::now($company->timezone)->startOfYear()->toDateString();
        $this->toDate = $this->normalizeDateInput($this->toDate)
            ?? Carbon::now($company->timezone)->toDateString();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['fromDate', 'toDate'], true)) {
            return;
        }

        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->companyId() === $this->companyId, 403);
        app(ReportingGuard::class)->authorize($context->company(), requiredPermissions: ['sales.statement.view']);

        $customer = Customer::where('company_id', $this->companyId)
            ->whereKey($this->customer->id)
            ->firstOrFail();

        $this->fromDate = $this->normalizeDateInput($this->fromDate);
        $this->toDate = $this->normalizeDateInput($this->toDate);

        $this->dispatch(
            'customer-statement-dates-updated.customer_statement.'.$customer->id,
            subjectType: 'customer_statement',
            subjectId: $customer->id,
            publicId: $customer->public_id,
            fromDate: $this->fromDate,
            toDate: $this->toDate,
        )->to(FinancialShareManager::class);
    }

    public function render(CompanyContext $context, CustomerStatementQuery $statementQuery): View
    {
        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->companyId() === $this->companyId, 403);
        $company = $context->company();
        $permissions = app(ReportingGuard::class)->readPermissions($company, requiredPermissions: ['sales.statement.view']);
        $customer = Customer::where('company_id', $this->companyId)->whereKey($this->customer->id)->firstOrFail();

        $this->fromDate = $this->normalizeDateInput($this->fromDate);
        $this->toDate = $this->normalizeDateInput($this->toDate);

        $this->resetValidation(['fromDate', 'toDate']);
        $validator = validator(['fromDate' => $this->fromDate, 'toDate' => $this->toDate], [
            'fromDate' => ['required', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:fromDate'],
        ]);
        foreach ($validator->errors()->messages() as $field => $messages) {
            $this->addError($field, __('reports.date_range_error'));
        }
        $statements = $validator->fails() ? ['currencies' => []] : $statementQuery->execute($customer, $this->fromDate, $this->toDate);

        $canShare = collect(['sales.document.share', 'sales.statement.view', 'customers.statement.view'])
            ->every(fn (string $permission): bool => in_array($permission, $permissions, true));

        return view('livewire.pages.sales.customer-statement-view', [
            'statements' => $statements['currencies'],
            'company' => $company,
            'canShare' => $canShare,
        ]);
    }

    private function normalizeDateInput(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
