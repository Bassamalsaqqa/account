<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Livewire\Concerns\HandlesSearchableSelectors;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\OpeningTradeItem;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\OpeningTradeAmount;
use App\Rules\PositiveExactDecimal;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** @var list<array{amount: string, currency_code?: string, exchange_rate?: ?string, original_reference?: ?string, due_date?: ?string, notes?: ?string}> */
    public array $additional_lines = [];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public ?string $glOverlapMessage = null;

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizeAccess();
        }
    }

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
        $this->authorizeAccess();
        $this->resetValidation();
        $this->party_id = null;
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->checkGlOverlap();
    }

    public function updatedCurrencyCode(): void
    {
        $this->authorizeAccess();
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = null;
        } elseif ($this->exchange_rate === null || $this->exchange_rate === '') {
            $this->exchange_rate = '1.0000000000';
        }
        foreach ($this->additional_lines as &$line) {
            $line['currency_code'] = $this->currency_code;
        }
        unset($line);
    }

    public function checkGlOverlap(): void
    {
        $this->authorizeAccess();

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
        $this->authorizeAccess();
        $this->has_multiple_lines = true;
        $this->additional_lines[] = [
            'amount' => '',
            'currency_code' => $this->currency_code,
            'original_reference' => '',
            'due_date' => '',
            'notes' => '',
        ];
    }

    public function removeAdditionalLine(int $index): void
    {
        $this->authorizeAccess();
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
            'amount' => ['required', new OpeningTradeAmount((int) $company->id, $this->currency_code)],
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
            $lineCurrency = (string) ($line['currency_code'] ?? $this->currency_code);
            $rules[$prefix.'amount'] = ['required', new OpeningTradeAmount((int) $company->id, $lineCurrency)];
            $rules[$prefix.'currency_code'] = ['required', 'string', 'size:3', 'in:'.$this->currency_code];
            if (! $isBase) {
                $rules[$prefix.'exchange_rate'] = ['nullable', 'same:exchange_rate'];
            }
            $rules[$prefix.'original_reference'] = 'nullable|string|max:100';
            $rules[$prefix.'due_date'] = 'nullable|date_format:Y-m-d';
            $rules[$prefix.'notes'] = 'nullable|string|max:500';
        }

        $existingMain = OpeningTradeItem::where('company_id', $company->id)
            ->where(function ($q): void {
                $q->where('idempotency_key', $this->formIdempotencyKey)
                    ->orWhere('idempotency_key', 'like', $this->formIdempotencyKey.':%')
                    ->orWhere('control_route->form->form_key', $this->formIdempotencyKey);
            })
            ->first();

        if ($existingMain === null) {
            $this->validate($rules);
        } else {
            // Authorized canonical replay: validate structural fields without blocking on fresh currency disablement
            $replayRules = [
                'side' => 'required|in:customer,vendor',
                'party_id' => 'required|integer|min:1',
                'amount' => 'required|string',
                'currency_code' => 'required|string|size:3',
                'opening_date' => 'required|date_format:Y-m-d',
                'due_date' => 'nullable|date_format:Y-m-d',
                'original_reference' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ];
            if (! $isBase) {
                $replayRules['exchange_rate'] = ['required', new PositiveExactDecimal(10)];
            }
            foreach ($this->additional_lines as $index => $line) {
                $prefix = 'additional_lines.'.$index.'.';
                $replayRules[$prefix.'amount'] = 'required|string';
                $replayRules[$prefix.'currency_code'] = ['required', 'string', 'size:3', 'in:'.$this->currency_code];
                if (! $isBase) {
                    $replayRules[$prefix.'exchange_rate'] = ['nullable', 'same:exchange_rate'];
                }
                $replayRules[$prefix.'original_reference'] = 'nullable|string|max:100';
                $replayRules[$prefix.'due_date'] = 'nullable|date_format:Y-m-d';
                $replayRules[$prefix.'notes'] = 'nullable|string|max:500';
            }
            $this->validate($replayRules);
        }

        /** @var User $actor */
        $actor = auth()->user();

        $rows = [
            [
                'amount' => $this->amount,
                'original_reference' => $this->original_reference,
                'due_date' => $this->due_date,
                'notes' => $this->notes,
            ],
        ];

        foreach ($this->additional_lines as $line) {
            $rows[] = [
                'amount' => (string) $line['amount'],
                'original_reference' => $line['original_reference'] ?? null,
                'due_date' => $line['due_date'] ?? null,
                'notes' => $line['notes'] ?? null,
            ];
        }

        try {
            $action = app(PostOpeningTradeItemAction::class);
            $action->executeForm(
                company: $company,
                side: $this->side,
                partyId: (int) $this->party_id,
                openingDate: $this->opening_date,
                currencyCode: $this->currency_code,
                exchangeRate: $isBase ? null : $this->exchange_rate,
                rows: $rows,
                actor: $actor,
                formIdempotencyKey: $this->formIdempotencyKey,
                sourceIdentity: 'manual_web',
            );

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
        } catch (IdempotencyConflictException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = __('accounting.opening_post_failed');
        }
    }

    public function getPartyName(): string
    {
        $this->authorizeAccess();

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
        if (! auth()->check()) {
            throw new AuthorizationException('Unauthenticated.');
        }
        /** @var User $user */
        $user = auth()->user();

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $this->pageCompanyId || ! $user->belongsToCompany($this->pageCompanyId)) {
            throw new AuthorizationException('Company context mismatch or inactive membership.');
        }

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        if ($this->side === OpeningTradeItem::SIDE_CUSTOMER) {
            if (! $user->hasPermissionTo('customers.manage') || ! $user->hasPermissionTo('settings.accounting.manage')) {
                throw new AuthorizationException('Missing required customer opening trade permissions.');
            }
        } elseif ($this->side === OpeningTradeItem::SIDE_VENDOR) {
            if (! $user->hasPermissionTo('vendors.manage') || ! $user->hasPermissionTo('settings.accounting.manage') || ! $user->hasPermissionTo('purchasing.cost.view')) {
                throw new AuthorizationException('Missing required vendor opening trade permissions.');
            }
        } else {
            throw new AuthorizationException('Invalid opening trade side.');
        }
    }

    /** @return list<string> */
    protected function allowedSearchableSelectorTypes(): array
    {
        return [$this->side === OpeningTradeItem::SIDE_CUSTOMER ? 'customer' : 'vendor'];
    }

    protected function searchableSelectorContext(): string
    {
        return 'management';
    }

    public function render(): View
    {
        $this->authorizeAccess();

        $company = app(CompanyContext::class)->company();
        $isBase = $this->currency_code === $company->base_currency_code;
        $carryingBasePreview = null;

        if (! $isBase && ! empty($this->exchange_rate)) {
            try {
                $rate = BigDecimal::of($this->exchange_rate);
                if ($rate->isPositive()) {
                    $totalCarryingSixPlace = BigDecimal::zero();
                    if (! empty($this->amount)) {
                        $mainAmt = BigDecimal::of($this->amount);
                        if ($mainAmt->isPositive()) {
                            $rowCarrying = $mainAmt->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
                            $totalCarryingSixPlace = $totalCarryingSixPlace->plus($rowCarrying);
                        }
                    }
                    foreach ($this->additional_lines as $line) {
                        $lineAmtRaw = trim((string) $line['amount']);
                        if ($lineAmtRaw !== '') {
                            $lineAmt = BigDecimal::of($lineAmtRaw);
                            if ($lineAmt->isPositive()) {
                                $rowCarrying = $lineAmt->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
                                $totalCarryingSixPlace = $totalCarryingSixPlace->plus($rowCarrying);
                            }
                        }
                    }
                    if ($totalCarryingSixPlace->isPositive()) {
                        $carryingBasePreview = (string) $totalCarryingSixPlace->toScale(2, RoundingMode::HALF_UP);
                    }
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
