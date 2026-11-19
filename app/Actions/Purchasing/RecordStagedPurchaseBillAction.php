<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Mobile\NativeAdmissionGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Canonical action for executing atomic staged purchase bill creation and optional cash settlement.
 */
final class RecordStagedPurchaseBillAction
{
    /**
     * @param  list<array<string, mixed>>  $linesInput
     * @return array{is_replay: bool, snapshot: array<string, mixed>}
     */
    public function execute(
        Company $company,
        User $actor,
        string $supplierPublicId,
        ?string $billNumber,
        array $linesInput,
        string $idempotencyKey,
        ?string $paidNow = null,
        ?string $explicitAccountId = null,
        ?MobileAccessToken $mobileToken = null,
        ?MobileDevice $mobileDevice = null
    ): array {
        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $actor,
            $supplierPublicId,
            $billNumber,
            $linesInput,
            $idempotencyKey,
            $paidNow,
            $explicitAccountId,
            $mobileToken,
            $mobileDevice
        ): array {
            // 1. Enforce strict lock order and native admission: Company -> Membership -> User -> Device -> Token
            $admission = app(NativeAdmissionGuard::class)->lockAndAuthorize(
                (int) $company->id,
                $actor,
                $mobileToken,
                $mobileDevice,
                ['purchasing.purchase.create', 'purchasing.purchase.post']
            );

            $lockedCompany = $admission['company'];
            $lockedActor = $admission['user'];
            $lockedDevice = $admission['device'];

            app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

            // 2. Lock and validate Vendor
            /** @var Vendor|null $vendor */
            $vendor = Vendor::where('company_id', $lockedCompany->id)
                ->where('public_id', $supplierPublicId)
                ->lockForUpdate()
                ->first();

            if ($vendor === null) {
                throw new InvalidArgumentException("Valid active supplier [{$supplierPublicId}] is required.");
            }

            if (empty($linesInput)) {
                throw new InvalidArgumentException('Purchase bill requires at least one line item.');
            }

            $resolvedBillNumber = is_string($billNumber) && trim($billNumber) !== ''
                ? trim($billNumber)
                : 'BILL-'.substr(sha1($idempotencyKey), 0, 10);

            // 3. Request Hash & Idempotency Replay
            $normalizedPayload = [
                'actor_id' => (int) $lockedActor->id,
                'device_id' => (string) $lockedDevice->device_id,
                'supplier_id' => (string) $vendor->public_id,
                'bill_number' => $billNumber,
                'paid_now' => $paidNow,
                'lines' => $linesInput,
            ];
            $requestHash = hash('sha256', json_encode($normalizedPayload, JSON_THROW_ON_ERROR));

            /** @var Purchase|null $existing */
            $existing = Purchase::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Cross-actor replay => 403 Forbidden
                if ((int) $existing->created_by !== (int) $lockedActor->id) {
                    throw new AuthorizationException('Idempotency key replay across different actors is forbidden.');
                }

                if (hash_equals((string) $existing->request_hash, $requestHash)) {
                    return [
                        'is_replay' => true,
                        'snapshot' => $existing->response_snapshot ?? [
                            'id' => (string) $existing->public_id,
                            'bill_number' => (string) ($existing->vendor_invoice_number ?? $existing->public_id),
                            'status' => (string) $existing->status,
                            'total_ils' => (string) BigDecimal::of((string) $existing->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
                            'posted_at' => $existing->posted_at ? $existing->posted_at->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                        ],
                    ];
                }

                throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was previously committed with different purchase bill parameters.");
            }

            // 4. Ensure Dedicated In-Transit Warehouse
            $transitWarehouse = app(EnsureCompanyInTransitWarehouseAction::class)->execute($lockedCompany, $lockedActor);

            // 5. Parse and Validate Lines
            $draftLines = [];
            foreach ($linesInput as $l) {
                $prodId = $l['product_id'] ?? null;
                if (! is_string($prodId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $prodId) !== 1) {
                    throw new InvalidArgumentException('Every purchase line requires a valid same-company product and base unit.');
                }

                /** @var Product|null $prod */
                $prod = Product::where('company_id', $lockedCompany->id)
                    ->where('public_id', $prodId)
                    ->lockForUpdate()
                    ->first();

                if ($prod === null) {
                    throw new InvalidArgumentException('Purchase product does not belong to the active company.');
                }

                /** @var ProductUnit|null $prodUnit */
                $prodUnit = ProductUnit::where('company_id', $lockedCompany->id)
                    ->where('product_id', $prod->id)
                    ->where('active', true)
                    ->where('is_base', true)
                    ->first();

                if ($prodUnit === null) {
                    throw new InvalidArgumentException('Product requires an active canonical base unit.');
                }

                $unitCost = $l['unit_cost_ils'] ?? $l['unit_cost'] ?? null;
                $qty = $l['quantity'] ?? null;
                if (! is_string($unitCost) || ! is_string($qty)
                    || preg_match('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $unitCost) !== 1
                    || preg_match('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $qty) !== 1
                    || BigDecimal::of($qty)->isLessThanOrEqualTo(0)) {
                    throw new InvalidArgumentException('Explicit exact cost and positive quantity decimal strings are required for every purchase line.');
                }

                $lineDraft = [
                    'product_id' => $prod->id,
                    'product_unit_id' => $prodUnit->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'discount_type' => 'fixed',
                    'discount_value' => '0',
                ];

                if ($prod->track_expiry && ! empty($l['lots']) && is_array($l['lots'])) {
                    $lotsDraft = [];
                    foreach ($l['lots'] as $lotItem) {
                        $lotsDraft[] = [
                            'product_unit_id' => $prodUnit->id,
                            'lot_number' => $lotItem['lot_number'] ?? null,
                            'expiry_date' => $lotItem['expiry_date'] ?? null,
                            'quantity' => (string) ($lotItem['quantity'] ?? $l['quantity'] ?? '1'),
                        ];
                    }
                    $lineDraft['lots'] = $lotsDraft;
                }

                $draftLines[] = $lineDraft;
            }

            // 6. Create Draft and Post Purchase Bill
            $draftData = [
                'vendor_id' => $vendor->id,
                'warehouse_id' => $transitWarehouse->id,
                'currency_code' => $lockedCompany->base_currency_code,
                'exchange_rate' => '1.0000000000',
                'purchase_date' => now()->format('Y-m-d'),
                'vendor_invoice_number' => $resolvedBillNumber,
                'lines' => $draftLines,
            ];

            $createDraftAction = app(CreatePurchaseDraftAction::class);
            $draft = $createDraftAction->execute($lockedCompany, $lockedActor, $draftData);
            $draft->is_staged = true;
            $draft->idempotency_key = $idempotencyKey;
            $draft->request_hash = $requestHash;
            $draft->save();

            $postPurchaseAction = app(PostPurchaseAction::class);
            $postedPurchase = $postPurchaseAction->execute($draft, $lockedActor);

            // 7. Atomic Settlement (No Lost Payment)
            if ($paidNow !== null && BigDecimal::of((string) $paidNow)->isPositive()) {
                /** @var MoneyAccount|null $lockedCashAccount */
                $lockedCashAccount = null;

                if ($explicitAccountId !== null) {
                    $lockedCashAccount = MoneyAccount::where('company_id', $lockedCompany->id)
                        ->where('public_id', $explicitAccountId)
                        ->where('account_type', MoneyAccount::TYPE_CASH)
                        ->where('currency_code', $postedPurchase->currency_code)
                        ->where('is_active', true)
                        ->with('ledgerAccount')
                        ->lockForUpdate()
                        ->first();
                } else {
                    $setting = CompanyMobileSetting::where('company_id', $lockedCompany->id)->lockForUpdate()->first();
                    if ($setting?->approved_cash_account_id) {
                        $lockedCashAccount = MoneyAccount::where('id', $setting->approved_cash_account_id)
                            ->where('company_id', $lockedCompany->id)
                            ->where('account_type', MoneyAccount::TYPE_CASH)
                            ->where('currency_code', $postedPurchase->currency_code)
                            ->where('is_active', true)
                            ->with('ledgerAccount')
                            ->lockForUpdate()
                            ->first();
                    }
                    if ($lockedCashAccount === null) {
                        $lockedCashAccount = MoneyAccount::where('company_id', $lockedCompany->id)
                            ->where('account_type', MoneyAccount::TYPE_CASH)
                            ->where('currency_code', $postedPurchase->currency_code)
                            ->where('is_active', true)
                            ->with('ledgerAccount')
                            ->lockForUpdate()
                            ->first();
                    }
                }

                $lockedLedger = $lockedCashAccount?->ledgerAccount;
                if ($lockedCashAccount === null || $lockedLedger === null || (int) $lockedLedger->company_id !== (int) $lockedCompany->id || ! $lockedLedger->active) {
                    throw new InvalidArgumentException('An approved company cash account with active ledger provenance is required for purchase payment settlement.');
                }

                $postPaymentAction = app(PostVendorPaymentAction::class);
                $paymentData = [
                    'vendor_id' => $vendor->id,
                    'money_account_id' => $lockedCashAccount->id,
                    'currency_code' => $postedPurchase->currency_code,
                    'exchange_rate' => '1.0000000000',
                    'amount' => (string) BigDecimal::of((string) $paidNow),
                    'payment_date' => now()->format('Y-m-d'),
                    'payment_method' => 'cash',
                    'notes' => 'سداد نقدي عند شراء فاتورة #'.$postedPurchase->vendor_invoice_number,
                    'idempotency_key' => $idempotencyKey.'-pay',
                    'allocations' => [
                        [
                            'purchase_id' => $postedPurchase->id,
                            'allocated_amount' => (string) BigDecimal::of((string) $paidNow),
                        ],
                    ],
                ];
                $postPaymentAction->execute($lockedCompany, $lockedActor, $paymentData);
            }

            // 8. Freeze Final Response Snapshot
            $snapshot = [
                'id' => (string) $postedPurchase->public_id,
                'bill_number' => (string) ($postedPurchase->vendor_invoice_number ?? $postedPurchase->public_id),
                'status' => (string) $postedPurchase->status,
                'total_ils' => (string) BigDecimal::of((string) $postedPurchase->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
                'posted_at' => $postedPurchase->posted_at ? $postedPurchase->posted_at->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
            ];

            $postedPurchase->freezeResponseSnapshot($snapshot);

            return [
                'is_replay' => false,
                'snapshot' => $snapshot,
            ];
        });
    }
}
