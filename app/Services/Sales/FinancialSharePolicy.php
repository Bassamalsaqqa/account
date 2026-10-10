<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class FinancialSharePolicy
{
    /** @return list<string> */
    public function permissions(string $type): array
    {
        return ['sales.document.share', ...match ($type) {
            PublicShare::SUBJECT_QUOTATION => ['sales.quote.view'],
            PublicShare::SUBJECT_SALES_INVOICE => ['sales.invoice.view'],
            PublicShare::SUBJECT_SALES_RETURN => ['sales.return.view'],
            PublicShare::SUBJECT_CUSTOMER_PAYMENT => ['money.receipt.view', 'money.receipt.share'],
            PublicShare::SUBJECT_CUSTOMER_STATEMENT => ['sales.statement.view', 'customers.statement.view'],
            default => throw new InvalidArgumentException('Unsupported public subject.'),
        }];
    }

    public function authorize(int $companyId, User $actor, string $type): void
    {
        foreach ($this->permissions($type) as $permission) {
            app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, $permission);
        }
    }

    public function source(int $companyId, string $type, int $id): Quotation|SalesInvoice|SalesReturn|CustomerPayment|Customer
    {
        return CompanyScope::executeWithoutScope(function () use ($companyId, $type, $id) {
            if (! Company::whereKey($companyId)->where('status', 'active')->exists()) {
                throw new InvalidArgumentException('Inactive share company.');
            }
            $class = match ($type) {
                PublicShare::SUBJECT_QUOTATION => Quotation::class,
                PublicShare::SUBJECT_SALES_INVOICE => SalesInvoice::class,
                PublicShare::SUBJECT_SALES_RETURN => SalesReturn::class,
                PublicShare::SUBJECT_CUSTOMER_PAYMENT => CustomerPayment::class,
                PublicShare::SUBJECT_CUSTOMER_STATEMENT => Customer::class,
                default => throw new InvalidArgumentException('Unsupported public subject.'),
            };
            $source = $class::where('company_id', $companyId)->whereKey($id)->firstOrFail();
            $valid = match (true) {
                $source instanceof Customer => (bool) $source->active,
                $source instanceof Quotation => ! $source->isDraft(),
                $source instanceof CustomerPayment => $source->posting_batch_id !== null && ! $source->is_reversed,
                default => $source->isPosted(),
            };
            if (! $valid) {
                throw new InvalidArgumentException('Retired or ineligible share source.');
            }

            return $source;
        });
    }

    public function quotationRevision(Quotation $source): string
    {
        return CompanyScope::executeWithoutScope(function () use ($source): string {
            if ($source->lines()->where('company_id', $source->company_id)->count() > DocumentRenderLimits::MAX_DOCUMENT_LINES) {
                throw new InvalidArgumentException('Quotation exceeds the supported source limit.');
            }
            // Commercial source fields only. Decoration/primary-image changes never rewrite an issued representation.
            $header = $source->only(['public_id', 'status', 'quotation_number', 'customer_snapshot', 'company_snapshot', 'currency_code', 'exchange_rate', 'issue_date', 'expiry_date', 'notes', 'terms', 'document_locale', 'include_product_images', 'subtotal_currency', 'discount_total_currency', 'tax_total_currency', 'grand_total_currency']);
            $header['issue_date'] = $source->issue_date->format('Y-m-d');
            $header['expiry_date'] = $source->expiry_date?->format('Y-m-d');
            $header['lines'] = $source->lines()->where('company_id', $source->company_id)->orderBy('line_number')
                ->get()->map(fn (Model $line) => $line->only(['line_number', 'item_description', 'quantity', 'unit_price', 'line_discount', 'line_tax', 'line_total', 'unit_name_ar', 'unit_name_en', 'product_sku', 'product_name_ar', 'product_name_en', 'unit_conversion_ratio']))->all();

            return hash('sha256', app(IssuedDocumentContent::class)->canonical($header));
        });
    }
}
