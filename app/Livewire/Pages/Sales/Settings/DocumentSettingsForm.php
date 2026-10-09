<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Services\Sales\DocumentSettingsService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class DocumentSettingsForm extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $logo = null;

    #[Locked]
    public int $settingsCompanyId;

    public string $default_document_locale = 'ar';

    public bool $show_logo = true;

    public bool $show_qr_by_default = false;

    public bool $show_product_images_on_quotes = false;

    public ?string $invoice_footer_ar = null;

    public ?string $invoice_footer_en = null;

    public ?string $quotation_terms_ar = null;

    public ?string $quotation_terms_en = null;

    public function mount(CompanyContext $context): void
    {
        $this->settingsCompanyId = (int) $context->companyId();
        $this->authorizeSettings();

        $company = $context->company();
        $actor = auth()->user();

        $settings = app(DocumentSettingsService::class)->get($company, $actor);

        $this->default_document_locale = (string) $settings->default_document_locale;
        $this->show_logo = (bool) $settings->show_logo;
        $this->show_qr_by_default = (bool) $settings->show_qr_by_default;
        $this->show_product_images_on_quotes = (bool) $settings->show_product_images_on_quotes;
        $this->invoice_footer_ar = $settings->invoice_footer_ar;
        $this->invoice_footer_en = $settings->invoice_footer_en;
        $this->quotation_terms_ar = $settings->quotation_terms_ar;
        $this->quotation_terms_en = $settings->quotation_terms_en;
    }

    public function save(CompanyContext $context): void
    {
        $this->authorizeSettings();

        $this->validate([
            'default_document_locale' => ['required', 'string', 'in:ar,en'],
            'show_logo' => ['boolean'],
            'show_qr_by_default' => ['boolean'],
            'show_product_images_on_quotes' => ['boolean'],
            'invoice_footer_ar' => ['nullable', 'string', 'max:2000'],
            'invoice_footer_en' => ['nullable', 'string', 'max:2000'],
            'quotation_terms_ar' => ['nullable', 'string', 'max:5000'],
            'quotation_terms_en' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=2048,max_height=2048'],
        ]);

        $company = $context->company();
        $actor = auth()->user();

        $settings = DB::transaction(function () use ($company, $actor) {
            $settings = app(DocumentSettingsService::class)->save($company, $actor, [
                'default_document_locale' => $this->default_document_locale,
                'show_logo' => $this->show_logo,
                'show_qr_by_default' => $this->show_qr_by_default,
                'show_product_images_on_quotes' => $this->show_product_images_on_quotes,
                'invoice_footer_ar' => $this->invoice_footer_ar,
                'invoice_footer_en' => $this->invoice_footer_en,
                'quotation_terms_ar' => $this->quotation_terms_ar,
                'quotation_terms_en' => $this->quotation_terms_en,
            ]);
            if ($this->logo !== null) {
                app(DocumentSettingsService::class)->uploadLogo($company, $actor, $this->logo);
            }

            return $settings;
        });
        $this->reset('logo');

        $this->default_document_locale = (string) $settings->default_document_locale;
        $this->show_logo = (bool) $settings->show_logo;
        $this->show_qr_by_default = (bool) $settings->show_qr_by_default;
        $this->show_product_images_on_quotes = (bool) $settings->show_product_images_on_quotes;
        $this->invoice_footer_ar = $settings->invoice_footer_ar;
        $this->invoice_footer_en = $settings->invoice_footer_en;
        $this->quotation_terms_ar = $settings->quotation_terms_ar;
        $this->quotation_terms_en = $settings->quotation_terms_en;

        session()->flash('success', __('documents.settings_saved_successfully'));
    }

    private function authorizeSettings(): void
    {
        abort_unless(auth()->check(), 403);

        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== $this->settingsCompanyId) {
            abort(403);
        }

        try {
            DB::transaction(function (): void {
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->settingsCompanyId,
                    auth()->user(),
                    'settings.company.view'
                );
                app(SalesActorGuard::class)->lockAndAuthorize(
                    $this->settingsCompanyId,
                    auth()->user(),
                    'settings.documents.manage'
                );
            });
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    public function render(): View
    {
        $this->authorizeSettings();

        return view('livewire.pages.sales.settings.document-settings-form');
    }
}
