<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\VoidPurchaseAction;
use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\VendorFinancialRead;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Canonical purchase correction with explicit dedicated-payment and child-return dependencies. */
final class PurchaseCorrection
{
    /** @return array<string, mixed> */
    public function preview(Purchase $purchase, User $actor, string $mode, string $reason, string $date): array
    {
        return app(TenantWriteAccess::class)->transaction((int) $purchase->company_id, function () use ($purchase, $actor, $mode, $reason, $date): array {
            $this->authorize($purchase, $actor, $mode);
            $purchase = Purchase::where('company_id', $purchase->company_id)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isPosted()) {
                throw ValidationException::withMessages(['correction' => __('corrections.refresh')]);
            }
            validator(['reason' => $reason, 'date' => $date], ['reason' => 'required|string|max:500', 'date' => 'required|date_format:Y-m-d'])->validate();
            $digest = app(PurchaseDependencySnapshot::class)->digest($purchase, $mode === 'edit');
            $children = app(PurchaseChildReturnPlan::class)->inspect($purchase, $actor);
            $plan = app(DedicatedPaymentPlan::class)->inspect((int) $purchase->company_id, (int) $purchase->id, true, $actor);
            $blockers = app(VoidPurchaseAction::class)->inspectBlockers($purchase);
            if ($plan['blockers'] === []) {
                $blockers = array_values(array_filter($blockers, fn (array $b): bool => $b['code'] !== 'active_vendor_payments'));
            }
            $blockers = array_values(array_filter($blockers, fn (array $b): bool => ! (($b['code'] === 'linked_purchase_returns' && $children['blockers'] === []) || app(PurchaseChildReturnPlan::class)->resolvedByChildren($purchase, $b, $children['returns']))));
            if ($mode === 'edit') {
                $this->validateReplacement($purchase);
            }
            $payload = ['actor' => $actor->id, 'company' => $purchase->company_id, 'id' => $purchase->id,
                'mode' => $mode, 'reason' => trim($reason), 'date' => $date, 'key' => (string) Str::uuid(),
                'expires' => now()->addMinutes(10)->timestamp, 'digest' => $digest];

            return ['presentation_permissions' => CorrectionPresentationAccess::capture($actor), 'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'payments' => $plan['payments'], 'returns' => $children['returns'], 'stock_rows' => app(CorrectionStockEffects::class)->rows($purchase), 'balance' => (string) $purchase->grand_total_currency, 'currency' => $purchase->currency_code,
                'blockers' => [...array_map(fn (array $b): string => $this->describeBlocker($purchase, $b), $blockers), ...$plan['blockers'], ...$children['blockers']],
                'number' => $purchase->purchase_number,
                'links' => collect([...$this->dependencyLinks($purchase, $actor, $blockers), ...$plan['links']])->unique('url')->values()->all(),
                'operation' => __('corrections.'.$mode),
                'rows' => $purchase->lines()->where('company_id', $purchase->company_id)->orderBy('line_number')->get()->map(fn ($line): array => [
                    'name' => $line->item_description, 'quantity' => (string) $line->quantity,
                    'amount' => (string) $line->line_total, 'currency' => $purchase->currency_code,
                ])->all()];
        });
    }

    public function execute(string $token, User $actor): Purchase
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            validator($p, ['company' => 'required|integer|min:1', 'id' => 'required|integer|min:1', 'actor' => 'required|integer|min:1', 'mode' => 'required|in:edit,delete', 'reason' => 'required|string|max:500', 'date' => 'required|date_format:Y-m-d', 'key' => 'required|uuid', 'expires' => 'required|integer', 'digest' => 'required|string|size:64'])->validate();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['correction' => __('corrections.refresh')]);
        }

        return app(TenantWriteAccess::class)->transaction((int) $p['company'], function () use ($p, $actor): Purchase {
            Company::whereKey($p['company'])->lockForUpdate()->firstOrFail();
            $purchase = Purchase::withoutGlobalScopes()->where('company_id', $p['company'])->findOrFail($p['id']);
            $this->authorize($purchase, $actor, $p['mode']);
            abort_unless((int) $p['actor'] === (int) $actor->id, 403);
            $purchase = Purchase::where('company_id', $purchase->company_id)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $economic = hash('sha256', json_encode(array_intersect_key($p, array_flip(['actor', 'company', 'id', 'mode', 'reason', 'date', 'digest'])), JSON_THROW_ON_ERROR));
            $receipt = DB::table('purchase_correction_receipts')->where('company_id', $p['company'])->where('request_key', $p['key'])->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $economic)) {
                    throw ValidationException::withMessages(['correction' => __('corrections.refresh')]);
                }

                return Purchase::where('company_id', $p['company'])->whereKey($receipt->replacement_id ?? $receipt->purchase_id)->firstOrFail();
            }
            if ($p['expires'] < now()->timestamp || ! hash_equals($p['digest'], app(PurchaseDependencySnapshot::class)->digest($purchase, $p['mode'] === 'edit', true))) {
                throw ValidationException::withMessages(['correction' => __('corrections.refresh')]);
            }
            $replacement = null;
            // The clone is prepared and written inside the same outer transaction as every inverse.
            if ($p['mode'] === 'edit') {
                $this->validateReplacement($purchase);
                $replacement = app(CreatePurchaseDraftAction::class)->execute(Company::findOrFail($purchase->company_id), $actor, $this->replacementData($purchase));
            }
            $plan = app(DedicatedPaymentPlan::class)->inspect((int) $purchase->company_id, (int) $purchase->id, true, $actor, true);
            if ($plan['blockers'] !== []) {
                throw ValidationException::withMessages(['correction' => $plan['blockers']]);
            }
            app(DedicatedPaymentPlan::class)->reverse($plan['payments'], (int) $purchase->company_id, true, $actor, $p['reason'], $p['date']);
            $children = app(PurchaseChildReturnPlan::class)->inspect($purchase, $actor);
            if ($children['blockers'] !== []) {
                throw ValidationException::withMessages(['correction' => $children['blockers']]);
            }
            foreach (array_reverse($children['returns']) as $child) {
                app(VoidPurchaseReturnAction::class)->execute(PurchaseReturn::where('company_id', $purchase->company_id)->findOrFail($child['id']), $actor, $p['reason'], $p['date']);
            }
            app(VoidPurchaseAction::class)->execute($purchase, $actor, $p['reason'], $p['date']);
            DB::table('purchase_correction_receipts')->insert([
                'company_id' => $p['company'], 'actor_id' => $actor->id, 'purchase_id' => $purchase->id,
                'replacement_id' => $replacement?->id, 'request_key' => $p['key'], 'payload_digest' => $economic,
                'mode' => $p['mode'], 'reason' => $p['reason'], 'business_date' => $p['date'], 'created_at' => now(),
            ]);

            return $replacement ?? $purchase->fresh();
        });
    }

    private function authorize(Purchase $purchase, User $actor, string $mode): void
    {
        abort_unless(in_array($mode, ['edit', 'delete'], true), 422);
        foreach (['purchasing.purchase.view', 'purchasing.purchase.void', 'purchasing.cost.view', ...($mode === 'edit' ? ['purchasing.purchase.create', 'purchasing.purchase.edit_draft'] : [])] as $permission) {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $purchase->company_id, $actor, $permission);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     * @return list<array{label: string, url: string}>
     */
    private function dependencyLinks(Purchase $purchase, User $actor, array $blockers): array
    {
        $links = [];
        if ($actor->can('purchasing.purchase.view')) {
            foreach (PurchaseReturn::where('company_id', $purchase->company_id)->where('purchase_id', $purchase->id)->where('status', '!=', 'void')->get() as $return) {
                $links[] = ['label' => __('purchasing.purchase_return').' #'.($return->return_number ?? $return->id), 'url' => route('purchase-returns.show', $return->public_id)];
            }
        }
        if (app(VendorFinancialRead::class)->allows((int) $purchase->company_id)) {
            $ids = VendorPaymentAllocation::where('company_id', $purchase->company_id)->where('purchase_id', $purchase->id)->pluck('vendor_payment_id');
            foreach (VendorPayment::where('company_id', $purchase->company_id)->whereIn('id', $ids)->where('is_reversed', false)->get() as $payment) {
                $links[] = ['label' => __('corrections.payment').' #'.($payment->payment_number ?? $payment->id), 'url' => route('vendor-payments.show', $payment->public_id)];
            }
        }

        if ($actor->can('inventory.stock.view')) {
            $ids = array_values(array_filter(array_map(fn (array $b) => $b['details']['product_id'] ?? null, $blockers)));
            foreach (DB::table('products')->where('company_id', $purchase->company_id)->whereIn('id', $ids)->orderBy('id')->get(['public_id', 'name_ar', 'name_en']) as $product) {
                $links[] = ['label' => app()->getLocale() === 'ar' ? $product->name_ar : ($product->name_en ?? $product->name_ar), 'url' => route('products.show', $product->public_id)];
            }
        }
        if ($actor->can('money.expense.view')) {
            $expenses = DB::table('expenses as e')->join('landed_cost_allocations as a', fn ($join) => $join->on('a.expense_id', '=', 'e.id')->on('a.company_id', '=', 'e.company_id'))
                ->where('a.company_id', $purchase->company_id)->where('a.purchase_id', $purchase->id)->where('a.status', 'locked')
                ->orderBy('e.id')->distinct()->get(['e.id', 'e.public_id', 'e.expense_number']);
            foreach ($expenses as $expense) {
                $links[] = ['label' => $expense->expense_number, 'url' => route('expenses.show', $expense->public_id)];
            }
        }

        return $links;
    }

    /** @param array<string, mixed> $blocker */
    private function describeBlocker(Purchase $purchase, array $blocker): string
    {
        $params = $blocker['translation_params'];
        if (isset($params['product_id'])) {
            $params['product_id'] = $purchase->lines()->where('company_id', $purchase->company_id)->where('product_id', $params['product_id'])->value('item_description') ?? $params['product_id'];
        }
        if (isset($params['lot_id'], $blocker['details']['lot_number'])) {
            $params['lot_id'] = $blocker['details']['lot_number'];
        }

        return __($blocker['translation_key'], $params);
    }

    private function validateReplacement(Purchase $purchase): void
    {
        try {
            $prepared = app(PurchaseDraftBuilder::class)->prepare(Company::findOrFail($purchase->company_id), $this->replacementData($purchase));
        } catch (InvalidUnitConversionException|InvalidQuantityException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['correction' => __('corrections.config_changed')]);
        }
        $originals = $purchase->lines()->where('company_id', $purchase->company_id)->orderBy('line_number')->get();
        foreach ($prepared->lines as $index => $line) {
            if ((bool) $line['attributes']['tax_inclusive'] !== (bool) $originals[$index]->tax_inclusive) {
                throw ValidationException::withMessages(['correction' => __('corrections.config_changed')]);
            }
            foreach (['quantity_base', 'unit_conversion_ratio', 'line_total', 'line_tax', 'line_discount', 'tax_rate_snapshot'] as $field) {
                $a = $line['attributes'][$field];
                $b = $originals[$index]->getAttribute($field);
                if (($a === null) !== ($b === null) || ($a !== null && ! BigDecimal::of((string) $a)->isEqualTo((string) $b))) {
                    throw ValidationException::withMessages(['correction' => __('corrections.config_changed')]);
                }
            }
        }
    }

    /**
     * Explicit editable intent: no identifiers, movement references or posted economic snapshots.
     *
     * @return array<string, mixed>
     */
    private function replacementData(Purchase $purchase): array
    {
        $data = $purchase->only(['vendor_id', 'warehouse_id', 'vendor_invoice_number', 'currency_code', 'exchange_rate', 'document_locale', 'notes']);
        $data['purchase_date'] = $purchase->purchase_date->format('Y-m-d');
        $data['due_date'] = $purchase->due_date?->format('Y-m-d');
        $data['lines'] = $purchase->lines()->where('company_id', $purchase->company_id)->with('lots')->orderBy('line_number')->get()->map(function ($line): array {
            $data = $line->only(['product_id', 'product_unit_id', 'item_description', 'quantity', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id']);
            $data['lots'] = $line->lots->map(fn ($lot): array => [
                'product_unit_id' => $line->product_unit_id, 'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->format('Y-m-d'), 'quantity' => (string) $lot->quantity,
            ])->all();

            return $data;
        })->all();

        return $data;
    }
}
