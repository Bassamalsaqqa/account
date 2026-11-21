<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Application\Platform\TenantWriteAccess;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Livewire\Concerns\HandlesSearchableSelectors;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\OpeningTradeItem;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\PositiveExactDecimal;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class OpeningTradeBalanceEntry extends Component
{
    use HandlesSearchableSelectors;

    #[Locked]
    public int $pageCompanyId;

    public string $side = OpeningTradeItem::SIDE_CUSTOMER; // 'customer' | 'vendor'

    public ?int $party_id = null;

    public string $amount = '';

    public string $currency_code = 'ILS';

    public ?string $exchange_rate = null;

    public ?string $original_reference = null;

    public ?string $due_date = null;

    public ?string $notes = null;

    public string $opening_date = '';

    public string $formIdempotencyKey = '';

    public bool $has_multiple_lines = false;

    /** @var list<array{amount: string, currency_code?: string, exchange_rate: ?string, original_reference: ?string, due_date: ?string, notes: ?string}> */
    public array $additional_lines = [];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public ?string $glOverlapMessage = null;

    public function mount(CompanyContext $context): void
    {
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $this->authorizeAccess();

        $this->currency_code = $company->base_currency_code;
        $this->opening_date = now($company->timezone)->toDateString();
        $this->formIdempotencyKey = (string) Str::ulid();

        $this->checkGlOverlap();
    }

    public function updatedSide(): void
    {
        $this->resetValidation();
        $this->party_id = null;
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->authorizeAccess();
        $this->checkGlOverlap();
    }

    public function updatedCurrencyCode(): void
    {
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = null;
        } elseif ($this->exchange_rate === null || $this->exchange_rate === '') {
            $this->exchange_rate = '1.0000000000';
        }
    }

    public function checkGlOverlap(): void
    {
        if (PostOpeningTradeItemAction::hasLegacyOverlap($this->pageCompanyId, $this->side)) {
            $this->glOverlapMessage = $this->side === OpeningTradeItem::SIDE_CUSTOMER
                ? __('accounting.gl_overlap_customer')
                : __('accounting.gl_overlap_vendor');
        } else {
            $this->glOverlapMessage = null;
        }
    }

    public function addAdditionalLine(): void
    {
        $this->has_multiple_lines = true;
        $this->additional_lines[] = [
            'amount' => '',
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'original_reference' => '',
            'due_date' => '',
            'notes' => '',
        ];
    }

    public function removeAdditionalLine(int $index): void
    {
        unset($this->additional_lines[$index]);
        $this->additional_lines = array_values($this->additional_lines);
        if (empty($this->additional_lines)) {
            $this->has_multiple_lines = false;
        }
    }

    public function save(): void
    {
        $this->postOpeningBalance();
    }

    public function postOpeningBalance(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->authorizeAccess();

        $company = app(CompanyContext::class)->company();
        $isBase = $this->currency_code === $company->base_currency_code;

        $rules = [
            'side' => 'required|in:customer,vendor',
            'party_id' => 'required|integer|min:1',
            'amount' => ['required', new PositiveExactDecimal(6)],
            'currency_code' => 'required|string|size:3',
            'opening_date' => 'required|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d',
            'original_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ];

        if (! $isBase) {
            $rules['exchange_rate'] = ['required', new PositiveExactDecimal(10)];
        }

        foreach ($this->additional_lines as $index => $line) {
            $prefix = 'additional_lines.'.$index.'.';
            $rules[$prefix.'amount'] = ['required', new PositiveExactDecimal(6)];
            $rules[$prefix.'currency_code'] = 'required|string|size:3';
            $rules[$prefix.'exchange_rate'] = [($line['currency_code'] ?? '') === $company->base_currency_code ? 'nullable' : 'required', new PositiveExactDecimal(10)];
            $rules[$prefix.'original_reference'] = 'nullable|string|max:100';
            $rules[$prefix.'due_date'] = 'nullable|date_format:Y-m-d';
            $rules[$prefix.'notes'] = 'nullable|string|max:500';
        }
        $this->validate($rules);

        $action = app(PostOpeningTradeItemAction::class);
        /** @var User $actor */
        $actor = auth()->user();

        try {
            app(TenantWriteAccess::class)->transaction((int) $company->id, function () use ($action, $company, $actor): void {
                // Post main item
                $action->execute(
                    company: $company,
                    side: $this->side,
                    partyId: (int) $this->party_id,
                    openingDate: $this->opening_date,
                    amount: $this->amount,
                    currencyCode: $this->currency_code,
                    exchangeRate: $this->exchange_rate,
                    actor: $actor,
                    idempotencyKey: $this->formIdempotencyKey,
                    originalReference: $this->original_reference,
                    dueDate: $this->due_date,
                    notes: $this->notes,
                    sourceIdentity: 'manual_web'
                );

                // Post any additional lines
                foreach ($this->additional_lines as $index => $line) {

                    $action->execute(
                        company: $company,
                        side: $this->side,
                        partyId: (int) $this->party_id,
                        openingDate: $this->opening_date,
                        amount: (string) $line['amount'],
                        currencyCode: (string) $line['currency_code'],
                        exchangeRate: $line['exchange_rate'] ?? $this->exchange_rate,
                        actor: $actor,
                        idempotencyKey: $this->formIdempotencyKey.':line:'.$index,
                        originalReference: ! empty($line['original_reference']) ? (string) $line['original_reference'] : null,
                        dueDate: ! empty($line['due_date']) ? (string) $line['due_date'] : null,
                        notes: ! empty($line['notes']) ? (string) $line['notes'] : null,
                        sourceIdentity: 'manual_web'
                    );

                }

            });

            $partyName = $this->getPartyName();
            $this->successMessage = $this->side === OpeningTradeItem::SIDE_CUSTOMER
                ? __('accounting.success_customer', ['name' => $partyName])
                : __('accounting.success_vendor', ['name' => $partyName]);

            $this->amount = '';
            $this->original_reference = null;
            $this->due_date = null;
            $this->notes = null;
            $this->additional_lines = [];
            $this->has_multiple_lines = false;
            $this->formIdempotencyKey = (string) Str::ulid();
        } catch (LegacyGlOpeningOverlapException $e) {
            $this->glOverlapMessage = $this->side === OpeningTradeItem::SIDE_CUSTOMER
                ? __('accounting.gl_overlap_customer')
                : __('accounting.gl_overlap_vendor');
        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = __('accounting.opening_post_failed');
        }
    }

    public function getPartyName(): string
    {
        if (! $this->party_id) {
            return '';
        }
        $locale = app()->getLocale();
        if ($this->side === OpeningTradeItem::SIDE_CUSTOMER) {
            $c = Customer::where('company_id', $this->pageCompanyId)->find($this->party_id);
            if (! $c) {
                return '';
            }

            return $locale === 'en' ? ($c->name_en ?: $c->name_ar) : ($c->name_ar ?: $c->name_en);
        }
        $v = Vendor::where('company_id', $this->pageCompanyId)->find($this->party_id);
        if (! $v) {
            return '';
        }

        return $locale === 'en' ? ($v->name_en ?: $v->name_ar) : ($v->name_ar ?: $v->name_en);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->check(), 403);
        /** @var User $user */
        $user = auth()->user();
        abort_unless($user->belongsToCompany($this->pageCompanyId), 403);

        if ($this->side === OpeningTradeItem::SIDE_CUSTOMER) {
            abort_unless($user->hasPermissionTo('customers.manage') && $user->hasPermissionTo('settings.accounting.manage'), 403);
        } else {
            abort_unless($user->hasPermissionTo('vendors.manage') && $user->hasPermissionTo('settings.accounting.manage') && $user->hasPermissionTo('purchasing.cost.view'), 403);
        }
    }

    /** @return list<string> */
    protected function allowedSearchableSelectorTypes(): array
    {
        return ['customer', 'vendor'];
    }

    protected function searchableSelectorContext(): string
    {
        return 'management';
    }

    public function render(): View
    {
        $company = app(CompanyContext::class)->company();
        $isBase = $this->currency_code === $company->base_currency_code;
        $carryingBasePreview = null;

        if (! $isBase && ! empty($this->amount) && ! empty($this->exchange_rate)) {
            try {
                $amt = BigDecimal::of($this->amount);
                $rate = BigDecimal::of($this->exchange_rate);
                if ($amt->isPositive() && $rate->isPositive()) {
                    $carryingBasePreview = (string) $amt->multipliedBy($rate)->toScale(2, RoundingMode::HALF_UP);
                }
            } catch (\Throwable) {
                $carryingBasePreview = null;
            }
        }

        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->pluck('currency_code')
            ->all();

        return view('livewire.pages.accounting.opening-trade-balance-entry', [
            'company' => $company,
            'isBase' => $isBase,
            'carryingBasePreview' => $carryingBasePreview,
            'currencies' => $currencies,
            'selectedPartyLabel' => $this->getPartyName(),
        ]);
    }
}
