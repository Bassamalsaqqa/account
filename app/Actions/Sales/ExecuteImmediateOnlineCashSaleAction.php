<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Contract\Revision3\PaymentSettlementValidator;
use App\Support\Mobile\Receipt\CompositeFinancialExecutionScope;
use App\Support\Mobile\Receipt\Exceptions\IllegalStateException;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inseparable canonical action executing an immediate online cash sale on Mobile V2.
 *
 * Atomically binds:
 * - Leg 1: Sales invoice draft creation with lines
 * - Leg 2: Invoice numbering reservation and posting (FEFO lot allocation + GL entries)
 * - Leg 3: Customer payment creation, numbering, and 100% allocation to the invoice
 *
 * Inseparable security invariant:
 * - Requires a valid, unconsumed NativeFinancialExecutionCapability as a mandatory argument.
 * - Single-use consumption is enforced immediately on admission.
 * - Requires root transaction level (DB::transactionLevel() === 1).
 * - Enforces fresh role definition and permission verification under database row locks.
 * - Enforces canonical currency and ascending entity lock ordering.
 * - Enforces strict stock (no negative inventory).
 */
final class ExecuteImmediateOnlineCashSaleAction
{
    public function __construct(
        private readonly CreateSalesInvoiceDraftAction $createDraftAction,
        private readonly PostSalesInvoiceAction $postInvoiceAction,
        private readonly PostCustomerPaymentAction $postPaymentAction,
        private readonly CurrencyMinorUnitsResolver $currencyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     sales_invoice_id: int,
     *     sales_invoice_public_id: string,
     *     customer_payment_id: int,
     *     customer_payment_public_id: string,
     *     command_receipt_id: null,
     *     terminal_canonical_result: array<string, mixed>
     * }
     */
    public function execute(
        NativeFinancialExecutionCapability $capability,
        Company $company,
        User $actor,
        MobileDevice $device,
        array $payload,
        string $idempotencySuffix
    ): array {
        // 1. Strict root transaction level assertion
        if (DB::transactionLevel() !== 1) {
            throw new IllegalStateException('Immediate online cash sale requires root database transaction level.');
        }

        // 2. Currency lock ordering and base currency validation
        $currency = (string) ($payload['currency'] ?? '');
        if ($currency === '' || $currency !== $company->base_currency_code) {
            throw new InvalidArgumentException("Immediate cash sale is restricted to company base currency [{$company->base_currency_code}].");
        }

        $this->currencyResolver->resolveCurrenciesForCompanyWrite((int) $company->id, [$currency], lockForUpdate: true);
        $minorUnits = $this->currencyResolver->resolveForCompany((int) $company->id, $currency);

        // 3. Compute actual canonical profile bytes & SHA-256 event hash from executed payload
        $serverContext = [
            'actor_id' => (string) $actor->public_id,
            'company_id' => (string) $company->public_id,
            'currency_minor_units' => $minorUnits,
        ];
        $canonicalProfile = OnlineSaleIntentProfile::construct($serverContext, $payload);
        $canonicalBytes = OnlineSaleIntentProfile::serialize($canonicalProfile);
        $computedEventHash = hash('sha256', $canonicalBytes);

        // 4. Inseparable capability consumption: validates opaque identity against private scoped registry
        MobileEventReceiptManager::verifyAndConsumeCapability(
            $capability,
            (int) $company->id,
            (int) $actor->id,
            (string) $device->device_id,
            (string) $canonicalProfile['client_event_id'],
            $computedEventHash
        );

        // 5. Fresh cooperative role definition & permission verification under lock
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $requiredPermissions = [
            'sales.invoice.create',
            'sales.invoice.post',
            'money.receipt.create',
            'money.receipt.allocate',
        ];
        foreach ($requiredPermissions as $perm) {
            if (! $actor->hasPermissionTo($perm)) {
                throw new AuthorizationException("Actor missing required permission [{$perm}] for immediate cash sale.");
            }
        }

        // 5. Canonical entity locking in acyclic order:
        // Warehouse -> Customer -> Products (ordered by ID) -> Units -> Cash Account
        $warehouseId = $payload['warehouse_id'] ?? null;
        /** @var Warehouse $warehouse */
        $warehouse = is_numeric($warehouseId)
            ? Warehouse::where('company_id', $company->id)->where('id', (int) $warehouseId)->lockForUpdate()->firstOrFail()
            : Warehouse::where('company_id', $company->id)->where('public_id', (string) $warehouseId)->lockForUpdate()->firstOrFail();

        $customerId = $payload['customer_id'] ?? null;
        /** @var Customer $customer */
        $customer = is_numeric($customerId)
            ? Customer::where('company_id', $company->id)->where('id', (int) $customerId)->lockForUpdate()->firstOrFail()
            : Customer::where('company_id', $company->id)->where('public_id', (string) $customerId)->lockForUpdate()->firstOrFail();

        if (! $customer->active) {
            throw new InvalidArgumentException("Cannot execute sale for inactive customer [{$customer->id}].");
        }

        $lines = (array) ($payload['lines'] ?? []);
        if (empty($lines)) {
            throw new InvalidArgumentException('Immediate sale requires at least one line item.');
        }

        // Collect and order product IDs ascending to prevent deadlock cycles
        $productLookups = [];
        foreach ($lines as $line) {
            $productLookups[] = (string) ($line['product_id'] ?? '');
        }

        $isNumericProducts = ctype_digit((string) $productLookups[0]);
        $lockedProducts = $isNumericProducts
            ? Product::where('company_id', $company->id)->whereIn('id', array_map('intval', $productLookups))->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : Product::where('company_id', $company->id)->whereIn('public_id', $productLookups)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');

        // Cash money account locking
        $paymentIntent = (array) ($payload['payment'] ?? []);
        if (($paymentIntent['payment_method'] ?? null) !== 'cash') {
            throw new InvalidArgumentException("Payment method must be 'cash'.");
        }
        if (($paymentIntent['settlement_mode'] ?? null) !== 'full') {
            throw new InvalidArgumentException("Settlement mode must be 'full'.");
        }

        $cashAccountId = $paymentIntent['cash_account_id'] ?? null;
        /** @var MoneyAccount $cashAccount */
        $cashAccount = is_numeric($cashAccountId)
            ? MoneyAccount::where('company_id', $company->id)->where('id', (int) $cashAccountId)->lockForUpdate()->firstOrFail()
            : MoneyAccount::where('company_id', $company->id)->where('public_id', (string) $cashAccountId)->lockForUpdate()->firstOrFail();

        if (! $cashAccount->is_active) {
            throw new InvalidArgumentException("Cash account [{$cashAccount->id}] is inactive.");
        }
        if ($cashAccount->currency_code !== $currency) {
            throw new InvalidArgumentException("Cash account currency [{$cashAccount->currency_code}] must match sale currency [{$currency}].");
        }

        // 6. Strict stock check and draft lines preparation
        $draftLines = [];
        foreach ($lines as $lineIndex => $line) {
            $prodLookup = (string) ($line['product_id'] ?? '');
            /** @var Product|null $product */
            $product = $lockedProducts->get($prodLookup);
            if ($product === null || ! $product->active) {
                throw new InvalidArgumentException("Product [{$prodLookup}] not found or inactive.");
            }

            $unitLookup = $line['product_unit_id'] ?? null;
            /** @var ProductUnit $unit */
            $unit = is_numeric($unitLookup)
                ? ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->where('id', (int) $unitLookup)->lockForUpdate()->firstOrFail()
                : ProductUnit::join('units', 'units.id', '=', 'product_units.unit_id')
                    ->where('product_units.company_id', $company->id)
                    ->where('units.company_id', $company->id)
                    ->where('product_units.product_id', $product->id)
                    ->where('units.public_id', (string) $unitLookup)
                    ->select('product_units.*')
                    ->lockForUpdate()
                    ->firstOrFail();

            if (! $unit->active) {
                throw new InvalidArgumentException("Product unit [{$unitLookup}] is inactive.");
            }

            // Compare authoritative conversion_to_base against exact admitted snapshot
            $admittedConversion = (string) ($line['conversion_to_base'] ?? '');
            if ($admittedConversion === '' || ! BigDecimal::of((string) $unit->conversion_to_base)->isEqualTo($admittedConversion)) {
                throw new InvalidArgumentException("Admitted conversion_to_base [{$admittedConversion}] does not match authoritative unit definition [{$unit->conversion_to_base}] at line {$lineIndex}.");
            }

            $qtyStr = (string) ($line['quantity'] ?? '');
            $qty = BigDecimal::of($qtyStr);
            if (! $qty->isPositive()) {
                throw new InvalidArgumentException("Line quantity must be positive at index {$lineIndex}.");
            }

            // Strict stock enforcement: no negative stock
            if ($product->track_stock) {
                $baseQty = $qty->multipliedBy($unit->conversion_to_base);
                $availableBase = BigDecimal::of((string) InventoryBalance::where('company_id', $company->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->sum('quantity_base'));

                if ($availableBase->isLessThan($baseQty)) {
                    throw new InvalidInventoryMovementException("Insufficient stock for product [{$product->id}] in warehouse [{$warehouse->id}]. Available: {$availableBase}, required: {$baseQty}.");
                }
            }

            // Compare authoritative tax rate and calculation against exact admitted snapshot
            $taxRateId = $line['tax_rate_id'] ?? null;
            $admittedTaxRate = (string) ($line['tax_rate'] ?? '0');
            $admittedInclusive = (bool) ($line['tax_inclusive'] ?? false);
            $resolvedTaxId = null;

            if ($taxRateId !== null) {
                /** @var TaxRate $taxRate */
                $taxRate = is_numeric($taxRateId)
                    ? TaxRate::where('company_id', $company->id)->where('id', (int) $taxRateId)->lockForUpdate()->firstOrFail()
                    : TaxRate::where('company_id', $company->id)->where('public_id', (string) $taxRateId)->lockForUpdate()->firstOrFail();

                if (! $taxRate->active) {
                    throw new InvalidArgumentException("Tax rate [{$taxRateId}] is inactive.");
                }

                if (! BigDecimal::of((string) $taxRate->rate)->isEqualTo($admittedTaxRate)) {
                    throw new InvalidArgumentException("Admitted tax_rate [{$admittedTaxRate}] does not match authoritative tax rate [{$taxRate->rate}] at line {$lineIndex}.");
                }

                $expectedCalculation = $admittedInclusive ? 'inclusive' : 'exclusive';
                if ($taxRate->calculation !== $expectedCalculation) {
                    throw new InvalidArgumentException("Admitted tax_inclusive does not match authoritative tax calculation [{$taxRate->calculation}] at line {$lineIndex}.");
                }

                $resolvedTaxId = (int) $taxRate->id;
            } else {
                if (! BigDecimal::of($admittedTaxRate)->isZero()) {
                    throw new InvalidArgumentException("Line has no tax rate ID but specifies non-zero tax_rate [{$admittedTaxRate}] at line {$lineIndex}.");
                }
                if ($admittedInclusive) {
                    throw new InvalidArgumentException("Line has no tax rate ID but specifies tax_inclusive true at line {$lineIndex}.");
                }
            }

            $draftLines[] = [
                'product_id' => (int) $product->id,
                'product_unit_id' => (int) $unit->id,
                'item_description' => $product->displayName(),
                'quantity' => $qtyStr,
                'unit_price' => (string) ($line['unit_price'] ?? '0'),
                'tax_rate_id' => $resolvedTaxId,
                'discount_type' => null,
                'discount_value' => '0',
            ];
        }

        // 7. Execute all financial legs under composite-scope permit
        return CompositeFinancialExecutionScope::withPermit(
            $capability,
            (int) $company->id,
            (int) $actor->id,
            function () use (
                $company,
                $actor,
                $customer,
                $warehouse,
                $currency,
                $draftLines,
                $paymentIntent,
                $minorUnits,
                $idempotencySuffix,
                $cashAccount
            ): array {
                // Leg 1: Create sales invoice draft
                $draftData = [
                    'customer_id' => (int) $customer->id,
                    'warehouse_id' => (int) $warehouse->id,
                    'currency_code' => $currency,
                    'exchange_rate' => '1.0000000000',
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->toDateString(),
                    'document_locale' => $customer->preferred_locale ?? 'ar',
                    'lines' => $draftLines,
                ];

                $draft = $this->createDraftAction->execute($company, $actor, $draftData);

                // Leg 2: Post sales invoice (sequence reservation, FEFO lot allocation, GL posting batch)
                $postedInvoice = $this->postInvoiceAction->execute($draft, $actor);

                // Verify payment amount matches posted grand total exactly
                $expectedGrandTotal = (string) $postedInvoice->grand_total_currency;
                $paymentAmountStr = (string) ($paymentIntent['amount'] ?? '');
                if (! BigDecimal::of($paymentAmountStr)->isEqualTo($expectedGrandTotal)) {
                    throw new InvalidArgumentException("Payment amount [{$paymentAmountStr}] does not match invoice grand total [{$expectedGrandTotal}].");
                }

                if (! PaymentSettlementValidator::verifyPaymentDecimalConsistency($paymentIntent, $expectedGrandTotal, $minorUnits)) {
                    throw new InvalidArgumentException('Payment intent violates decimal consistency with grand total.');
                }

                // Leg 3: Post customer payment with 100% allocation to posted sales invoice
                $paymentData = [
                    'customer_id' => (int) $customer->id,
                    'money_account_id' => (int) $cashAccount->id,
                    'payment_date' => $draftData['issue_date'],
                    'payment_method' => 'cash',
                    'document_locale' => $postedInvoice->document_locale ?? 'ar',
                    'amount' => $expectedGrandTotal,
                    'exchange_rate' => '1.0000000000',
                    'reference_number' => null,
                    'notes' => 'Mobile online cash sale settlement',
                    'idempotency_key' => 'fin1-payment:'.$idempotencySuffix,
                    'allocations' => [
                        [
                            'sales_invoice_id' => (int) $postedInvoice->id,
                            'allocated_amount' => $expectedGrandTotal,
                        ],
                    ],
                ];

                $customerPayment = $this->postPaymentAction->execute(
                    $company,
                    $actor,
                    $paymentData
                );

                // Assemble and return terminal canonical result
                $terminalResult = [
                    'status' => 'completed',
                    'sales_invoice_id' => (int) $postedInvoice->id,
                    'sales_invoice_public_id' => (string) $postedInvoice->public_id,
                    'invoice_public_id' => (string) $postedInvoice->public_id,
                    'invoice_number' => (string) $postedInvoice->invoice_number,
                    'customer_payment_id' => (int) $customerPayment->id,
                    'customer_payment_public_id' => (string) $customerPayment->public_id,
                    'payment_public_id' => (string) $customerPayment->public_id,
                    'command_receipt_id' => null,
                    'customer_id' => (int) $postedInvoice->customer_id,
                    'customer_public_id' => (string) $customer->public_id,
                    'currency' => (string) $postedInvoice->currency_code,
                    'total_amount' => (string) $postedInvoice->grand_total_currency,
                    'grand_total' => (string) $postedInvoice->grand_total_currency,
                    'paid_amount' => (string) $customerPayment->amount,
                    'amount' => (string) $customerPayment->amount,
                    'lines_count' => count($postedInvoice->lines),
                ];

                return [
                    'sales_invoice_id' => (int) $postedInvoice->id,
                    'sales_invoice_public_id' => (string) $postedInvoice->public_id,
                    'customer_payment_id' => (int) $customerPayment->id,
                    'customer_payment_public_id' => (string) $customerPayment->public_id,
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => $terminalResult,
                ];
            }
        );
    }
}
