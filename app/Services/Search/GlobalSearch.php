<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Check;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Phase7\Phase7FinancialRead;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

final class GlobalSearch
{
    public const MIN_TERM_LENGTH = 2;

    public const MAX_TERM_LENGTH = 100;

    public const MAX_RESULTS_PER_CATEGORY = 5;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly Phase7FinancialRead $phase7FinancialRead,
    ) {}

    /**
     * Execute bounded multi-category global search for active company and user.
     *
     * @return array<string, list<array{
     *     category: string,
     *     public_id: string,
     *     title: string,
     *     subtitle: string,
     *     status: string,
     *     url: string
     * }>>
     */
    public function search(string $rawQuery): array
    {
        $term = trim($rawQuery);
        $len = mb_strlen($term);

        if ($len < self::MIN_TERM_LENGTH || $len > self::MAX_TERM_LENGTH) {
            return [];
        }

        // Require actual logged in auth user, never fall back to context-only actor when logged out
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        if (! $this->companyContext->hasCompany()) {
            return [];
        }

        $companyId = (int) $this->companyContext->companyId();

        // Require matching context user id
        $contextUser = $this->companyContext->user();
        if (! $contextUser || (int) $contextUser->id !== (int) $user->id) {
            return [];
        }

        // Fresh company query on every request (do not trust cached status)
        $company = Company::whereKey($companyId)
            ->where('status', 'active')
            ->first();

        if (! $company) {
            return [];
        }

        // Fresh active membership check
        $isMember = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            return [];
        }

        // Configure Spatie team context and prevent stale role/permission cache
        setPermissionsTeamId($companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $isArabic = app()->getLocale() === 'ar';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $likePattern = "%{$escaped}%";

        $results = [];

        // 1. Customer
        if ($user->hasPermissionTo('customers.view')) {
            $customerResults = $this->searchCustomers($companyId, $likePattern, $isArabic);
            if (! empty($customerResults)) {
                $results['customer'] = $customerResults;
            }
        }

        // 2. Vendor
        if ($user->hasPermissionTo('vendors.view')) {
            $vendorResults = $this->searchVendors($companyId, $likePattern, $isArabic);
            if (! empty($vendorResults)) {
                $results['vendor'] = $vendorResults;
            }
        }

        // 3. Product (name SKU exact barcode)
        if ($user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
            $productResults = $this->searchProducts($companyId, $likePattern, $term, $isArabic);
            if (! empty($productResults)) {
                $results['product'] = $productResults;
            }
        }

        // 4. Sales Invoice
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $invoiceResults = $this->searchInvoices($companyId, $likePattern, $isArabic);
            if (! empty($invoiceResults)) {
                $results['invoice'] = $invoiceResults;
            }
        }

        // 5. Quotation
        if ($user->hasPermissionTo('sales.quote.view')) {
            $quotationResults = $this->searchQuotations($companyId, $likePattern, $isArabic);
            if (! empty($quotationResults)) {
                $results['quotation'] = $quotationResults;
            }
        }

        // 6. Purchase
        if ($user->hasPermissionTo('purchasing.purchase.view')) {
            $purchaseResults = $this->searchPurchases($companyId, $likePattern, $isArabic);
            if (! empty($purchaseResults)) {
                $results['purchase'] = $purchaseResults;
            }
        }

        // 7. Check
        if ($user->hasPermissionTo('money.check.view')) {
            $checkResults = $this->searchChecks($companyId, $likePattern);
            if (! empty($checkResults)) {
                $results['check'] = $checkResults;
            }
        }

        return $results;
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchCustomers(int $companyId, string $likePattern, bool $isArabic): array
    {
        $customers = Customer::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern) {
                $q->where('name_ar', 'like', $likePattern)
                    ->orWhere('name_en', 'like', $likePattern)
                    ->orWhere('business_name_ar', 'like', $likePattern)
                    ->orWhere('business_name_en', 'like', $likePattern)
                    ->orWhere('phone', 'like', $likePattern)
                    ->orWhere('code', 'like', $likePattern);
            })
            ->select(['id', 'public_id', 'company_id', 'name_ar', 'name_en', 'code', 'status'])
            ->orderBy('name_ar')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $customers->map(function (Customer $customer) use ($isArabic) {
            $title = $isArabic
                ? ($customer->name_ar ?: $customer->name_en)
                : ($customer->name_en ?: $customer->name_ar);

            return [
                'category' => 'customer',
                'public_id' => $customer->public_id,
                'title' => (string) $title,
                'subtitle' => (string) ($customer->code ?? ''),
                'status' => (string) ($customer->status ?? 'active'),
                'url' => route('customers.show', $customer->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchVendors(int $companyId, string $likePattern, bool $isArabic): array
    {
        $vendors = Vendor::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern) {
                $q->where('name_ar', 'like', $likePattern)
                    ->orWhere('name_en', 'like', $likePattern)
                    ->orWhere('business_name_ar', 'like', $likePattern)
                    ->orWhere('business_name_en', 'like', $likePattern)
                    ->orWhere('phone', 'like', $likePattern)
                    ->orWhere('code', 'like', $likePattern);
            })
            ->select(['id', 'public_id', 'company_id', 'name_ar', 'name_en', 'code', 'status'])
            ->orderBy('name_ar')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $vendors->map(function (Vendor $vendor) use ($isArabic) {
            $title = $isArabic
                ? ($vendor->name_ar ?: $vendor->name_en)
                : ($vendor->name_en ?: $vendor->name_ar);

            return [
                'category' => 'vendor',
                'public_id' => $vendor->public_id,
                'title' => (string) $title,
                'subtitle' => (string) ($vendor->code ?? ''),
                'status' => (string) ($vendor->status ?? 'active'),
                'url' => route('vendors.show', $vendor->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchProducts(int $companyId, string $likePattern, string $exactBarcodeTerm, bool $isArabic): array
    {
        $products = Product::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern, $exactBarcodeTerm, $companyId) {
                $q->where('name_ar', 'like', $likePattern)
                    ->orWhere('name_en', 'like', $likePattern)
                    ->orWhere('sku', 'like', $likePattern)
                    ->orWhereHas('barcodes', function (Builder $bQ) use ($exactBarcodeTerm, $companyId) {
                        $bQ->where('company_id', $companyId)
                            ->where('barcode', $exactBarcodeTerm);
                    });
            })
            ->select(['id', 'public_id', 'company_id', 'name_ar', 'name_en', 'sku', 'active'])
            ->orderBy('name_ar')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $products->map(function (Product $product) use ($isArabic) {
            $title = $isArabic
                ? ($product->name_ar ?: $product->name_en)
                : ($product->name_en ?: $product->name_ar);

            return [
                'category' => 'product',
                'public_id' => $product->public_id,
                'title' => (string) $title,
                'subtitle' => (string) ($product->sku ?? ''),
                'status' => $product->active ? 'active' : 'inactive',
                'url' => route('products.show', $product->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchInvoices(int $companyId, string $likePattern, bool $isArabic): array
    {
        $invoices = SalesInvoice::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern, $companyId) {
                $q->where('invoice_number', 'like', $likePattern)
                    ->orWhereHas('customer', function (Builder $cQ) use ($likePattern, $companyId) {
                        $cQ->where('company_id', $companyId)
                            ->where(function (Builder $names) use ($likePattern) {
                                $names->where('name_ar', 'like', $likePattern)
                                    ->orWhere('name_en', 'like', $likePattern)
                                    ->orWhere('code', 'like', $likePattern);
                            });
                    });
            })
            ->select(['id', 'public_id', 'company_id', 'invoice_number', 'issue_date', 'status', 'customer_id'])
            ->with(['customer' => fn ($c) => $c->where('company_id', $companyId)->select(['id', 'company_id', 'name_ar', 'name_en', 'code'])])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $invoices->map(function (SalesInvoice $invoice) use ($isArabic) {
            $customerName = '';
            if (($customer = $invoice->getRelation('customer')) !== null) {
                $customerName = $isArabic
                    ? ($customer->name_ar ?: $customer->name_en)
                    : ($customer->name_en ?: $customer->name_ar);
            }

            $subtitle = $customerName ?: (string) $invoice->getRawOriginal('issue_date');

            return [
                'category' => 'invoice',
                'public_id' => $invoice->public_id,
                'title' => $invoice->invoice_number ?: __('search.statuses.draft').' - '.substr($invoice->public_id, -6),
                'subtitle' => (string) $subtitle,
                'status' => (string) $invoice->status,
                'url' => route('invoices.show', $invoice->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchQuotations(int $companyId, string $likePattern, bool $isArabic): array
    {
        $quotations = Quotation::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern, $companyId) {
                $q->where('quotation_number', 'like', $likePattern)
                    ->orWhereHas('customer', function (Builder $cQ) use ($likePattern, $companyId) {
                        $cQ->where('company_id', $companyId)
                            ->where(function (Builder $names) use ($likePattern) {
                                $names->where('name_ar', 'like', $likePattern)
                                    ->orWhere('name_en', 'like', $likePattern)
                                    ->orWhere('code', 'like', $likePattern);
                            });
                    });
            })
            ->select(['id', 'public_id', 'company_id', 'quotation_number', 'issue_date', 'status', 'customer_id'])
            ->with(['customer' => fn ($c) => $c->where('company_id', $companyId)->select(['id', 'company_id', 'name_ar', 'name_en', 'code'])])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $quotations->map(function (Quotation $quotation) use ($isArabic) {
            $customerName = '';
            if ($quotation->customer) {
                $customerName = $isArabic
                    ? ($quotation->customer->name_ar ?: $quotation->customer->name_en)
                    : ($quotation->customer->name_en ?: $quotation->customer->name_ar);
            }

            $subtitle = $customerName ?: (string) $quotation->getRawOriginal('issue_date');

            return [
                'category' => 'quotation',
                'public_id' => $quotation->public_id,
                'title' => $quotation->quotation_number ?: __('search.statuses.draft').' - '.substr($quotation->public_id, -6),
                'subtitle' => (string) $subtitle,
                'status' => (string) $quotation->status,
                'url' => route('quotations.show', $quotation->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchPurchases(int $companyId, string $likePattern, bool $isArabic): array
    {
        $purchases = Purchase::where('company_id', $companyId)
            ->where(function (Builder $q) use ($likePattern, $companyId) {
                $q->where('purchase_number', 'like', $likePattern)
                    ->orWhere('vendor_invoice_number', 'like', $likePattern)
                    ->orWhereHas('vendor', function (Builder $vQ) use ($likePattern, $companyId) {
                        $vQ->where('company_id', $companyId)
                            ->where(function (Builder $names) use ($likePattern) {
                                $names->where('name_ar', 'like', $likePattern)
                                    ->orWhere('name_en', 'like', $likePattern)
                                    ->orWhere('code', 'like', $likePattern);
                            });
                    });
            })
            ->select(['id', 'public_id', 'company_id', 'purchase_number', 'vendor_invoice_number', 'purchase_date', 'status', 'vendor_id'])
            ->with(['vendor' => fn ($v) => $v->where('company_id', $companyId)->select(['id', 'company_id', 'name_ar', 'name_en', 'code'])])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $purchases->map(function (Purchase $purchase) use ($isArabic) {
            $vendorName = '';
            if ($purchase->vendor) {
                $vendorName = $isArabic
                    ? ($purchase->vendor->name_ar ?: $purchase->vendor->name_en)
                    : ($purchase->vendor->name_en ?: $purchase->vendor->name_ar);
            }

            $displayNumber = $purchase->purchase_number ?: ($purchase->vendor_invoice_number ?: $purchase->public_id);
            $subtitle = $vendorName ?: (string) $purchase->getRawOriginal('purchase_date');

            return [
                'category' => 'purchase',
                'public_id' => $purchase->public_id,
                'title' => (string) $displayNumber,
                'subtitle' => (string) $subtitle,
                'status' => (string) $purchase->status,
                'url' => route('purchases.show', $purchase->public_id),
            ];
        })->all();
    }

    /**
     * @return list<array{category: string, public_id: string, title: string, subtitle: string, status: string, url: string}>
     */
    private function searchChecks(int $companyId, string $likePattern): array
    {
        $visibleCheckIds = $this->phase7FinancialRead->visibleCheckIds($companyId, true);

        $checks = Check::where('company_id', $companyId)
            ->whereIn('id', $visibleCheckIds)
            ->where('check_number', 'like', $likePattern)
            ->select(['id', 'public_id', 'company_id', 'check_number', 'direction', 'due_date', 'status'])
            ->orderByDesc('due_date')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS_PER_CATEGORY)
            ->get();

        return $checks->map(function (Check $check) {
            $directionLabel = $check->direction
                ? __('search.direction_'.$check->direction)
                : '';
            $dateLabel = (string) $check->getRawOriginal('due_date');
            $subtitle = trim($directionLabel.($dateLabel !== '' ? ' · '.$dateLabel : ''));

            return [
                'category' => 'check',
                'public_id' => $check->public_id,
                'title' => (string) $check->check_number,
                'subtitle' => $subtitle,
                'status' => (string) $check->status,
                'url' => route('money.checks.show', $check->public_id),
            ];
        })->all();
    }
}
