<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales\Settings;

use App\Domain\Sales\Documents\DocumentData;
use App\Services\Sales\DocumentPresentation;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\DocumentSettingsService;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class DocumentSettingsForm extends Component
{
    use WithFileUploads;

    public const array SUPPORTED_TEMPLATES = ['classic', 'modern', 'compact'];

    public const array SUPPORTED_ACCENT_COLORS = ['#255fd6', '#0f766e', '#1e293b', '#7c3aed'];

    public const array SUPPORTED_LOCALES = ['ar', 'en'];

    public const array SUPPORTED_PREVIEW_TYPES = ['invoice', 'quotation', 'customer_payment'];

    public ?TemporaryUploadedFile $logo = null;

    #[Locked]
    public int $settingsCompanyId;

    public string $default_document_locale = 'ar';

    public string $template = 'classic';

    public string $accent_color = '#255fd6';

    public string $preview_type = 'invoice';

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
        $this->template = (string) ($settings->getAttribute('template') ?? 'classic');
        $this->accent_color = (string) ($settings->getAttribute('accent_color') ?? '#255fd6');
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
            'default_document_locale' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_LOCALES)],
            'template' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_TEMPLATES)],
            'accent_color' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_ACCENT_COLORS)],
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

        $writtenLogo = null;
        try {
            $settings = DB::transaction(function () use ($company, $actor, &$writtenLogo) {
                $settings = app(DocumentSettingsService::class)->save($company, $actor, [
                    'default_document_locale' => $this->default_document_locale,
                    'template' => $this->template,
                    'accent_color' => $this->accent_color,
                    'show_logo' => $this->show_logo,
                    'show_qr_by_default' => $this->show_qr_by_default,
                    'show_product_images_on_quotes' => $this->show_product_images_on_quotes,
                    'invoice_footer_ar' => $this->invoice_footer_ar,
                    'invoice_footer_en' => $this->invoice_footer_en,
                    'quotation_terms_ar' => $this->quotation_terms_ar,
                    'quotation_terms_en' => $this->quotation_terms_en,
                ]);
                if ($this->logo !== null) {
                    $writtenLogo = app(DocumentSettingsService::class)->uploadLogo($company, $actor, $this->logo);
                }

                return $settings;
            });
        } catch (\Throwable $error) {
            if (is_string($writtenLogo)) {
                Storage::disk('public')->delete($writtenLogo);
            }
            throw $error;
        }
        $this->reset('logo');

        $this->default_document_locale = (string) $settings->default_document_locale;
        $this->template = (string) ($settings->getAttribute('template') ?? 'classic');
        $this->accent_color = (string) ($settings->getAttribute('accent_color') ?? '#255fd6');
        $this->show_logo = (bool) $settings->show_logo;
        $this->show_qr_by_default = (bool) $settings->show_qr_by_default;
        $this->show_product_images_on_quotes = (bool) $settings->show_product_images_on_quotes;
        $this->invoice_footer_ar = $settings->invoice_footer_ar;
        $this->invoice_footer_en = $settings->invoice_footer_en;
        $this->quotation_terms_ar = $settings->quotation_terms_ar;
        $this->quotation_terms_en = $settings->quotation_terms_en;

        session()->flash('success', __('documents.settings_saved_successfully'));
    }

    public function getSampleDocument(): DocumentData
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            abort(403, 'Company context is required.');
        }
        $company = $context->company();

        // Strict allowlist validation and fallback for preview inputs
        $previewLocale = in_array($this->default_document_locale, self::SUPPORTED_LOCALES, true) ? $this->default_document_locale : 'ar';
        $template = in_array($this->template, self::SUPPORTED_TEMPLATES, true) ? $this->template : 'classic';
        $accentColor = in_array($this->accent_color, self::SUPPORTED_ACCENT_COLORS, true) ? $this->accent_color : '#255fd6';
        $previewType = in_array($this->preview_type, self::SUPPORTED_PREVIEW_TYPES, true) ? $this->preview_type : 'invoice';

        $isAr = $previewLocale === 'ar';
        $curr = $company->base_currency_code ?: 'SAR';

        // Clearly fictional sample party data with small Preview indicators
        $companyInfo = [
            'name' => $company->displayName().($isAr ? ' (معاينة)' : ' (Preview)'),
            'business_name' => $company->displayName(),
            'address' => $company->getAttribute('address_'.$previewLocale) ?: ($isAr ? 'المنطقة التجارية، مبنى الأعمال' : 'Commercial District, Business Center'),
            'phone' => $company->phone ?: '+000 00 000 0000',
            'email' => $company->email ?: 'info@company.example.com',
            'tax_number' => $company->tax_number,
            'registration_number' => $company->registration_number,
        ];

        $customerInfo = [
            'name' => $isAr ? 'شركة الأمل للتجارة (عميل تجريبي)' : 'Al-Amal Trading (Sample Customer)',
            'phone' => '+000 00 000 0001',
            'email' => 'sample@customer.example.com',
            'tax_number' => null,
            'registration_number' => null,
            'address' => $isAr ? 'شارع التجارة، المركز التجاري' : 'Trade St, Commercial Center',
            'business_name' => null,
        ];

        if ($previewType === 'customer_payment') {
            $docType = 'customer_payment';
            $docNumber = 'REC-2026-0089';
            $lines = [
                [
                    'line_number' => '1',
                    'item_description' => 'INV-2026-0158',
                    'sku' => null,
                    'quantity' => '1.00',
                    'unit_name' => null,
                    'unit_price' => '1667.50',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'total' => '1667.50',
                    'currency_code' => $curr,
                    'payment_currency_code' => $curr,
                    'payment_currency_amount' => '1667.50',
                ],
            ];
            $docData = [
                'number' => $docNumber,
                'issue_date' => date('Y-m-d'),
                'date' => date('Y-m-d'),
                'status' => 'posted',
                'currency_code' => $curr,
                'subtotal' => '1667.50',
                'discount_total' => '0.00',
                'tax_total' => '0.00',
                'total' => '1667.50',
                'grand_total' => '1667.50',
                'reference' => 'TXN-2026-0042',
                'money_account' => $isAr ? 'حساب مصرف الراجحي الرئيسي' : 'Al Rajhi Primary Bank Account',
                'payment_method' => 'bank_transfer',
                'notes' => $isAr ? 'سند قبض تجريبي لمعاينة القالب والتصميم' : 'Sample customer receipt preview for layout and styling verification',
                'terms' => null,
            ];
        } else {
            $docType = $previewType === 'quotation' ? 'quotation' : 'sales_invoice';
            $docNumber = $previewType === 'quotation' ? 'QT-2026-0042' : 'INV-2026-0158';
            $lines = [
                [
                    'line_number' => '1',
                    'item_description' => $isAr ? 'خدمات استشارية تقنية متقدمة' : 'Advanced Technical Advisory Services',
                    'sku' => 'SRV-ADV-01',
                    'quantity' => '1.00',
                    'unit_name' => $isAr ? 'خدمة' : 'srv',
                    'unit_price' => '1000.00',
                    'discount' => '0.00',
                    'tax' => '150.00',
                    'total' => '1150.00',
                ],
                [
                    'line_number' => '2',
                    'item_description' => $isAr ? 'باقة صيانة دورية سنوية' : 'Annual Periodic Maintenance Package',
                    'sku' => 'PKG-MNT-12',
                    'quantity' => '2.00',
                    'unit_name' => $isAr ? 'قطعة' : 'ea',
                    'unit_price' => '250.00',
                    'discount' => '50.00',
                    'tax' => '67.50',
                    'total' => '517.50',
                ],
            ];
            $docData = [
                'number' => $docNumber,
                'issue_date' => date('Y-m-d'),
                'date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'status' => 'posted',
                'currency_code' => $curr,
                'subtotal' => '1500.00',
                'discount_total' => '50.00',
                'tax_total' => '217.50',
                'total' => '1667.50',
                'grand_total' => '1667.50',
                'notes' => $isAr ? 'عينة نموذجية لمعاينة التصميم والشعار والألوان' : 'Sample preview for layout, logo and color verification',
                'terms' => $previewType === 'quotation'
                    ? ($isAr ? ($this->quotation_terms_ar ?: 'عرض السعر سارٍ لمدة 15 يوماً من تاريخ الإصدار.') : ($this->quotation_terms_en ?: 'Quotation is valid for 15 days from issue date.'))
                    : null,
            ];
            if ($previewType === 'quotation') {
                $docData['valid_until'] = date('Y-m-d', strtotime('+15 days'));
            }
        }

        $cleanText = static function (?string $value): ?string {
            if ($value === null) {
                return null;
            }
            $stripped = trim(strip_tags($value));

            return $stripped === '' ? null : mb_substr($stripped, 0, 2000);
        };

        $footerAr = $cleanText($this->invoice_footer_ar) ?: 'شكراً لتعاملكم معنا';
        $footerEn = $cleanText($this->invoice_footer_en) ?: 'Thank you for your business';
        $footer = $isAr ? $footerAr : $footerEn;

        $logoDataUri = null;
        if ($this->show_logo) {
            $logoDataUri = app(DocumentPresentation::class)->forCompany((int) $company->id, $previewLocale)['logo'];
        }

        $presentation = [
            'template' => $template,
            'accent_color' => $accentColor,
            'show_qr' => $this->show_qr_by_default,
            'footer' => $footer,
            'logo' => $logoDataUri,
        ];

        return new DocumentData(
            type: $docType,
            locale: $previewLocale,
            company: $companyInfo,
            customer: $customerInfo,
            document: $docData,
            lines: $lines,
            statement: null,
            presentation: $presentation,
            applications: [],
        );
    }

    public function getSamplePreviewHtmlProperty(): string
    {
        return app(DocumentRenderer::class)->html($this->getSampleDocument(), printControls: false);
    }

    public function downloadSamplePdf(): StreamedResponse
    {
        $this->authorizeSettings();

        // Fail closed if state is outside strict allowlists
        if (! in_array($this->template, self::SUPPORTED_TEMPLATES, true)
            || ! in_array($this->accent_color, self::SUPPORTED_ACCENT_COLORS, true)
            || ! in_array($this->default_document_locale, self::SUPPORTED_LOCALES, true)
            || ! in_array($this->preview_type, self::SUPPORTED_PREVIEW_TYPES, true)) {
            abort(422, 'Invalid document preview configuration.');
        }

        $sample = $this->getSampleDocument();
        $pdfBytes = app(PdfRendererService::class)->renderDocument($sample);

        return response()->streamDownload(
            static function () use ($pdfBytes): void {
                echo $pdfBytes;
            },
            "sample-document-{$this->template}-{$this->default_document_locale}.pdf",
            ['Content-Type' => 'application/pdf']
        );
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
