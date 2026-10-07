<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Domain\Money\Queries\SettlementTargetsQuery;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\Vendor;
use App\Services\Money\EligibleMoneyAccounts;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class CheckForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $direction;

    #[Locked]
    public string $requestKey;

    public ?int $partyId = null;

    public ?int $bankId = null;

    public string $date = '';

    public string $dueDate = '';

    public string $number = '';

    public string $bankName = '';

    public string $drawer = '';

    public string $currency = '';

    public string $amount = '';

    public string $rate = '';

    public string $notes = '';

    /** @var array<int, array{document?: string, payment?: string}> */
    public array $allocations = [];

    public function mount(string $direction): void
    {
        $this->direction = $direction;
        $company = $this->authorizeForm();
        $this->date = $this->dueDate = Carbon::now($company->timezone)->toDateString();
        $this->currency = $company->base_currency_code;
        $this->rate = '1';
        $this->requestKey = (string) Str::uuid();
    }

    private function authorizeForm(): Company
    {
        $company = $this->authorizeCheckDirection($this->direction, true);
        $this->authorizeMoney($this->direction === 'incoming' ? 'money.receipt.create' : 'money.vendor_payment.create');

        return $company;
    }

    public function updatedPartyId(): void
    {
        $this->authorizeForm();
        $this->allocations = [];
    }

    public function updatedCurrency(): void
    {
        $company = $this->authorizeForm();
        $this->bankId = null;
        $this->rate = $this->currency === $company->base_currency_code ? '1' : '';
        $this->allocations = [];
    }

    public function save(): mixed
    {
        $company = $this->authorizeForm();
        $this->validate(['partyId' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'dueDate' => 'required|date_format:Y-m-d',
            'number' => 'required|string|max:100', 'bankName' => 'required|string|max:200', 'drawer' => 'nullable|string|max:200',
            'amount' => 'required|string', 'rate' => 'required|string', 'notes' => 'nullable|string|max:2000']);
        $allocations = [];
        foreach ($this->allocations as $id => $row) {
            if (trim($row['document'] ?? '') === '') {
                continue;
            }
            $allocations[] = [$this->direction === 'incoming' ? 'sales_invoice_id' : 'purchase_id' => $id, 'allocated_amount' => $row['document'], 'payment_currency_amount' => $row['payment'] ?? ''];
        }
        $data = ['party_id' => $this->partyId, 'money_account_id' => $this->bankId, 'date' => $this->date, 'due_date' => $this->dueDate,
            'check_number' => $this->number, 'bank_name' => $this->bankName, 'drawer' => $this->drawer, 'currency_code' => $this->currency,
            'amount' => $this->amount, 'exchange_rate' => $this->rate, 'notes' => $this->notes, 'idempotency_key' => $this->requestKey, 'allocations' => $allocations];
        try {
            $check = $this->direction === 'incoming' ? app(ReceiveCheckAction::class)->execute($company, auth()->user(), $data) : app(IssueCheckAction::class)->execute($company, auth()->user(), $data);
        } catch (\InvalidArgumentException|MathException $exception) {
            $this->addError('check', __('money.invalid_request'));

            return null;
        }

        return redirect()->route('money.checks.show', $check->public_id);
    }

    public function render(): View
    {
        $this->authorizeForm();
        $parties = $this->direction === 'incoming' ? Customer::where('company_id', $this->pageCompanyId)->where('status', 'active')->orderBy('name_ar')->get()
            : Vendor::withTrashed()->where('company_id', $this->pageCompanyId)->orderBy('name_ar')->get();
        $targets = $this->partyId === null ? [] : app(SettlementTargetsQuery::class)->forParty($this->pageCompanyId, $this->partyId, $this->direction === 'incoming' ? 'customer' : 'vendor');

        return view('livewire.pages.money.check-form', ['parties' => $parties, 'targets' => $targets,
            'banks' => app(EligibleMoneyAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $this->currency)->get(),
            'currencies' => CompanyCurrency::where('company_id', $this->pageCompanyId)->where('enabled', true)->pluck('currency_code')]);
    }
}
