<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseProductSelection;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesProductSelection;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TransactionProposalResolver
{
    /** Input must already have passed TransactionProposalSchema and spoken-evidence validation.
     * @param  array<string, mixed>  $proposal
     * @param  array<string, int>  $choices
     * @return array{intent: string, payload: array<string, mixed>, questions: list<array<string, mixed>>, origins: array<string, string>, unresolved: list<string>}
     */
    public function resolve(Company $company, User $actor, array $proposal, array $choices = []): array
    {
        $intent = $proposal['intent'] ?? 'unsupported';
        if (! in_array($intent, ['sales', 'purchase'], true)) {
            throw new AssistantUnavailable('intent_unsupported');
        }

        return DB::transaction(function () use ($company, $actor, $proposal, $choices, $intent): array {
            app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
            $purchase = $intent === 'purchase';
            $questions = [];
            $unresolved = [];
            $origins = [];
            $party = $purchase
                ? $this->entity(Vendor::where('company_id', $company->id)->where('status', 'active'), $proposal['party'], ['name_ar', 'name_en', 'code'], 'party', $choices, $questions)
                : $this->entity(Customer::where('company_id', $company->id)->where('status', 'active'), $proposal['party'], ['name_ar', 'name_en', 'code'], 'party', $choices, $questions);
            $currency = $this->currency($proposal['currency']);
            if ($proposal['currency'] === null) {
                $currency = $purchase && $party instanceof Vendor
                    ? app(PurchaseDocumentRules::class)->defaultCurrency($company, $party) : $company->base_currency_code;
                $origins['currency_code'] = 'company_default';
            } else {
                $origins['currency_code'] = 'spoken';
            }
            if ($currency === null || ! $company->currencies()->where('currency_code', $currency)->where('enabled', true)->exists()) {
                $questions[] = ['path' => 'currency', 'candidates' => []];
                $currency = null;
            }
            $rate = $proposal['exchange_rate'];
            if ($rate === null && $currency === $company->base_currency_code) {
                $rate = '1';
                $origins['exchange_rate'] = 'canonical_suggestion';
            } elseif ($rate === null) {
                $questions[] = ['path' => 'exchange_rate', 'candidates' => []];
            } else {
                $origins['exchange_rate'] = 'spoken';
            }
            $warehouse = null;
            if ($proposal['warehouse'] !== null || isset($choices['warehouse'])) {
                $warehouse = $this->entity(Warehouse::where('company_id', $company->id)->where('active', true),
                    $proposal['warehouse'], ['name_ar', 'name_en', 'code'], 'warehouse', $choices, $questions);
                $origins['warehouse_id'] = 'spoken';
            } else {
                try {
                    $warehouse = $purchase ? app(PurchaseDocumentRules::class)->warehouse($company, null)
                        : Warehouse::where('company_id', $company->id)->where('active', true)->where('is_default', true)->first();
                    $origins['warehouse_id'] = 'company_default';
                } catch (Throwable) {
                    $unresolved[] = 'warehouse_id';
                }
            }
            $date = $this->date($proposal['date'], $company);
            $due = $proposal['due_date'] === null
                ? ($purchase ? app(PurchaseDocumentRules::class)->defaultDueDate($company, $date) : null)
                : $this->date($proposal['due_date'], $company);
            $origins[$purchase ? 'purchase_date' : 'issue_date'] = $proposal['date'] === null ? 'company_default' : 'spoken';
            $payload = [
                $purchase ? 'vendor_id' : 'customer_id' => $party?->id, 'warehouse_id' => $warehouse?->id,
                'currency_code' => $currency, 'exchange_rate' => $rate, $purchase ? 'purchase_date' : 'issue_date' => $date,
                'due_date' => $due, 'document_locale' => $party->preferred_locale ?? $company->default_locale,
                'notes' => $proposal['notes'], 'lines' => [],
            ];
            if ($purchase) {
                $payload['vendor_invoice_number'] = $proposal['vendor_invoice_number'];
                if ($payload['vendor_invoice_number'] === null) {
                    $unresolved[] = 'vendor_invoice_number';
                }
            }
            if ($proposal['lines'] === []) {
                $questions[] = ['path' => 'lines', 'candidates' => []];
            }
            foreach ($proposal['lines'] as $index => $line) {
                $path = 'lines.'.$index;
                $productAmbiguous = false;
                $product = $this->entity(Product::where('company_id', $company->id)->where('active', true)
                    ->when($purchase, fn ($query) => $query->where('product_type', Product::TYPE_STOCK)->where('track_stock', true)),
                    $line['product'], ['name_ar', 'name_en', 'sku'], $path.'.product', $choices, $questions, false, $productAmbiguous);
                $barcode = null;
                if ($product === null && ! $productAmbiguous && $line['product'] !== null) {
                    $barcode = ProductBarcode::where('company_id', $company->id)->where('barcode', $line['product'])->first();
                    if ($barcode !== null) {
                        $product = Product::where('company_id', $company->id)->where('active', true)
                            ->when($purchase, fn ($query) => $query->where('product_type', Product::TYPE_STOCK)->where('track_stock', true))
                            ->whereKey($barcode->product_id)->first();
                    }
                }
                if ($product === null) {
                    $this->entity(Product::where('company_id', $company->id)->where('active', true)
                        ->when($purchase, fn ($query) => $query->where('product_type', Product::TYPE_STOCK)->where('track_stock', true)),
                        $line['product'], ['name_ar', 'name_en', 'sku'], $path.'.product', $choices, $questions);
                }
                if ($product?->track_stock && $warehouse === null) {
                    $this->entity(Warehouse::where('company_id', $company->id)->where('active', true), null,
                        ['name_ar', 'name_en', 'code'], 'warehouse', [], $questions);
                }
                $unit = null;
                if ($product !== null) {
                    $units = ProductUnit::with('unit')->where('company_id', $company->id)->where('product_id', $product->id)->where('active', true)
                        ->whereHas('unit', fn ($query) => $query->where('company_id', $company->id)->where('active', true))->get();
                    $matches = $units->filter(function (ProductUnit $row) use ($line, $barcode): bool {
                        if ($line['unit'] === null) {
                            return $barcode?->unit_id !== null && (int) $row->unit_id === (int) $barcode->unit_id;
                        }

                        return in_array(mb_strtolower($line['unit']), array_map(fn ($name) => mb_strtolower((string) $name),
                            [$row->unit->code, $row->unit->name_ar, $row->unit->name_en, $row->unit->symbol_ar, $row->unit->symbol_en]), true);
                    });
                    if ($line['unit'] === null && $barcode === null && $units->count() === 1) {
                        $matches = $units;
                    }
                    $unit = $matches->count() === 1 ? $matches->first() : null;
                    $candidates = $units->take(5)->map(fn (ProductUnit $row) => ['value' => (int) $row->id, 'label' => $row->unit->name()])->all();
                    if (isset($choices[$path.'.unit']) && in_array($choices[$path.'.unit'], array_column($candidates, 'value'), true)) {
                        $unit = $units->firstWhere('id', $choices[$path.'.unit']);
                    }
                    if ($unit === null || ($barcode?->unit_id !== null && (int) $unit->unit_id !== (int) $barcode->unit_id)) {
                        $unit = null;
                        $questions[] = ['path' => $path.'.unit', 'candidates' => $candidates];
                    }
                }
                if ($line['quantity'] === null) {
                    $questions[] = ['path' => $path.'.quantity', 'candidates' => []];
                }
                $priceField = $purchase ? 'unit_cost' : 'unit_price';
                $price = $line[$priceField];
                if ($product !== null && $unit !== null && $line['quantity'] !== null) {
                    if ($purchase) {
                        app(PurchaseDocumentRules::class)->selectedUnit($product, (int) $unit->id, $line['quantity']);
                    } else {
                        app(SalesDocumentRules::class)->selectedUnit($product, (int) $unit->id, Quantity::of($line['quantity']));
                    }
                    if ($currency !== null && $rate !== null) {
                        $selected = $purchase
                            ? app(PurchaseProductSelection::class)->select((int) $product->id, (int) $unit->id, $currency, $rate)
                            : app(SalesProductSelection::class)->select((int) $product->id, (int) $unit->id, $currency, $rate, $warehouse?->id);
                        if ($price === null) {
                            $price = $selected[$priceField];
                            $origins[$path.'.'.$priceField] = 'canonical_suggestion';
                        } else {
                            $origins[$path.'.'.$priceField] = 'spoken';
                        }
                        if (! $purchase) {
                            app(SalesDocumentRules::class)->price($product, $unit, $actor, BigDecimal::of($rate), $currency, BigDecimal::of($price));
                        }
                    }
                }
                if ($price === null) {
                    $unresolved[] = $path.'.'.$priceField;
                }
                if (! $purchase && $line['discount_value'] !== null && BigDecimal::of($line['discount_value'])->isPositive()
                    && ! $actor->hasPermissionTo('sales.invoice.change_discount')) {
                    throw new AuthorizationException('Discount permission is required.');
                }
                $tax = $line['tax'] === null ? null : $this->entity(TaxRate::where('company_id', $company->id)->where('active', true),
                    $line['tax'], ['name_ar', 'name_en'], $path.'.tax', $choices, $questions);
                $resolved = ['product_id' => $product?->id, 'product_unit_id' => $unit?->id,
                    'item_description' => $product?->displayName() ?? '', 'quantity' => $line['quantity'] ?? '',
                    $priceField => $price ?? '', 'discount_type' => $line['discount_type'] ?? ($purchase ? 'none' : null),
                    'discount_value' => $line['discount_value'] ?? '0', 'tax_rate_id' => $tax?->id];
                if ($purchase) {
                    $resolved['lots'] = array_map(fn ($lot) => ['product_unit_id' => $unit?->id, 'quantity' => $lot['quantity'] ?? '',
                        'lot_number' => $lot['lot_number'] ?? '', 'expiry_date' => $lot['expires_at']], $line['lots']);
                    if ($product?->track_expiry && $resolved['lots'] === []) {
                        $unresolved[] = $path.'.lots';
                    }
                }
                foreach (['product_id' => 'product', 'product_unit_id' => 'unit', 'quantity' => 'quantity',
                    'discount_value' => 'discount_value', 'tax_rate_id' => 'tax'] as $field => $reference) {
                    if ($resolved[$field] !== null && $resolved[$field] !== '') {
                        $origins[$path.'.'.$field] = isset($choices[$path.'.'.$reference]) ? 'resolved_choice'
                            : ($line[$reference] === null ? 'canonical_suggestion' : 'spoken');
                    }
                }
                $payload['lines'][] = $resolved;
            }

            $origins[$purchase ? 'vendor_id' : 'customer_id'] = isset($choices['party']) ? 'resolved_choice' : 'spoken';
            foreach (['notes', 'vendor_invoice_number'] as $field) {
                if (isset($payload[$field])) {
                    $origins[$field] = 'spoken';
                }
            }
            $uniqueQuestions = [];
            foreach ($questions as $question) {
                $uniqueQuestions[$question['path']] = $question;
            }

            return ['intent' => $intent, 'payload' => $payload, 'questions' => array_values($uniqueQuestions), 'origins' => $origins, 'unresolved' => $unresolved];
        });
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  list<string>  $columns
     * @param  array<string, int>  $choices
     * @param  list<array<string, mixed>>  $questions
     * @return T|null
     */
    private function entity(Builder $query, ?string $reference, array $columns, string $path, array $choices, array &$questions, bool $ask = true, bool &$ambiguous = false): ?Model
    {
        $matches = $reference === null ? collect() : (clone $query)->where(function ($query) use ($columns, $reference): void {
            foreach ($columns as $column) {
                $query->orWhere($column, $reference);
            }
        })->limit(2)->get();
        $ambiguous = $matches->count() > 1;
        if ($matches->count() === 1) {
            return $matches->first();
        }
        $candidateQuery = clone $query;
        if ($reference !== null) {
            $candidateQuery->where(function ($query) use ($columns, $reference): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.addcslashes($reference, '\\%_').'%');
                }
            });
        }
        $candidates = $candidateQuery->orderBy('id')->limit(5)->get();
        if (isset($choices[$path]) && $candidates->contains('id', $choices[$path])) {
            return $candidates->firstWhere('id', $choices[$path]);
        }
        if ($ask) {
            $questions[] = ['path' => $path, 'candidates' => $candidates->map(fn ($row) => [
                'value' => (int) $row->getKey(), 'label' => (string) ($row->getAttribute('name_ar') ?? $row->getAttribute('name_en') ?? $row->getAttribute('code')),
            ])->all()];
        }

        return null;
    }

    private function currency(?string $value): ?string
    {
        return match (mb_strtolower($value ?? '')) {
            'ils', 'شيكل', 'شواكل', 'shekel', 'shekels' => 'ILS',
            'usd', 'دولار', 'dollar', 'dollars' => 'USD',
            'jod', 'دينار', 'dinar', 'dinars' => 'JOD',
            default => null,
        };
    }

    private function date(?string $value, Company $company): string
    {
        $date = match (mb_strtolower($value ?? '')) {
            '', 'today', 'اليوم' => now($company->timezone)->toDateString(),
            'yesterday', 'أمس', 'امبارح', 'مبارح' => now($company->timezone)->subDay()->toDateString(),
            default => app(SalesProposalSchema::class)->normalizeDigits($value),
        };
        app(SalesDocumentRules::class)->date($date);

        return $date;
    }
}
