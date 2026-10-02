<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerStatementView extends Component
{
    public Customer $customer;

    public ?string $shareUrl = null;

    public int $shareExpiryDays = 30;

    public ?string $sharePassword = null;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.statement.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->customer = Customer::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if ($this->fromDate === null) {
            $this->fromDate = Carbon::now()->startOfYear()->toDateString();
        }
        if ($this->toDate === null) {
            $this->toDate = Carbon::now()->toDateString();
        }
    }

    public function createShareLink(PublicShareService $service): void
    {
        $this->validate(['shareExpiryDays' => ['integer', 'min:0', 'max:3650'], 'sharePassword' => ['nullable', 'string', 'max:255']]);
        $company = app(CompanyContext::class)->company();
        $result = $service->createShare($company, auth()->user(), PublicShare::SUBJECT_CUSTOMER_STATEMENT, $this->customer->id,
            $this->shareExpiryDays === 0 ? null : Carbon::now()->addDays($this->shareExpiryDays), $this->sharePassword);
        $this->shareUrl = $result['url'];
    }

    public function revokeShareLink(PublicShareService $service): void
    {
        $company = app(CompanyContext::class)->company();
        foreach (PublicShare::where('company_id', $company->id)->where('subject_type', PublicShare::SUBJECT_CUSTOMER_STATEMENT)->where('subject_id', $this->customer->id)->where('is_active', true)->get() as $share) {
            $service->revokeShare($share, auth()->user());
        }
        $this->shareUrl = null;
    }

    public function render(CompanyContext $context, CustomerStatementQuery $statementQuery): View
    {
        $company = $context->company();
        $statements = $statementQuery->execute($this->customer, $this->fromDate ?: null, $this->toDate ?: null);

        return view('livewire.pages.sales.customer-statement-view', [
            'statements' => $statements['currencies'],
            'company' => $company,
        ]);
    }
}
