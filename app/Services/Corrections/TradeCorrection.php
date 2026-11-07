<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Application\Platform\TenantWriteAccess;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Money\MoneyActorGuard;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared receipt/preview boundary; economic writes remain in the canonical family actions. */
final class TradeCorrection
{
    public const MODELS = ['sales_invoice' => SalesInvoice::class, 'sales_return' => SalesReturn::class, 'purchase_return' => PurchaseReturn::class];

    public function authorize(SalesInvoice|SalesReturn|PurchaseReturn $source, User $actor, string $mode, bool $lock = true): void
    {
        abort_unless(in_array($mode, ['edit', 'delete'], true), 422);
        $family = $this->family($source);
        $permissions = match ($family) {
            'sales_invoice' => ['sales.invoice.view', 'sales.invoice.void', ...($mode === 'edit' ? ['sales.invoice.create', 'sales.invoice.edit_draft'] : [])],
            'sales_return' => ['sales.return.view', 'sales.return.void', ...($mode === 'edit' ? ['sales.return.create', 'sales.return.manage', 'sales.invoice.view'] : [])],
            'purchase_return' => ['purchasing.purchase.view', 'purchasing.return.void', 'purchasing.cost.view', ...($mode === 'edit' ? ['purchasing.return.manage'] : [])],
            default => abort(422),
        };
        foreach ($permissions as $permission) {
            if ($lock) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $source->company_id, $actor, $permission);
            } else {
                abort_unless((int) auth()->id() === (int) $actor->id, 403);
                app(MoneyActorGuard::class)->authorize((int) $source->company_id, $permission);
            }
        }
    }

    public function family(SalesInvoice|SalesReturn|PurchaseReturn $source): string
    {
        $family = array_search($source::class, self::MODELS, true);
        abort_unless(is_string($family), 422);

        return $family;
    }

    /** @return array<string, mixed> */
    public function preview(SalesInvoice|SalesReturn|PurchaseReturn $source, User $actor, string $mode, string $reason, string $date): array
    {
        return app(TenantWriteAccess::class)->transaction((int) $source->company_id, function () use ($source, $actor, $mode, $reason, $date): array {
            $this->authorize($source, $actor, $mode);
            $source = $source->newQuery()->where('company_id', $source->company_id)->findOrFail($source->id);
            validator(['reason' => $reason, 'date' => $date], ['reason' => 'required|string|max:500', 'date' => 'required|date_format:Y-m-d'])->validate();
            if ($source->status !== 'posted') {
                $this->stale();
            }
            $family = $this->family($source);
            // Sales' sealed void boundary currently uses company-local today for every leg.
            if ($family !== 'purchase_return' && $date !== now(Company::findOrFail($source->company_id)->timezone)->toDateString()) {
                throw ValidationException::withMessages(['date' => __('corrections.today_only')]);
            }
            $plan = $this->plan($source, $actor);
            $p = ['family' => $family, 'company' => (int) $source->company_id, 'id' => (int) $source->id, 'actor' => (int) $actor->id,
                'mode' => $mode, 'reason' => trim($reason), 'date' => $date, 'key' => (string) Str::uuid(), 'expires' => now()->addMinutes(10)->timestamp,
                'digest' => $this->digest($source, $mode === 'edit', $plan)];
            $rows = $source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get()->map(fn ($line) => [
                'name' => $line->item_description, 'quantity' => (string) $line->quantity, 'amount' => (string) $line->line_total, 'currency' => $source->currency_code,
                'stock' => (string) BigDecimal::of((string) $line->quantity_base)->multipliedBy($family === 'sales_return' ? '-1' : '1'),
            ])->all();

            return ['presentation_permissions' => CorrectionPresentationAccess::capture($actor), 'token' => Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)), 'operation' => __('corrections.'.$mode),
                'stock_rows' => app(CorrectionStockEffects::class)->rows($source),
                'number' => $source->invoice_number ?? $source->return_number, 'rows' => $rows, 'payments' => $plan['payments'], 'returns' => $plan['returns'], 'blockers' => $plan['blockers'], 'links' => $plan['links'],
                'balance' => (string) $source->grand_total_currency, 'currency' => $source->currency_code];
        });
    }

    public function execute(string $token, User $actor): SalesInvoice|SalesReturn|PurchaseReturn
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            validator($p, ['family' => 'required|in:sales_invoice,sales_return,purchase_return', 'company' => 'required|integer|min:1', 'id' => 'required|integer|min:1', 'actor' => 'required|integer|min:1', 'mode' => 'required|in:edit,delete', 'reason' => 'required|string|max:500', 'date' => 'required|date_format:Y-m-d', 'key' => 'required|uuid', 'expires' => 'required|integer', 'digest' => 'required|string|size:64'])->validate();
        } catch (\Throwable) {
            $this->stale();
        }

        return app(TenantWriteAccess::class)->transaction((int) $p['company'], function () use ($p, $actor): SalesInvoice|SalesReturn|PurchaseReturn {
            // Serialize before even reading the source: a pre-lock snapshot can race a completed correction.
            Company::whereKey($p['company'])->lockForUpdate()->firstOrFail();
            $class = self::MODELS[$p['family']];
            $source = $class::where('company_id', $p['company'])->whereKey($p['id'])->firstOrFail();
            $this->authorize($source, $actor, $p['mode']);
            $source = $class::where('company_id', $p['company'])->whereKey($p['id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $p['actor'] === (int) $actor->id, 403);
            $economic = hash('sha256', json_encode(array_intersect_key($p, array_flip(['family', 'company', 'id', 'actor', 'mode', 'reason', 'date', 'digest'])), JSON_THROW_ON_ERROR));
            $table = $p['family'].'_correction_receipts';
            $receipt = DB::table($table)->where('company_id', $p['company'])->where('request_key', $p['key'])->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $economic)) {
                    $this->stale();
                }

                return $class::where('company_id', $p['company'])->findOrFail($receipt->replacement_id ?? $receipt->source_id);
            }
            $plan = $this->plan($source, $actor, true);
            if ($p['expires'] < now()->timestamp || $source->status !== 'posted' || ! hash_equals($p['digest'], $this->digest($source, $p['mode'] === 'edit', $plan, true))) {
                $this->stale();
            }
            if ($plan['blockers'] !== []) {
                throw ValidationException::withMessages(['correction' => $plan['blockers']]);
            }
            if ($p['family'] !== 'purchase_return' && $p['date'] !== now(Company::findOrFail($source->company_id)->timezone)->toDateString()) {
                $this->stale();
            }
            app(DedicatedPaymentPlan::class)->reverse($plan['payments'], (int) $source->company_id, false, $actor, $p['reason'], $p['date']);
            foreach (array_reverse($plan['returns']) as $child) {
                app(VoidSalesReturnAction::class)->execute(SalesReturn::where('company_id', $source->company_id)->findOrFail($child['id']), $actor, $p['reason']);
            }
            // Returns must release the canonical quantity cap before a replacement is built.
            $action = match ($p['family']) {
                'sales_invoice' => VoidSalesInvoiceAction::class,
                'sales_return' => VoidSalesReturnAction::class,
                'purchase_return' => VoidPurchaseReturnAction::class,
                default => abort(422),
            };
            if ($p['family'] === 'purchase_return') {
                app($action)->execute($source, $actor, $p['reason'], $p['date']);
            } else {
                app($action)->execute($source, $actor, $p['reason']);
            }
            $replacement = $p['mode'] === 'edit' ? $this->clone($source, $actor) : null;
            DB::table($table)->insert(['company_id' => $p['company'], 'actor_id' => $actor->id, 'source_id' => $source->id,
                'replacement_id' => $replacement?->id, 'request_key' => $p['key'], 'payload_digest' => $economic, 'mode' => $p['mode'],
                'reason' => $p['reason'], 'business_date' => $p['date'], 'created_at' => now()]);

            return $replacement ?? $source->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function plan(SalesInvoice|SalesReturn|PurchaseReturn $source, User $actor, bool $lock = false): array
    {
        $plan = ['payments' => [], 'returns' => [], 'blockers' => [], 'links' => [], 'snapshot' => []];
        if ($source instanceof SalesInvoice) {
            $payment = app(DedicatedPaymentPlan::class)->inspect((int) $source->company_id, (int) $source->id, false, $actor, $lock);
            $plan = array_replace($plan, $payment);
            $children = SalesReturn::where('company_id', $source->company_id)->where('sales_invoice_id', $source->id)->where('status', '!=', 'void')->orderBy('id')->limit(2001)->get(['id', 'public_id', 'return_number', 'status', 'grand_total_currency', 'currency_code']);
            if ($children->count() > 2000) {
                throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
            }
            foreach ($children as $child) {
                if (! $actor->can('sales.return.view') || ! $actor->can('sales.return.void')) {
                    $plan['blockers'][] = __('corrections.dependency_restricted');

                    continue;
                }
                foreach (['sales.return.view', 'sales.return.void'] as $permission) {
                    app(SalesActorGuard::class)->lockAndAuthorize((int) $source->company_id, $actor, $permission);
                }
                $link = ['label' => $child->return_number, 'url' => route('returns.show', $child->public_id)];
                $plan['links'][] = $link;
                if ($child->status === 'draft') {
                    $plan['blockers'][] = __('corrections.draft_return', ['number' => $child->return_number]);
                } else {
                    $plan['returns'][] = ['id' => (int) $child->id, 'number' => $child->return_number, 'amount' => $child->grand_total_currency, 'currency' => $child->currency_code, 'stock_rows' => app(CorrectionStockEffects::class)->rows($child)];
                }
            }
            $stock = app(SalesReturnStockRead::class)->inspect((int) $source->company_id, array_column($plan['returns'], 'id'), $actor);
            $plan['blockers'] = [...$plan['blockers'], ...$stock['blockers']];
            $plan['links'] = [...$plan['links'], ...$stock['links']];
        } elseif ($source instanceof SalesReturn) {
            $stock = app(SalesReturnStockRead::class)->inspect((int) $source->company_id, [(int) $source->id], $actor);
            $plan['blockers'] = $stock['blockers'];
            $plan['links'] = $stock['links'];
        } elseif ($source instanceof PurchaseReturn) {
            $plan['blockers'] = array_map(fn ($b) => __($b['translation_key'], $b['translation_params']), app(VoidPurchaseReturnAction::class)->inspectBlockers($source));
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    private function digest(SalesInvoice|SalesReturn|PurchaseReturn $source, bool $replacement, array $plan, bool $lock = false): string
    {
        if ($source instanceof PurchaseReturn) {
            $graph = app(PurchaseDependencySnapshot::class)->capture(Purchase::where('company_id', $source->company_id)->findOrFail($source->purchase_id), $replacement, $lock);
            $graph['source_return'] = $source->only(['id', 'status', 'return_date', 'reason', 'notes', 'document_locale', 'grand_total_currency', 'grand_total_base']);
        } else {
            $invoice = $source instanceof SalesInvoice ? $source : SalesInvoice::where('company_id', $source->company_id)->findOrFail($source->sales_invoice_id);
            $graph = app(SalesDependencySnapshot::class)->capture($invoice, $replacement, $lock);
        }

        return hash('sha256', json_encode([$graph, $plan['snapshot'], array_column($plan['payments'], 'id'), array_column($plan['returns'], 'id')], JSON_THROW_ON_ERROR));
    }

    private function clone(SalesInvoice|SalesReturn|PurchaseReturn $source, User $actor): SalesInvoice|SalesReturn|PurchaseReturn
    {
        $company = Company::findOrFail($source->company_id);
        if ($source instanceof SalesInvoice) {
            $data = $source->only(['customer_id', 'warehouse_id', 'currency_code', 'exchange_rate', 'notes', 'terms', 'document_locale']);
            $data['issue_date'] = $source->issue_date->format('Y-m-d');
            $data['due_date'] = $source->due_date?->format('Y-m-d');
            $data['lines'] = $source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get()->map(fn ($l) => $l->only(['product_id', 'product_unit_id', 'item_description', 'quantity', 'unit_price', 'discount_type', 'discount_value', 'tax_rate_id']))->all();
            $replacement = app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, $data);
        } elseif ($source instanceof SalesReturn) {
            $data = $source->only(['sales_invoice_id', 'reason', 'notes']);
            $data['issue_date'] = $source->issue_date->format('Y-m-d');
            $data['lines'] = $source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get()->map(fn ($l) => $l->only(['sales_invoice_line_id', 'quantity']))->all();
            $replacement = app(CreateSalesReturnDraftAction::class)->execute($company, $actor, $data);
        } else {
            $data = ['purchase_id' => $source->purchase_id, 'reason' => $source->reason, 'notes' => $source->notes];
            $data['return_date'] = $source->return_date->format('Y-m-d');
            $data['lines'] = $source->lines()->where('company_id', $source->company_id)->with('allocations')->orderBy('line_number')->get()->map(function ($l) {
                return ['purchase_line_id' => $l->purchase_line_id, 'quantity' => (string) $l->quantity, 'allocations' => $l->allocations->map(fn ($a) => $a->only(['original_stock_movement_id', 'purchase_line_lot_id', 'inventory_lot_id', 'quantity']))->all()];
            })->all();
            $replacement = app(CreatePurchaseReturnDraftAction::class)->execute($company, $actor, $data);
        }
        if ($replacement->document_locale !== $source->document_locale) {
            // A new return's canonical setter can validate/preserve its original language.
            $replacement->document_locale = $source->document_locale;
            $replacement->save();
        }
        $old = $source->lines()->where('company_id', $source->company_id)->orderBy('line_number')->get();
        $new = $replacement->lines()->orderBy('line_number')->get();
        if ($old->count() !== $new->count()) {
            $this->configChanged();
        }
        foreach ($new as $i => $line) {
            if ((bool) $line->tax_inclusive !== (bool) $old[$i]->tax_inclusive) {
                $this->configChanged();
            }
            foreach (['quantity', 'quantity_base', 'unit_conversion_ratio', 'line_tax', 'line_discount', 'line_total', 'line_total_base', 'tax_rate_snapshot'] as $field) {
                $a = $line->getAttribute($field);
                $b = $old[$i]->getAttribute($field);
                if (($a === null) !== ($b === null) || ($a !== null && ! BigDecimal::of((string) $a)->isEqualTo((string) $b))) {
                    $this->configChanged();
                }
            }
        }

        return $replacement;
    }

    private function configChanged(): never
    {
        throw ValidationException::withMessages(['correction' => __('corrections.config_changed')]);
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['correction' => __('corrections.refresh')]);
    }
}
