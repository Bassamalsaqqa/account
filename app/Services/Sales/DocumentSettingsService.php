<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\CompanyDocumentSettings;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class DocumentSettingsService
{
    public function uploadLogo(Company $company, User $actor, UploadedFile $file): string
    {
        $path = null;
        try {
            DB::transaction(function () use ($company, $actor, $file, &$path): void {
                app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.company.view');
                app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.documents.manage');
                Validator::make(['logo' => $file], ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=2048,max_height=2048']])->validate();
                $extension = match ($file->getMimeType()) {
                    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
                    default => throw new \InvalidArgumentException('Unsupported logo format.'),
                };
                $path = $file->storeAs('companies/'.$company->id, Str::uuid().'.'.$extension, 'public');
                if (! is_string($path)) {
                    throw new \RuntimeException('Logo storage failed.');
                }
                Company::whereKey($company->id)->update(['logo_path' => $path]);
                app(AuditService::class)->log(companyId: $company->id, eventKey: 'settings.logo_updated', summary: 'Current decorative logo updated', actorUserId: $actor->id, subject: $company);
            });
            if (! is_string($path)) {
                throw new \RuntimeException('Logo storage did not complete.');
            }

            return $path;
        } catch (\Throwable $error) {
            if (is_string($path)) {
                Storage::disk('public')->delete($path);
            }
            throw $error;
        }
    }

    /**
     * Retrieve the document settings for the company under strict authorization.
     */
    public function get(Company $company, User $actor): CompanyDocumentSettings
    {
        return DB::transaction(function () use ($company, $actor): CompanyDocumentSettings {
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.company.view');
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.documents.manage');

            $settings = CompanyDocumentSettings::where('company_id', $company->id)->first();

            if ($settings === null) {
                $settings = new CompanyDocumentSettings([
                    'company_id' => $company->id,
                    'default_document_locale' => 'ar',
                    'show_logo' => true,
                    'show_qr_by_default' => false,
                    'show_product_images_on_quotes' => false,
                    'invoice_footer_ar' => null,
                    'invoice_footer_en' => null,
                    'quotation_terms_ar' => null,
                    'quotation_terms_en' => null,
                ]);
            }

            return $settings;
        });
    }

    /**
     * Validate, sanitize, and save document settings atomically with auditing.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Company $company, User $actor, array $data): CompanyDocumentSettings
    {
        return DB::transaction(function () use ($company, $actor, $data): CompanyDocumentSettings {
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.company.view');
            app(SalesActorGuard::class)->lockAndAuthorize($company->id, $actor, 'settings.documents.manage');

            $values = Validator::make($data, [
                'default_document_locale' => ['required', 'string', 'in:ar,en'],
                'show_logo' => ['required', 'boolean'],
                'show_qr_by_default' => ['required', 'boolean'],
                'show_product_images_on_quotes' => ['required', 'boolean'],
                'invoice_footer_ar' => ['nullable', 'string', 'max:2000'],
                'invoice_footer_en' => ['nullable', 'string', 'max:2000'],
                'quotation_terms_ar' => ['nullable', 'string', 'max:5000'],
                'quotation_terms_en' => ['nullable', 'string', 'max:5000'],
            ])->validate();

            $cleanText = static function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }
                $stripped = trim(strip_tags($value));

                return $stripped === '' ? null : $stripped;
            };

            /** @var CompanyDocumentSettings $settings */
            $settings = CompanyDocumentSettings::firstOrNew(['company_id' => $company->id]);

            $safeFields = [
                'default_document_locale',
                'show_logo',
                'show_qr_by_default',
                'show_product_images_on_quotes',
                'invoice_footer_ar',
                'invoice_footer_en',
                'quotation_terms_ar',
                'quotation_terms_en',
            ];

            $before = $settings->exists ? $settings->only($safeFields) : null;

            $settings->company_id = $company->id;
            $settings->default_document_locale = (string) $values['default_document_locale'];
            $settings->show_logo = (bool) $values['show_logo'];
            $settings->show_qr_by_default = (bool) $values['show_qr_by_default'];
            $settings->show_product_images_on_quotes = (bool) $values['show_product_images_on_quotes'];
            $settings->invoice_footer_ar = $cleanText($values['invoice_footer_ar'] ?? null);
            $settings->invoice_footer_en = $cleanText($values['invoice_footer_en'] ?? null);
            $settings->quotation_terms_ar = $cleanText($values['quotation_terms_ar'] ?? null);
            $settings->quotation_terms_en = $cleanText($values['quotation_terms_en'] ?? null);
            $settings->save();

            $after = $settings->only($safeFields);

            app(AuditService::class)->log(
                companyId: $company->id,
                eventKey: 'settings.documents_updated',
                summary: "Document settings updated by {$actor->name}",
                actorUserId: $actor->id,
                subject: $settings,
                before: $before,
                after: $after,
            );

            return $settings->refresh();
        });
    }
}
