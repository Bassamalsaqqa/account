<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Sales\Settings\DocumentSettingsForm;
use App\Models\Company;
use App\Models\User;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\PdfRendererService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSampleRenderTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        app(CompanyRoleService::class)->ensurePermissionsExist();

        $this->owner = User::factory()->create([
            'name' => 'مالك المنشأة',
            'email' => 'owner@example.com',
            'locale' => 'ar',
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التاجر للتجارة العامة',
            'name_en' => 'Small Trader General Trading Co.',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_all_18_sample_pdf_and_html_combinations_render_cleanly(): void
    {
        $templates = ['classic', 'modern', 'compact'];
        $types = ['invoice', 'quotation', 'customer_payment'];
        $locales = ['ar', 'en'];

        $pdfRenderer = app(PdfRendererService::class);
        $docRenderer = app(DocumentRenderer::class);

        $manifest = [];
        $renderedHtmlByCombination = [];
        $renderedPdfByCombination = [];

        foreach ($templates as $tmpl) {
            foreach ($types as $type) {
                foreach ($locales as $loc) {
                    $key = "{$tmpl}-{$type}-{$loc}";

                    // Use strictly supported accent colors from DocumentSettingsForm::SUPPORTED_ACCENT_COLORS
                    $accentColor = match ($tmpl) {
                        'modern' => '#0f766e',
                        'compact' => '#1e293b',
                        default => '#255fd6',
                    };
                    $this->assertContains($accentColor, DocumentSettingsForm::SUPPORTED_ACCENT_COLORS);

                    $form = new DocumentSettingsForm;
                    $form->settingsCompanyId = $this->company->id;
                    $form->template = $tmpl;
                    $form->accent_color = $accentColor;
                    $form->preview_type = $type;
                    $form->default_document_locale = $loc;

                    $docData = $form->getSampleDocument();

                    // 1. Verify DocumentData properties & allowed accent color reaches presentation
                    $this->assertSame($tmpl, $docData->presentation['template'] ?? null);
                    $this->assertSame($accentColor, $docData->presentation['accent_color'] ?? null);
                    $this->assertSame($loc, $docData->locale);

                    // 2. Render HTML & verify language direction and accent presence
                    $html = $docRenderer->html($docData, printControls: false);
                    $this->assertNotEmpty($html);
                    $this->assertStringContainsString($loc === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);
                    $this->assertStringContainsString($accentColor, $html);

                    if ($tmpl === 'modern') {
                        $this->assertStringContainsString('class="header-banner"', $html);
                    }

                    // 3. Render PDF bytes via mPDF
                    $pdfBytes = $pdfRenderer->renderDocument($docData);
                    $this->assertNotEmpty($pdfBytes);
                    $this->assertStringStartsWith('%PDF-', $pdfBytes);
                    $this->assertGreaterThan(5000, strlen($pdfBytes));

                    $comboKey = "{$type}-{$loc}";
                    $renderedHtmlByCombination[$comboKey][$tmpl] = $html;
                    $renderedPdfByCombination[$comboKey][$tmpl] = $pdfBytes;

                    $manifest[] = [
                        'key' => $key,
                        'template' => $tmpl,
                        'type' => $type,
                        'locale' => $loc,
                        'accent_color' => $accentColor,
                        'html_bytes' => strlen($html),
                        'pdf_bytes' => strlen($pdfBytes),
                        'pdf_file' => "pdf/{$key}.pdf",
                        'html_file' => "html/{$key}.html",
                        'direction' => $loc === 'ar' ? 'rtl' : 'ltr',
                    ];
                }
            }
        }

        // Assert that Classic, Modern, and Compact have genuine distinct HTML and PDF outputs
        foreach ($types as $type) {
            foreach ($locales as $loc) {
                $comboKey = "{$type}-{$loc}";
                $classicHtml = $renderedHtmlByCombination[$comboKey]['classic'];
                $modernHtml = $renderedHtmlByCombination[$comboKey]['modern'];
                $compactHtml = $renderedHtmlByCombination[$comboKey]['compact'];

                $this->assertNotEquals($classicHtml, $modernHtml, "HTML for {$comboKey} must differ between classic and modern");
                $this->assertNotEquals($classicHtml, $compactHtml, "HTML for {$comboKey} must differ between classic and compact");
                $this->assertNotEquals($modernHtml, $compactHtml, "HTML for {$comboKey} must differ between modern and compact");

                $classicPdf = $renderedPdfByCombination[$comboKey]['classic'];
                $modernPdf = $renderedPdfByCombination[$comboKey]['modern'];
                $compactPdf = $renderedPdfByCombination[$comboKey]['compact'];

                $this->assertNotEquals($classicPdf, $modernPdf, "PDF for {$comboKey} must differ between classic and modern");
                $this->assertNotEquals($classicPdf, $compactPdf, "PDF for {$comboKey} must differ between classic and compact");
                $this->assertNotEquals($modernPdf, $compactPdf, "PDF for {$comboKey} must differ between modern and compact");
            }
        }

        $this->assertCount(18, $manifest);
    }
}
