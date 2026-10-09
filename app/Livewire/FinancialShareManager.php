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

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->companyId = app(CompanyContext::class)->companyId();
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->requestKey = (string) Str::uuid();
        $this->locale = app()->getLocale();
        $this->expiryDays = $subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 7 : 30;
        $this->authorizeShare();
    }

    private function authorizeShare(): void
    {
        abort_unless(auth()->check() && app(CompanyContext::class)->companyId() === $this->companyId, 403);
        DB::transaction(fn () => app(FinancialSharePolicy::class)->authorize($this->companyId, auth()->user(), $this->subjectType));
    }

    public function create(): void
    {
        $this->authorizeShare();
        $this->validate(['expiryDays' => ['required', 'integer', 'min:1', 'max:'.($this->subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 30 : 365)], 'locale' => ['required', 'in:ar,en'],
            'password' => [$this->subjectType === PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 'required' : 'nullable', 'string', 'min:8', 'max:128'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_filter(['nullable', 'date_format:Y-m-d', $this->from ? 'after_or_equal:from' : null])]);
        try {
            $result = app(PublicShareService::class)->createShare(app(CompanyContext::class)->company(), auth()->user(), $this->subjectType, $this->subjectId,
                now()->startOfDay()->addDays($this->expiryDays), $this->password === '' ? null : $this->password, $this->requestKey,
                ['locale' => $this->locale, 'from' => $this->from, 'to' => $this->to]);
        } catch (\InvalidArgumentException) {
            $this->addError('share', __('sharing.issuance_unavailable'));

            return;
        }
        $this->url = $result['url'];
        $this->password = null;
        $this->qr = null;
        $this->requestKey = (string) Str::uuid();
    }

    public function recover(string $publicId): void
    {
        $this->authorizeShare();
        $this->url = app(PublicShareService::class)->urlFor($this->managed($publicId), auth()->user());
        $this->qr = null;
    }

    public function showQr(): void
    {
        $this->authorizeShare();
        if ($this->url !== null) {
            $this->qr = app(PdfRendererService::class)->generateQrDataUri($this->url);
        }
    }

    public function revoke(string $publicId): void
    {
        $this->authorizeShare();
        app(PublicShareService::class)->revokeShare($this->managed($publicId), auth()->user());
        $this->url = null;
        $this->qr = null;
    }

    private function managed(string $publicId): PublicShare
    {
        return PublicShare::where('company_id', $this->companyId)->where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId)->where('public_id', $publicId)->firstOrFail();
    }

    public function render(): View
    {
        $this->authorizeShare();
        $preview = DB::transaction(function () {
            $this->authorizeShare();
            $source = app(FinancialSharePolicy::class)->source($this->companyId, $this->subjectType, $this->subjectId);
            if ($source instanceof Customer) {
                $dates = Validator::make(['from' => $this->from, 'to' => $this->to], [
                    'from' => ['nullable', 'date_format:Y-m-d'],
                    'to' => array_filter(['nullable', 'date_format:Y-m-d', $this->from ? 'after_or_equal:from' : null]),
                ]);
                if ($dates->fails()) {
                    return null;
                }
                app(DocumentRenderLimits::class)->assertStatementSource($this->companyId, $source->id);
                $from = is_string($this->from) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->from) ? $this->from : null;
                $to = is_string($this->to) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->to) ? $this->to : now(app(CompanyContext::class)->company()->timezone)->format('Y-m-d');

                return app(DocumentDataBuilder::class)->statement(app(CustomerStatementQuery::class)->execute($source, $from, $to), in_array($this->locale, ['ar', 'en'], true) ? $this->locale : 'ar');
            }

            return app(DocumentDataBuilder::class)->build($source, locale: in_array($this->locale, ['ar', 'en'], true) ? $this->locale : 'ar');
        });

        $this->authorizeShare();
        $shares = PublicShare::where('company_id', $this->companyId)->where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId)->latest('id')->limit(50)->get();
        $available = [];
        foreach ($shares as $share) {
            try {
                app(IssuedFinancialShares::class)->valid($share);
                $available[$share->public_id] = true;
            } catch (\Throwable) {
                $available[$share->public_id] = false;
            }
        }

        return view('livewire.financial-share-manager', ['preview' => $preview, 'shares' => $shares, 'available' => $available]);
    }
}
