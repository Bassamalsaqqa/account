<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\CompanyDocumentSettings;
use Illuminate\Support\Facades\Storage;

/** Current decoration only. Never fills gaps in historical identity or commercial terms. */
final class DocumentPresentation
{
    /** @return array<string, string|bool|null> */
    public function forCompany(int $companyId, string $locale): array
    {
        $company = Company::whereKey($companyId)->firstOrFail();
        $settings = CompanyDocumentSettings::where('company_id', $companyId)->first();

        return [
            'logo' => ($settings->show_logo ?? true) ? $this->logo($companyId, $company->logo_path) : null,
            'footer' => $settings?->getAttribute('invoice_footer_'.$locale),
            'show_qr' => $settings->show_qr_by_default ?? false,
        ];
    }

    private function logo(int $companyId, ?string $path): ?string
    {
        if ($path === null || ! preg_match('#^companies/'.$companyId.'/[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp)$#D', $path)) {
            return null;
        }
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $file = realpath($disk->path($path));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            || ! is_file($file) || ! is_readable($file) || filesize($file) > 512 * 1024) {
            return null;
        }
        $info = @getimagesize($file);
        if ($info === false || $info[0] > 2048 || $info[1] > 2048
            || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        $bytes = file_get_contents($file);

        return $bytes === false ? null : 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
    }
}
