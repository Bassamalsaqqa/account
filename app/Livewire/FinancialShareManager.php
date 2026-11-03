<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\PublicShare;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\DocumentRenderLimits;
use App\Services\Sales\FinancialSharePolicy;
use App\Services\Sales\IssuedFinancialShares;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class FinancialShareManager extends Component
{
    #[Locked]
    public int $companyId;

    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    #[Locked]
    public string $requestKey;

    public int $expiryDays = 7;

    public string $locale = 'ar';

    public ?string $password = null;

    public ?string $from = null;

    public ?string $to = null;

    #[Locked]
    public ?string $url = null;

    #[Locked]
    public ?string $qr = null;

    #[Locked]
    public ?string $selectedShareId = null;

    public bool $historyLoaded = false;

    public function mount(string $subjectType, int $subjectId): void
    {
        $companyContext = app(CompanyContext::class);
        $this->companyId = $companyContext->companyId();
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->requestKey = (string) Str::uuid();

        $company = $companyContext->hasCompany() ? $companyContext->company() : null;
        $currentLocale = app()->getLocale();
        $this->locale = ($company !== null && $company->isLanguageEnabled($currentLocale))
            ? $currentLocale
            : ((string) ($company !== null ? $company->default_locale : 'ar'));

        $securitySettings = $company?->securitySettings;
        $configuredExpiry = $securitySettings?->public_share_default_expiry_days;
        $defaultExpiry = ($configuredExpiry !== null && (int) $configuredExpiry > 0) ? (int) $configuredExpiry : 30;
        $this->expiryDays = $subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? min($defaultExpiry, 30) : $defaultExpiry;

        $this->authorizeShare();
    }

    public function loadHistory(): void
    {
        $this->authorizeShare();
        $this->historyLoaded = true;
    }

    private function authorizeShare(): void
    {
        abort_unless(auth()->check() && app(CompanyContext::class)->companyId() === $this->companyId, 403);
        DB::transaction(fn () => app(FinancialSharePolicy::class)->authorize($this->companyId, auth()->user(), $this->subjectType));
    }

    public function create(): void
    {
        $this->authorizeShare();

        $company = app(CompanyContext::class)->company();
        if (! $company->isLanguageEnabled($this->locale)) {
            $currentLocale = app()->getLocale();
            $this->locale = $company->isLanguageEnabled($currentLocale)
                ? $currentLocale
                : (string) ($company->default_locale ?? 'ar');
        }

        $securitySettings = $company->securitySettings;
        $configuredExpiry = $securitySettings?->public_share_default_expiry_days;
        $defaultExpiry = ($configuredExpiry !== null && (int) $configuredExpiry > 0) ? (int) $configuredExpiry : 30;
        if ($this->expiryDays <= 0) {
            $this->expiryDays = $this->subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? min($defaultExpiry, 30) : $defaultExpiry;
        }

        $this->validate([
            'expiryDays' => ['required', 'integer', 'min:1', 'max:'.($this->subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 30 : 365)],
            'locale' => ['required', 'in:ar,en'],
            'password' => [$this->subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 'required' : 'nullable', 'string', 'min:8', 'max:128'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => array_filter(['nullable', 'date_format:Y-m-d', $this->from ? 'after_or_equal:from' : null]),
        ]);

        try {
            $result = app(PublicShareService::class)->createShare(
                $company,
                auth()->user(),
                $this->subjectType,
                $this->subjectId,
                null,
                $this->password === '' ? null : $this->password,
                $this->requestKey,
                ['locale' => $this->locale, 'from' => $this->from, 'to' => $this->to],
                $this->expiryDays
            );
        } catch (\InvalidArgumentException) {
            $this->addError('share', __('sharing.issuance_unavailable'));

            return;
        }

        $this->url = $result['url'];
        $this->selectedShareId = $result['share']->public_id;
        $this->password = null;
        $this->qr = null;
        $this->historyLoaded = true;
        $this->requestKey = (string) Str::uuid();
    }

    public function recover(string $publicId): void
    {
        $this->authorizeShare();
        $this->url = null;
        $this->qr = null;
        $this->selectedShareId = null;
        $this->historyLoaded = true;

        try {
            $share = $this->managed($publicId);
            app(IssuedFinancialShares::class)->valid($share);
            $this->url = app(PublicShareService::class)->urlFor($share, auth()->user());
            $this->selectedShareId = $publicId;
        } catch (\InvalidArgumentException|DecryptException) {
            $this->addError('share', __('sharing.issuance_unavailable'));
        }
    }

    public function showQr(): void
    {
        $this->authorizeShare();
        if ($this->url !== null) {
            try {
                $this->qr = app(PdfRendererService::class)->generateQrDataUri($this->url);
            } catch (\InvalidArgumentException) {
                $this->qr = null;
                $this->addError('share', __('sharing.issuance_unavailable'));
            }
        }
    }

    public function revoke(string $publicId): void
    {
        $this->authorizeShare();
        app(PublicShareService::class)->revokeShare($this->managed($publicId), auth()->user());
        $this->url = null;
        $this->qr = null;
        $this->selectedShareId = null;
    }

    private function managed(string $publicId): PublicShare
    {
        return PublicShare::where('company_id', $this->companyId)->where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId)->where('public_id', $publicId)->firstOrFail();
    }

    public function render(): View
    {
        $this->authorizeShare();
        $preview = null;
        $previewError = null;

        try {
            $source = app(FinancialSharePolicy::class)->source($this->companyId, $this->subjectType, $this->subjectId);
            if ($source instanceof Customer) {
                $dates = Validator::make(['from' => $this->from, 'to' => $this->to], [
                    'from' => ['nullable', 'date_format:Y-m-d'],
                    'to' => array_filter(['nullable', 'date_format:Y-m-d', $this->from ? 'after_or_equal:from' : null]),
                ]);
                if ($dates->fails()) {
                    $preview = null;
                    $previewError = __('sharing.invalid_dates');
                } else {
                    app(DocumentRenderLimits::class)->assertStatementSource($this->companyId, $source->id);
                    $from = is_string($this->from) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->from) ? $this->from : null;
                    $to = is_string($this->to) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->to) ? $this->to : now(app(CompanyContext::class)->company()->timezone)->format('Y-m-d');

                    $preview = app(DocumentDataBuilder::class)->statement(app(CustomerStatementQuery::class)->execute($source, $from, $to), in_array($this->locale, ['ar', 'en'], true) ? $this->locale : 'ar');
                }
            } else {
                $preview = app(DocumentDataBuilder::class)->build($source, locale: in_array($this->locale, ['ar', 'en'], true) ? $this->locale : 'ar');
            }
        } catch (\InvalidArgumentException) {
            // A supported bound/source refusal must not break the private detail screen.
            $preview = null;
            $previewError = __('sharing.issuance_unavailable');
        }

        $shares = collect();
        $available = [];

        if ($this->historyLoaded) {
            $shares = PublicShare::where('company_id', $this->companyId)
                ->where('subject_type', $this->subjectType)
                ->where('subject_id', $this->subjectId)
                ->latest('id')
                ->limit(10)
                ->get();

            foreach ($shares as $share) {
                if (! $share->isValid()) {
                    $available[$share->public_id] = false;

                    continue;
                }
                try {
                    app(IssuedFinancialShares::class)->valid($share);
                    $available[$share->public_id] = true;
                } catch (\Throwable) {
                    $available[$share->public_id] = false;
                }
            }
        }

        if ($this->url !== null && $this->selectedShareId !== null) {
            try {
                $selected = PublicShare::where('company_id', $this->companyId)
                    ->where('subject_type', $this->subjectType)
                    ->where('subject_id', $this->subjectId)
                    ->where('public_id', $this->selectedShareId)
                    ->first();

                if ($selected === null) {
                    throw new \InvalidArgumentException('Share not found.');
                }

                app(IssuedFinancialShares::class)->valid($selected);
            } catch (\Throwable) {
                $this->url = null;
                $this->qr = null;
                $this->selectedShareId = null;
            }
        }

        $message = __('sharing.message', [], in_array($this->locale, ['ar', 'en'], true) ? $this->locale : 'ar');
        $shareMessage = $this->url === null ? null : $message."\n".$this->url;

        return view('livewire.financial-share-manager', [
            'preview' => $preview,
            'previewError' => $previewError,
            'shares' => $shares,
            'available' => $available,
            'shareMessage' => $shareMessage,
            'shareText' => $message,
        ]);
    }
}
