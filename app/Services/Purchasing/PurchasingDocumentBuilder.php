<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Models\Check;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Sales\DocumentPresentation;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PurchasingDocumentBuilder
{
    public const int MAX_LINES = 500;

    public const int MAX_STATEMENT_ENTRIES = 1000;

    public const int MAX_TEXT_BYTES = 256 * 1024; // 256 KiB

    public const int MAX_IMAGE_BYTES = 4 * 1024 * 1024; // 4 MiB

    public const int MAX_FIELD_TEXT_BYTES = 20000;

    public const int MAX_FIELD_IMAGE_BYTES = 3 * 1024 * 1024;

    public const int MAX_TOTAL_JSON_BYTES = 15 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function build(Purchase|PurchaseReturn|VendorPayment $source, ?string $locale = null): array
    {
        $locale ??= $source->getAttribute('document_locale') ?: 'ar';
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported document locale.');
        }

        return DB::transaction(function () use ($source, $locale): array {
            $context = app(CompanyContext::class);
            $user = auth()->user();
            if ($user === null || ! $context->hasCompany() || (int) $context->companyId() !== (int) $source->company_id) {
                throw new AuthorizationException('An active authenticated matching company context is required.');
            }

            $companyId = (int) $source->company_id;
            $guard = app(SalesActorGuard::class);

            if ($source instanceof VendorPayment) {
                $company = $guard->lockAndAuthorize($companyId, $user, 'purchasing.document.pdf');
                if (! app(VendorFinancialRead::class)->allows($companyId)) {
                    throw new AuthorizationException('Vendor financial read permission is required.');
                }
                $withCost = true;
                $payment = VendorPayment::where('company_id', $companyId)->whereKey($source->id)->firstOrFail();

                return $this->buildVendorPayment($company, $payment, $locale, $withCost);
            }

            $company = $guard->lockAndAuthorize($companyId, $user, 'purchasing.purchase.view');
            $guard->lockAndAuthorize($companyId, $user, 'purchasing.document.pdf');
            $withCost = $user->hasPermissionTo('purchasing.cost.view');

            if ($source instanceof Purchase) {
                $purchase = Purchase::where('company_id', $companyId)->whereKey($source->id)->firstOrFail();

                return $this->buildPurchase($company, $purchase, $locale, $withCost);
            }

            $return = PurchaseReturn::where('company_id', $companyId)->whereKey($source->id)->firstOrFail();

            return $this->buildPurchaseReturn($company, $return, $locale, $withCost);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(Vendor $vendor, ?string $from = null, ?string $to = null, ?string $locale = null): array
    {
        $locale ??= $vendor->preferred_locale ?: 'ar';
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Unsupported document locale.');
        }

        if ($from !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            throw new InvalidArgumentException('Invalid from date format.');
        }
        if ($to !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new InvalidArgumentException('Invalid to date format.');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('From date cannot be after to date.');
        }

        return DB::transaction(function () use ($vendor, $from, $to, $locale): array {
            $context = app(CompanyContext::class);
            $user = auth()->user();
            if ($user === null || ! $context->hasCompany() || (int) $context->companyId() !== (int) $vendor->company_id) {
                throw new AuthorizationException('An active authenticated matching company context is required.');
            }

            $companyId = (int) $vendor->company_id;
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize($companyId, $user, 'purchasing.document.pdf');

            // Preflight total commercial history <= 1000 before hydration
            $count = 0;
            foreach (['purchases', 'purchase_returns', 'vendor_payments'] as $table) {
                $count += DB::table($table)->where('company_id', $companyId)->where('vendor_id', $vendor->id)->count();
            }
            $count += DB::table('vendor_payment_application_events')->where('company_id', $companyId)
                ->whereIn('vendor_payment_id', DB::table('vendor_payments')->select('id')->where('company_id', $companyId)->where('vendor_id', $vendor->id))->count();
            if ($count > self::MAX_STATEMENT_ENTRIES) {
                throw new InvalidArgumentException('Statement history exceeds the supported source limit.');
            }

            $vendor = Vendor::where('company_id', $companyId)->whereKey($vendor->id)->firstOrFail();

            $previousLocale = app()->getLocale();
            try {
                app()->setLocale($locale);
                $statement = app(VendorStatementQuery::class)->execute($vendor, $from, $to);
            } finally {
                app()->setLocale($previousLocale);
            }

            unset($statement['vendor']);

            $statementEntries = 0;
            foreach ($statement['currencies'] as $group) {
                $statementEntries += count($group['entries']);
            }
            if ($statementEntries > self::MAX_STATEMENT_ENTRIES) {
                throw new InvalidArgumentException('Statement exceeds the supported entry limit.');
            }

            $companySnapshot = app(PurchaseIdentitySnapshot::class)->company($company);
            $vendorSnapshot = app(PurchaseIdentitySnapshot::class)->vendor($vendor);

            $document = [
                'number' => (string) $vendor->code,
                'issue_date' => now($company->timezone)->format('Y-m-d'),
                'from_date' => $from,
                'to_date' => $to,
            ];

            $payload = [
                'type' => 'vendor_statement',
                'locale' => $locale,
                'company' => $this->party($companySnapshot, $locale),
                'vendor' => $this->party($vendorSnapshot, $locale),
                'document' => $document,
                'lines' => [],
                'applications' => [],
                'statement' => $statement,
                'presentation' => app(DocumentPresentation::class)->forCompany($companyId, $locale),
                'with_cost' => true,
            ];

            $this->assertLimits($payload);

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPurchase(Company $company, Purchase $purchase, string $locale, bool $withCost): array
    {
        $companyId = (int) $purchase->company_id;

        if ($purchase->lines()->where('company_id', $companyId)->count() > self::MAX_LINES) {
            throw new InvalidArgumentException('Document exceeds the supported line limit.');
        }

        $isDraft = $purchase->isDraft();
        if ($isDraft) {
            $companySnapshot = app(PurchaseIdentitySnapshot::class)->company($company);
            $vendorSnapshot = app(PurchaseIdentitySnapshot::class)->vendor($purchase->vendor);
        } else {
            $companySnapshot = $purchase->company_snapshot;
            $vendorSnapshot = $purchase->vendor_snapshot;
            if (! is_array($companySnapshot) || ! is_array($vendorSnapshot) || empty($companySnapshot) || empty($vendorSnapshot)) {
                throw new InvalidArgumentException('Historical document identity snapshots are required.');
            }
        }

        $document = [
            'number' => (string) $purchase->purchase_number,
            'status' => (string) $purchase->status,
            'issue_date' => $purchase->purchase_date->format('Y-m-d'),
            'due_date' => $purchase->due_date?->format('Y-m-d'),
            'posted_at' => $purchase->posted_at?->format('Y-m-d H:i'),
            'vendor_invoice_number' => $purchase->vendor_invoice_number,
            'warehouse_name' => $isDraft ? $purchase->warehouse?->displayName() : null,
            'notes' => $purchase->notes,
        ];

        if ($withCost) {
            $document += [
                'currency_code' => (string) $purchase->currency_code,
                'base_currency_code' => (string) $purchase->base_currency_code,
                'exchange_rate' => (string) $purchase->exchange_rate,
                'subtotal' => (string) $purchase->getAttribute('subtotal_currency'),
                'discount_total' => (string) $purchase->getAttribute('discount_total_currency'),
                'tax_total' => (string) $purchase->getAttribute('tax_total_currency'),
                'grand_total' => (string) $purchase->grand_total_currency,
                'amount_base' => (string) $purchase->grand_total_base,
                'total_landed_cost_base' => (string) $purchase->lines()->where('company_id', $companyId)->sum('landed_cost_allocated_base'),
            ];
        }

        // Reuse the canonical, permission-redacted private read projection.
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($locale);
            $read = app(PurchaseReadModel::class)->detail($purchase, $withCost);
        } finally {
            app()->setLocale($previousLocale);
        }
        $lines = $this->readLines($read['lines'], $withCost);

        $payload = [
            'type' => 'purchase',
            'locale' => $locale,
            'company' => $this->party($companySnapshot, $locale),
            'vendor' => $this->party($vendorSnapshot, $locale),
            'document' => $document,
            'lines' => $lines,
            'applications' => [],
            'statement' => null,
            'presentation' => app(DocumentPresentation::class)->forCompany($companyId, $locale),
            'with_cost' => $withCost,
        ];

        $this->assertLimits($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPurchaseReturn(Company $company, PurchaseReturn $return, string $locale, bool $withCost): array
    {
        $companyId = (int) $return->company_id;

        if ($return->lines()->where('company_id', $companyId)->count() > self::MAX_LINES) {
            throw new InvalidArgumentException('Document exceeds the supported line limit.');
        }

        $isDraft = $return->status === PurchaseReturn::STATUS_DRAFT;
        if ($isDraft) {
            $companySnapshot = app(PurchaseIdentitySnapshot::class)->company($company);
            $vendorSnapshot = app(PurchaseIdentitySnapshot::class)->vendor($return->vendor);
        } else {
            $companySnapshot = $return->company_snapshot;
            $vendorSnapshot = $return->vendor_snapshot;
            if (! is_array($companySnapshot) || ! is_array($vendorSnapshot) || empty($companySnapshot) || empty($vendorSnapshot)) {
                throw new InvalidArgumentException('Historical document identity snapshots are required.');
            }
        }

        $document = [
            'number' => (string) $return->return_number,
            'status' => (string) $return->status,
            'issue_date' => $return->return_date instanceof Carbon ? $return->return_date->format('Y-m-d') : (string) $return->return_date,
            'posted_at' => $return->posted_at?->format('Y-m-d H:i'),
            'original_reference' => (string) $return->purchase->purchase_number,
            'warehouse_name' => $isDraft ? $return->warehouse?->displayName() : null,
            'reason' => $return->reason,
            'notes' => $return->notes,
        ];

        if ($withCost) {
            $document += [
                'currency_code' => (string) $return->currency_code,
                'base_currency_code' => (string) $return->base_currency_code,
                'exchange_rate' => (string) $return->exchange_rate,
                'subtotal' => (string) $return->subtotal_currency,
                'discount_total' => (string) $return->discount_total_currency,
                'tax_total' => (string) $return->tax_total_currency,
                'grand_total' => (string) $return->grand_total_currency,
                'amount_base' => (string) $return->grand_total_base,
            ];
        }

        // Reuse the canonical, permission-redacted private read projection.
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($locale);
            $read = app(PurchaseReturnReadModel::class)->detail($return, $withCost);
        } finally {
            app()->setLocale($previousLocale);
        }
        $lines = $this->readLines($read['lines'], $withCost);

        $payload = [
            'type' => 'purchase_return',
            'locale' => $locale,
            'company' => $this->party($companySnapshot, $locale),
            'vendor' => $this->party($vendorSnapshot, $locale),
            'document' => $document,
            'lines' => $lines,
            'applications' => [],
            'statement' => null,
            'presentation' => app(DocumentPresentation::class)->forCompany($companyId, $locale),
            'with_cost' => $withCost,
        ];

        $this->assertLimits($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVendorPayment(Company $company, VendorPayment $payment, string $locale, bool $withCost): array
    {
        $companyId = (int) $payment->company_id;

        $allocationsCount = $payment->allocations()->where('company_id', $companyId)->count();
        if ($allocationsCount > self::MAX_LINES) {
            throw new InvalidArgumentException('Receipt exceeds the supported allocation limit.');
        }

        $companySnapshot = $payment->getAttribute('company_snapshot');
        $vendorSnapshot = $payment->getAttribute('vendor_snapshot');
        if (! is_array($companySnapshot) || ! is_array($vendorSnapshot) || empty($companySnapshot) || empty($vendorSnapshot)) {
            throw new InvalidArgumentException('Historical document identity snapshots are required.');
        }

        $moneyAccountSnapshot = $payment->getAttribute('money_account_snapshot');
        $moneyAccountName = is_array($moneyAccountSnapshot)
            ? ($moneyAccountSnapshot['name_'.$locale] ?? $moneyAccountSnapshot['name_ar'] ?? $moneyAccountSnapshot['name_en'] ?? null)
            : null;

        $checkNumber = null;
        if ($payment->payment_method === 'check' && $payment->check_id !== null) {
            $check = Check::where('company_id', $companyId)->find($payment->check_id);
            $checkNumber = $check?->check_number;
        }

        $document = [
            'number' => (string) $payment->payment_number,
            'status' => $payment->is_reversed ? 'reversed' : 'posted',
            'issue_date' => $payment->payment_date instanceof Carbon ? $payment->payment_date->format('Y-m-d') : (string) $payment->payment_date,
            'payment_method' => (string) $payment->payment_method,
            'reference' => $payment->reference_number ? (string) $payment->reference_number : null,
            'money_account' => $moneyAccountName,
            'check_number' => $checkNumber,
            'notes' => $payment->notes ? (string) $payment->notes : null,
            'currency_code' => (string) $payment->currency_code,
            'base_currency_code' => (string) $payment->base_currency_code,
            'exchange_rate' => (string) $payment->exchange_rate,
            'amount' => (string) $payment->amount,
            'amount_base' => (string) $payment->amount_base,
            'grand_total' => (string) $payment->amount,
            'grand_total_base' => (string) $payment->amount_base,
            'unallocated_amount' => (string) $payment->unallocated_amount,
            'unallocated_amount_base' => (string) $payment->unallocated_amount_base,
            'is_reversed' => (bool) $payment->is_reversed,
            'reversed_at' => $payment->reversed_at?->format('Y-m-d H:i'),
            'reversal_reason' => $payment->reversal_reason ? (string) $payment->reversal_reason : null,
        ];

        // Original allocations: application_event_id is null
        $lines = [];
        $originalAllocations = $payment->allocations()->where('company_id', $companyId)->whereNull('application_event_id')->orderBy('id')->get();
        foreach ($originalAllocations as $alloc) {
            $pur = Purchase::where('company_id', $companyId)->findOrFail($alloc->purchase_id);
            $lines[] = [
                'item_description' => (string) $pur->purchase_number,
                'purchase_number' => (string) $pur->purchase_number,
                'quantity' => '1',
                'unit_name' => null,
                'sku' => null,
                'allocated_amount' => (string) $alloc->allocated_amount,
                'total' => (string) $alloc->allocated_amount,
                'currency_code' => (string) $pur->currency_code,
                'payment_currency_code' => (string) $payment->currency_code,
                'payment_currency_amount' => (string) $alloc->payment_currency_amount,
                'base_currency_code' => (string) $payment->base_currency_code,
                'settlement_base_value' => (string) $alloc->settlement_base_value,
            ];
        }

        // Later applications appendix: application_event_id is not null
        $applications = [];
        $laterAllocations = $payment->allocations()->where('company_id', $companyId)->whereNotNull('application_event_id')->orderBy('id')->get();
        foreach ($laterAllocations as $alloc) {
            $pur = Purchase::where('company_id', $companyId)->findOrFail($alloc->purchase_id);
            $event = VendorPaymentApplicationEvent::where('company_id', $companyId)
                ->where('vendor_payment_id', $payment->id)->findOrFail($alloc->application_event_id);
            $appDate = $event->application_date->format('Y-m-d');

            $applications[] = [
                'item_description' => (string) $pur->purchase_number,
                'purchase_number' => (string) $pur->purchase_number,
                'application_date' => $appDate,
                'status' => $event->reversed_at === null ? 'posted' : 'reversed',
                'reversed_at' => $event->reversed_at?->format('Y-m-d H:i'),
                'reversal_reason' => $event->reversal_reason ? (string) $event->reversal_reason : null,
                'allocated_amount' => (string) $alloc->allocated_amount,
                'total' => (string) $alloc->allocated_amount,
                'currency_code' => (string) $pur->currency_code,
                'payment_currency_code' => (string) $payment->currency_code,
                'payment_currency_amount' => (string) $alloc->payment_currency_amount,
                'base_currency_code' => (string) $payment->base_currency_code,
                'settlement_base_value' => (string) $alloc->settlement_base_value,
            ];
        }

        $payload = [
            'type' => 'vendor_payment',
            'locale' => $locale,
            'company' => $this->party($companySnapshot, $locale),
            'vendor' => $this->party($vendorSnapshot, $locale),
            'document' => $document,
            'lines' => $lines,
            'applications' => $applications,
            'statement' => null,
            'presentation' => app(DocumentPresentation::class)->forCompany($companyId, $locale),
            'with_cost' => $withCost,
        ];

        $this->assertLimits($payload);

        return $payload;
    }

    /** @param list<array<string,mixed>> $source
     * @return list<array<string,mixed>>
     */
    private function readLines(array $source, bool $withCost): array
    {
        $lines = [];
        foreach ($source as $index => $line) {
            $item = ['line_number' => (string) ($index + 1),
                'item_description' => $line['item_description'], 'unit_name' => $line['unit_name'],
                'sku' => $line['product_sku'], 'quantity' => $line['quantity'], 'quantity_base' => $line['quantity_base']];
            foreach (['lots', 'allocations'] as $key) {
                if (isset($line[$key])) {
                    $item[$key] = array_map(static function (array $entry): array {
                        unset($entry['public_id']);

                        return $entry;
                    }, $line[$key]);
                }
            }
            if ($withCost) {
                foreach (['unit_cost' => 'unit_cost', 'line_discount' => 'discount', 'line_tax' => 'tax', 'line_total' => 'total',
                    'landed_cost_allocated_base' => 'landed_cost_allocated_base', 'inventory_unit_cost_base' => 'inventory_unit_cost_base',
                    'historical_receipt_value_base' => 'historical_receipt_value_base', 'inventory_value_removed_base' => 'inventory_value_removed_base',
                    'valuation_adjustment_base' => 'valuation_adjustment_base'] as $key => $output) {
                    if (array_key_exists($key, $line)) {
                        $item[$output] = $line[$key];
                    }
                }
            }
            $lines[] = $item;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string|null>
     */
    private function party(array $snapshot, string $locale): array
    {
        $name = $snapshot['name_'.$locale] ?? ($locale === 'en' ? ($snapshot['name_ar'] ?? null) : ($snapshot['name_en'] ?? null)) ?? $snapshot['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Document identity snapshot is missing.');
        }

        $result = ['name' => $name];

        foreach (['phone', 'email', 'tax_number', 'registration_number'] as $key) {
            $result[$key] = isset($snapshot[$key]) && is_string($snapshot[$key]) ? $snapshot[$key] : null;
        }

        foreach (['address', 'business_name'] as $key) {
            $value = $snapshot[$key.'_'.$locale] ?? ($locale === 'en' ? ($snapshot[$key.'_ar'] ?? null) : ($snapshot[$key.'_en'] ?? null)) ?? $snapshot[$key] ?? null;
            if ($key === 'address' && $value === null) {
                $value = $snapshot['address_line_1_'.$locale] ?? ($locale === 'en' ? ($snapshot['address_line_1_ar'] ?? null) : ($snapshot['address_line_1_en'] ?? null)) ?? null;
            }
            $result[$key] = is_string($value) ? $value : null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertLimits(array $data): void
    {
        if (! in_array($data['locale'] ?? '', ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Document exceeds the supported locale or line limits.');
        }

        if ((count($data['lines'] ?? []) + count($data['applications'] ?? [])) > self::MAX_LINES) {
            throw new InvalidArgumentException('Document exceeds the supported line limit.');
        }

        $statementEntries = 0;
        foreach ($data['statement']['currencies'] ?? [] as $group) {
            $statementEntries += count($group['entries'] ?? []);
        }
        if ($statementEntries > self::MAX_STATEMENT_ENTRIES) {
            throw new InvalidArgumentException('Statement exceeds the supported entry limit.');
        }

        $textBytes = 0;
        $imageBytes = 0;
        $this->assertValues($data, $textBytes, $imageBytes);

        if ($textBytes > self::MAX_TEXT_BYTES) {
            throw new InvalidArgumentException('Document text exceeds the preparation budget.');
        }

        if ($imageBytes > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Document images exceed the preparation budget.');
        }

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_TOTAL_JSON_BYTES) {
            throw new InvalidArgumentException('Document exceeds the preparation byte limit.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function assertValues(array $values, int &$textBytes, int &$imageBytes): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->assertValues($value, $textBytes, $imageBytes);
            } elseif (is_float($value) || is_object($value)) {
                throw new InvalidArgumentException('Document values must be explicit exact primitives.');
            } elseif (is_string($value)) {
                $isImage = in_array($key, ['image', 'logo'], true);
                $len = strlen($value);
                if ($isImage) {
                    $imageBytes += $len;
                    if ($len > self::MAX_FIELD_IMAGE_BYTES) {
                        throw new InvalidArgumentException('Document text or image exceeds its preparation limit.');
                    }
                } else {
                    $textBytes += $len;
                    if ($len > self::MAX_FIELD_TEXT_BYTES) {
                        throw new InvalidArgumentException('Document text or image exceeds its preparation limit.');
                    }
                }
            }
        }
    }
}
