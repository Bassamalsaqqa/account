<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Commands\SalesDraftIntent;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesProductSelection;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SalesProposalResolver
{
    /** @param array<string, mixed> $proposal
     * @return array{payload: array<string, mixed>|null, questions: list<string>, summary: list<array<string, string>>}
     */
    public function resolve(Company $company, User $actor, array $proposal): array
    {
        $proposal = app(SalesProposalSchema::class)->validate($proposal);

        return DB::transaction(function () use ($company, $actor, $proposal): array {
            app(AssistantAccess::class)->authorize($company, $actor);
            $questions = [];
            $summary = [];
            $customer = $this->one(Customer::where('company_id', $company->id)->where('status', 'active'), $proposal['customer'], ['name_ar', 'name_en', 'code']);
            if (! $customer instanceof Customer) {
                $questions[] = 'customer';
            }
            $currency = $this->currency($proposal['currency']);
            if ($currency === null || ! $company->currencies()->where('currency_code', $currency)->where('enabled', true)->exists()) {
                $questions[] = 'currency';
            }
            $rate = $proposal['exchange_rate'];
            if ($currency === $company->base_currency_code && $rate === null) {
                $rate = '1';
            }
            if ($rate === null) {
                $questions[] = 'exchange_rate';
            }
            $warehouse = $this->one(Warehouse::where('company_id', $company->id)->where('active', true), $proposal['warehouse'], ['name_ar', 'name_en', 'code']);
            if ($proposal['warehouse'] !== null && ! $warehouse instanceof Warehouse) {
                $questions[] = 'warehouse';
            }
            $issueDate = $this->date($proposal['issue_date'], $company->timezone);
            $dueDate = $proposal['due_date'] === null ? null : $this->date($proposal['due_date'], $company->timezone);
            $lines = [];
            if ($proposal['lines'] === []) {
                $questions[] = 'lines';
            }
            foreach ($proposal['lines'] as $index => $line) {
                $ambiguousProduct = false;
                $product = $this->one(Product::where('company_id', $company->id)->where('active', true), $line['product'], ['name_ar', 'name_en', 'sku'], $ambiguousProduct);
                if ($ambiguousProduct) {
                    $questions[] = 'product';

                    continue;
                }
                $barcode = null;
                if (is_string($line['product'])) {
                    $matches = ProductBarcode::where('company_id', $company->id)->where('barcode', app(SalesProposalSchema::class)->normalizeDigits($line['product']))->limit(2)->get();
                    if ($matches->count() === 1) {
                        $barcode = $matches->first();
                        $barProduct = Product::where('company_id', $company->id)->where('active', true)->whereKey($barcode->product_id)->first();
                        if ($product !== null && $barProduct !== null && (int) $product->id !== (int) $barProduct->id) {
                            $questions[] = 'product';

                            continue;
                        }
                        $product ??= $barProduct;
                    } elseif ($matches->count() > 1) {
                        $questions[] = 'product';

                        continue;
                    }
                }
                if (! $product instanceof Product) {
                    $questions[] = 'product';

                    continue;
                }
                $units = ProductUnit::with('unit')->where('company_id', $company->id)->where('product_id', $product->id)->where('active', true)
                    ->whereHas('unit', fn (Builder $q) => $q->where('company_id', $company->id)->where('active', true))->get();
                $matched = $units->filter(function (ProductUnit $unit) use ($line, $barcode): bool {
                    if ($line['unit'] === null) {
                        return $barcode?->unit_id !== null && (int) $unit->unit_id === (int) $barcode->unit_id;
                    }
                    $reference = mb_strtolower(trim($line['unit']));

                    return in_array($reference, array_map(fn ($v) => mb_strtolower(trim((string) $v)), [$unit->unit->code, $unit->unit->name_ar, $unit->unit->name_en, $unit->unit->symbol_ar, $unit->unit->symbol_en]), true);
                });
                if ($line['unit'] === null && $barcode?->unit_id === null && $units->count() === 1) {
                    $matched = $units;
                }
                if ($matched->count() !== 1) {
                    $questions[] = 'unit';

                    continue;
                }
                $unit = $matched->first();
                if ($barcode?->unit_id !== null && (int) $unit->unit_id !== (int) $barcode->unit_id) {
                    $questions[] = 'unit';

                    continue;
                }
                if ($line['quantity'] === null) {
                    $questions[] = 'quantity';

                    continue;
                }
                if ($product->track_stock && ! $warehouse instanceof Warehouse) {
                    $questions[] = 'warehouse';
                }
                if ($currency === null || $rate === null) {
                    continue;
                }
                $rules = app(SalesDocumentRules::class);
                $rules->selectedUnit($product, (int) $unit->id, Quantity::of($line['quantity']));
                $selected = app(SalesProductSelection::class)->select((int) $product->id, (int) $unit->id, $currency, $rate, $warehouse?->id);
                $price = $line['unit_price'] ?? $selected['unit_price'];
                $rules->price($product, $unit, $actor, BigDecimal::of($rate), $currency, BigDecimal::of($price));
                if (BigDecimal::of($line['discount_value'] ?? '0')->isPositive() && ! $actor->hasPermissionTo('sales.invoice.change_discount')) {
                    throw new AuthorizationException('Discount permission is required.');
                }
                $tax = $this->one(TaxRate::where('company_id', $company->id)->where('active', true), $line['tax'], ['name_ar', 'name_en']);
                if ($line['tax'] !== null && ! $tax instanceof TaxRate) {
                    $questions[] = 'tax';
                }
                $lines[] = [
                    'product_id' => (int) $product->id, 'product_unit_id' => (int) $unit->id,
                    'item_description' => $product->displayName(), 'quantity' => $line['quantity'], 'unit_price' => $price,
                    'discount_type' => $line['discount_type'], 'discount_value' => $line['discount_value'] ?? '0', 'tax_rate_id' => $tax?->id,
                ];
                $summary[] = [
                    'product' => $product->displayName(), 'unit' => $unit->unit->name(),
                    'quantity' => $line['quantity'], 'conversion' => (string) $unit->conversion_to_base,
                    'base_quantity' => (string) BigDecimal::of($line['quantity'])->multipliedBy($unit->conversion_to_base),
                    'unit_price' => $price, 'currency' => $currency, 'price_source' => $line['unit_price'] === null ? 'default' : 'requested',
                ];
            }
            if ($questions !== []) {
                return ['payload' => null, 'questions' => array_values(array_unique($questions)), 'summary' => []];
            }
            $locale = $customer->preferred_locale ?? $company->default_locale ?? 'ar';
            app(SalesDocumentRules::class)->header($company, $currency, BigDecimal::of($rate), $issueDate, $locale);
            $payload = app(SalesDraftIntent::class)->canonicalize([
                'customer_id' => (int) $customer->id, 'warehouse_id' => $warehouse?->id,
                'currency_code' => $currency, 'exchange_rate' => $rate,
                'issue_date' => $issueDate, 'due_date' => $dueDate, 'document_locale' => $locale,
                'lines' => $lines,
            ]);

            return ['payload' => $payload, 'questions' => [], 'summary' => $summary];
        });
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  list<string>  $columns
     * @return T|null
     */
    private function one(Builder $query, ?string $reference, array $columns, ?bool &$ambiguous = null): ?Model
    {
        if ($reference === null) {
            return null;
        }
        $matches = $query->where(function (Builder $q) use ($columns, $reference): void {
            foreach ($columns as $column) {
                $q->orWhere($column, $reference);
            }
        })->limit(2)->get();
        $ambiguous = $matches->count() > 1;

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function currency(?string $reference): ?string
    {
        return match (mb_strtolower($reference ?? '')) {
            'ils', 'شيكل', 'شواكل', 'shekel', 'shekels' => 'ILS',
            'usd', 'دولار', 'dollar', 'dollars' => 'USD',
            'jod', 'دينار', 'dinar', 'dinars' => 'JOD',
            default => $reference !== null && preg_match('/\A[A-Za-z]{3}\z/', $reference) ? strtoupper($reference) : null,
        };
    }

    private function date(?string $reference, string $timezone): string
    {
        if (! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        $date = match (mb_strtolower($reference ?? '')) {
            '', 'today', 'اليوم' => now($timezone)->toDateString(),
            'yesterday', 'أمس', 'امبارح', 'مبارح' => now($timezone)->subDay()->toDateString(),
            default => app(SalesProposalSchema::class)->normalizeDigits($reference),
        };
        app(SalesDocumentRules::class)->date($date);

        return $date;
    }
}
