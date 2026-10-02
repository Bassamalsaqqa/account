<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use InvalidArgumentException;

final class DocumentDataBuilder
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, string|null>
     */
    private function party(array $snapshot, string $locale): array
    {
        $name = $snapshot['name_'.$locale] ?? $snapshot['name_ar'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Document identity snapshot is missing.');
        }
        $result = ['name' => $name];
        foreach (['phone', 'email', 'tax_number', 'registration_number'] as $key) {
            $result[$key] = isset($snapshot[$key]) && is_string($snapshot[$key]) ? $snapshot[$key] : null;
        }
        foreach (['address', 'business_name'] as $key) {
            $value = $snapshot[$key.'_'.$locale] ?? ($key === 'address' ? ($snapshot['address_line_1_'.$locale] ?? null) : null) ?? $snapshot[$key.'_ar'] ?? $snapshot[$key] ?? null;
            $result[$key] = is_string($value) ? $value : null;
        }

        return $result;
    }

    public function build(SalesInvoice|Quotation|SalesReturn|CustomerPayment $source, bool $forPdf = false): DocumentData
    {
        $locale = $source->getAttribute('document_locale') ?: 'ar';
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported document locale.');
        }
        $companySnapshot = $source->getAttribute('company_snapshot');
        $customerSnapshot = $source->getAttribute('customer_snapshot');
        // Draft previews may read current identity; posted documents must use their snapshots exclusively.
        if ($source instanceof SalesInvoice && $source->isDraft()) {
            $companySnapshot = $source->company->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en', 'tax_number']);
            $customerSnapshot = $source->customer->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en', 'business_name_ar', 'business_name_en', 'business_name', 'address_line_1_ar', 'address_line_1_en']);
        }
        if (! is_array($companySnapshot) || ! is_array($customerSnapshot)) {
            throw new InvalidArgumentException('Historical document identity snapshots are required.');
        }
        $isPayment = $source instanceof CustomerPayment;
        $type = match (true) {
            $source instanceof SalesInvoice => 'sales_invoice', $source instanceof SalesReturn => 'sales_return',
            $source instanceof Quotation => 'quotation', default => 'customer_payment',
        };
        $numberField = match ($type) {
            'sales_invoice' => 'invoice_number', 'sales_return' => 'return_number', 'quotation' => 'quotation_number', default => 'payment_number',
        };
        $document = ['number' => $source->getAttribute($numberField), 'status' => $isPayment ? ($source->is_reversed ? 'reversed' : 'posted') : $source->getAttribute('status'),
            'issue_date' => ($isPayment ? $source->payment_date : $source->getAttribute('issue_date'))->format('Y-m-d'),
            'currency_code' => (string) $source->currency_code];
        foreach (['subtotal' => 'subtotal_currency', 'discount_total' => 'discount_total_currency', 'tax_total' => 'tax_total_currency', 'grand_total' => 'grand_total_currency'] as $field => $column) {
            $document[$field] = $isPayment ? ($field === 'grand_total' ? (string) $source->amount : '0.000000') : (string) $source->getAttribute($column);
        }
        $document['notes'] = $source->getAttribute('notes');
        $document['terms'] = $isPayment ? null : $source->getAttribute('terms');
        $lines = [];
        if (! $isPayment) {
            foreach ($source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get() as $line) {
                $lines[] = ['line_number' => (string) $line->line_number,
                    'item_description' => $line->getAttribute('product_name_'.$locale) ?: $line->item_description,
                    'unit_name' => $line->getAttribute('unit_name_'.$locale) ?: $line->unit_name_ar,
                    'sku' => $line->product_sku, 'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price, 'discount' => (string) $line->line_discount,
                    'tax' => (string) $line->line_tax, 'total' => (string) $line->line_total];
                if ($source instanceof Quotation && $source->include_product_images) {
                    $image = app(QuotationMedia::class)->primary((int) $source->company_id, $line->product_id, $forPdf);
                    if ($image !== null) {
                        $lines[array_key_last($lines)]['image'] = $image;
                    }
                }
            }
        } else {
            $document['reference'] = $source->reference_number;
            $document['money_account'] = $source->getAttribute('money_account_snapshot')['name_'.$locale] ?? null;
            foreach ($source->allocations()->where('company_id', $source->company_id)->whereNull('application_event_id')->get() as $allocation) {
                $lines[] = ['item_description' => $allocation->salesInvoice->invoice_number ?? '', 'quantity' => '1',
                    'unit_name' => null, 'sku' => null, 'unit_price' => (string) $allocation->allocated_amount,
                    'discount' => '0', 'tax' => '0', 'total' => (string) $allocation->allocated_amount];
            }
        }

        return new DocumentData($type, $locale, $this->party($companySnapshot, $locale), $this->party($customerSnapshot, $locale), $document, $lines);
    }

    /** @param array<string, mixed> $statement */
    public function statement(array $statement): DocumentData
    {
        /** @var Customer $customer */
        $customer = $statement['customer'];
        $locale = $customer->preferred_locale ?: 'ar';
        $company = Company::findOrFail($customer->company_id);
        unset($statement['customer']);

        return new DocumentData('customer_statement', $locale,
            $this->party($company->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en']), $locale),
            $this->party($customer->only(['name_ar', 'name_en', 'phone', 'email', 'business_name_ar', 'business_name_en', 'business_name', 'address_line_1_ar', 'address_line_1_en']), $locale),
            ['number' => $customer->code, 'issue_date' => now($company->timezone)->format('Y-m-d')], statement: $statement);
    }
}
