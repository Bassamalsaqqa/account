<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\PostingBatch;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use Illuminate\Support\Carbon;
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

    public function build(SalesInvoice|Quotation|SalesReturn|CustomerPayment $source, bool $forPdf = false, ?string $locale = null, bool $includeApplications = false): DocumentData
    {
        $locale ??= $source->getAttribute('document_locale') ?: 'ar';
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
        if ($source instanceof SalesInvoice) {
            $document['due_date'] = $source->due_date?->format('Y-m-d');
        }
        if ($source instanceof Quotation) {
            $document['valid_until'] = $source->expiry_date?->format('Y-m-d');
        }
        $batch = $source->getAttribute('posting_batch_id') === null ? null : PostingBatch::where('company_id', $source->company_id)->findOrFail($source->getAttribute('posting_batch_id'));
        $document['base_currency_code'] = $batch?->base_currency_code;
        $document['exchange_rate'] = $source->getAttribute('exchange_rate');
        $document['amount_base'] = $isPayment ? (string) $source->amount_base : $source->getAttribute('grand_total_base');
        if ($source instanceof SalesInvoice) {
            $document['payment_status'] = $source->derivedPaymentStatus();
        }
        if ($source instanceof SalesReturn && $source->sales_invoice_id !== null) {
            $document['original_reference'] = SalesInvoice::where('company_id', $source->company_id)->findOrFail($source->sales_invoice_id)->invoice_number;
        }
        $lines = [];
        $applications = [];
        $imageBytes = 0;
        if (! $isPayment) {
            if ($source->lines()->where('company_id', $source->company_id)->count() > DocumentRenderLimits::MAX_DOCUMENT_LINES) {
                throw new InvalidArgumentException('Document exceeds the supported line limit.');
            }
            foreach ($source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get() as $line) {
                $lines[] = ['line_number' => (string) $line->line_number,
                    'item_description' => DocumentDescription::choose($line->item_description, $line->getAttribute('product_name_ar'), $line->getAttribute('product_name_en'), $locale),
                    'unit_name' => $line->getAttribute('unit_name_'.$locale) ?: $line->unit_name_ar,
                    'sku' => $line->product_sku, 'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price, 'discount' => (string) $line->line_discount,
                    'tax' => (string) $line->line_tax, 'total' => (string) $line->line_total];
                if ($source instanceof Quotation && $source->include_product_images) {
                    $image = app(QuotationMedia::class)->primary((int) $source->company_id, $line->product_id, $forPdf);
                    if ($image !== null) {
                        $imageBytes += strlen($image);
                        if ($imageBytes > 4 * 1024 * 1024) {
                            throw new InvalidArgumentException('Document images exceed the preparation budget.');
                        }
                        $lines[array_key_last($lines)]['image'] = $image;
                    }
                }
            }
        } else {
            $document['reference'] = $source->reference_number;
            $document['money_account'] = $source->getAttribute('money_account_snapshot')['name_'.$locale] ?? null;
            $document['payment_method'] = $source->payment_method;
            $originalAllocations = $source->allocations()->where('company_id', $source->company_id)->whereNull('application_event_id');
            if ((clone $originalAllocations)->count() > DocumentRenderLimits::MAX_DOCUMENT_LINES) {
                throw new InvalidArgumentException('Receipt exceeds the supported allocation limit.');
            }
            foreach ($originalAllocations->orderBy('id')->get() as $allocation) {
                $invoice = SalesInvoice::where('company_id', $source->company_id)->findOrFail($allocation->sales_invoice_id);
                $lines[] = ['item_description' => $invoice->invoice_number ?? '', 'quantity' => '1',
                    'unit_name' => null, 'sku' => null, 'unit_price' => (string) $allocation->allocated_amount,
                    'discount' => '0', 'tax' => '0', 'total' => (string) $allocation->allocated_amount,
                    'currency_code' => $invoice->currency_code, 'payment_currency_code' => $source->currency_code,
                    'payment_currency_amount' => (string) $allocation->payment_currency_amount,
                    'base_currency_code' => $batch?->base_currency_code,
                    'settlement_base_value' => (string) $allocation->settlement_base_value];
            }
            if ($includeApplications) {
                $later = $source->allocations()->where('company_id', $source->company_id)->whereNotNull('application_event_id');
                if ((clone $later)->count() + count($lines) > DocumentRenderLimits::MAX_DOCUMENT_LINES) {
                    throw new InvalidArgumentException('Receipt exceeds the supported allocation limit.');
                }
                foreach ($later->orderBy('id')->get() as $allocation) {
                    $invoice = SalesInvoice::where('company_id', $source->company_id)->findOrFail($allocation->sales_invoice_id);
                    $event = CustomerPaymentApplicationEvent::where('company_id', $source->company_id)
                        ->where('customer_payment_id', $source->id)->findOrFail($allocation->application_event_id);
                    $applications[] = ['item_description' => $invoice->invoice_number, 'application_date' => Carbon::parse($event->application_date)->format('Y-m-d'),
                        'status' => $event->reversed_at === null ? 'posted' : 'reversed',
                        'currency_code' => $invoice->currency_code, 'total' => (string) $allocation->allocated_amount,
                        'payment_currency_code' => $source->currency_code, 'payment_currency_amount' => (string) $allocation->payment_currency_amount,
                        'base_currency_code' => $batch?->base_currency_code, 'settlement_base_value' => (string) $allocation->settlement_base_value];
                }
            }
        }

        return new DocumentData($type, $locale, $this->party($companySnapshot, $locale), $this->party($customerSnapshot, $locale), $document, $lines,
            presentation: app(DocumentPresentation::class)->forCompany((int) $source->company_id, $locale), applications: $applications);
    }

    /** @param array<string, mixed> $statement */
    public function statement(array $statement, ?string $locale = null): DocumentData
    {
        /** @var Customer $customer */
        $customer = $statement['customer'];
        $locale ??= $customer->preferred_locale ?: 'ar';
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported document locale.');
        }
        $company = Company::findOrFail($customer->company_id);
        unset($statement['customer']);

        return new DocumentData('customer_statement', $locale,
            $this->party($company->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en']), $locale),
            $this->party($customer->only(['name_ar', 'name_en', 'phone', 'email', 'business_name_ar', 'business_name_en', 'business_name', 'address_line_1_ar', 'address_line_1_en']), $locale),
            ['number' => $customer->code, 'issue_date' => now($company->timezone)->format('Y-m-d'), 'from_date' => $statement['from_date'] ?? null, 'to_date' => $statement['to_date'] ?? null], statement: $statement,
            presentation: app(DocumentPresentation::class)->forCompany((int) $customer->company_id, $locale));
    }
}
