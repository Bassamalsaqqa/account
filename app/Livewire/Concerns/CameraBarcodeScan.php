<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Livewire\Pages\Products\ProductIndex;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait CameraBarcodeScan
{
    /**
     * Handle incoming camera barcode scan in a single server round-trip.
     *
     * @return array{ok: bool, message: string, requiresReview?: bool}
     */
    public function scanCameraBarcode(mixed $code): array
    {
        // 1. Fresh authorization, actor, company, and membership gate (failures must remain 403)
        abort_unless(auth()->check(), 403, 'Unauthorized.');

        /** @var User $user */
        $user = auth()->user();
        $context = app(CompanyContext::class);
        abort_unless($context->hasCompany(), 403, 'No active company context.');

        $company = $context->company();
        abort_unless((int) $context->user()?->id === (int) $user->id
            && Company::whereKey($company->id)->where('status', 'active')->exists(), 403);
        abort_unless($user->belongsToCompany($company->id), 403, 'User does not belong to active company.');

        // 2. Exact bounded string validation (<= 128 chars, non-empty)
        if (! is_string($code)) {
            return [
                'ok' => false,
                'message' => __('camera.invalid_code'),
            ];
        }

        $raw = trim($code);
        if ($raw === '' || mb_strlen($raw) > 128) {
            return [
                'ok' => false,
                'message' => mb_strlen($raw) > 128 ? __('camera.barcode_too_long') : __('camera.invalid_code'),
            ];
        }

        // 3. Delegate to component-specific handler if implemented (e.g. ProductIndex)
        if ($this instanceof ProductIndex) {
            return $this->handleCameraBarcodeScan($raw);
        }

        // 4. Remaining hosts are the three canonical document forms; each owns these properties and scanBarcode.
        // Standard canonical document forms (InvoiceForm, QuotationForm, PurchaseForm)
        abort_unless((int) $context->companyId() === (int) $this->pageCompanyId, 403, 'Company context mismatch.');
        $this->barcodeSearch = $raw;

        $this->resetErrorBag('barcodeSearch');

        try {
            $this->scanBarcode();
        } catch (HttpException|AuthorizationException $e) {
            // Preserve HTTP/auth 403 exceptions - never convert to success-like 200
            throw $e;
        } catch (\Throwable $e) {
            // Prevent schema / SQL / stack disclosures: return bounded localized error
            return [
                'ok' => false,
                'message' => __('camera.network_error'),
            ];
        }

        // Existing scanBarcode clears barcodeSearch ONLY on success.
        // Any failure (e.g. barcodeSearch error or lines.* quantity error) leaves barcodeSearch populated or sets error bag.
        $barcodeSearchCleared = (string) $this->barcodeSearch === '';
        $hasBarcodeError = $this->getErrorBag()->has('barcodeSearch');

        $lineError = null;
        foreach ($this->getErrorBag()->messages() as $key => $messages) {
            if (str_starts_with($key, 'lines.')) {
                $lineError = $messages[0] ?? null;
                break;
            }
        }

        if (! $barcodeSearchCleared || $hasBarcodeError) {
            $errorMessage = (string) (
                $this->getErrorBag()->first('barcodeSearch')
                ?: $lineError
                ?: __('camera.product_not_found')
            );

            return [
                'ok' => false,
                'message' => $errorMessage,
            ];
        }

        // The item was added. Do not invite a retry that would add it again, or
        // represent an earlier total as a freshly calculated document preview.
        if (! $this->documentTotalsValid) {
            return [
                'ok' => true,
                'requiresReview' => true,
                'message' => __('camera.scan_added_review'),
            ];
        }

        return [
            'ok' => true,
            'message' => __('camera.scan_success'),
        ];
    }
}
