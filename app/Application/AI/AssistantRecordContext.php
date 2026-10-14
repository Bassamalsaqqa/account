<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Routing\Route;

/** A server-resolved screen identity; financial records and names stay out of model context. */
final class AssistantRecordContext
{
    private function __construct(
        public readonly string $type,
        public readonly string $publicId,
        public readonly string $name,
        public readonly ?string $filter,
        private readonly ?string $reference,
    ) {}

    /** @return array{type:string, public_id:string}|null */
    public static function fromRoute(?Route $route = null): ?array
    {
        $route ??= request()->route();
        $type = match ($route?->getName()) {
            'customers.show', 'customers.edit', 'customers.statement' => 'customer',
            'vendors.show', 'vendors.edit' => 'vendor',
            'products.show', 'products.edit' => 'product',
            'invoices.show', 'invoices.edit' => 'invoice',
            default => null,
        };
        $id = $route?->parameter('publicId');

        return $type !== null && is_string($id) ? ['type' => $type, 'public_id' => $id] : null;
    }

    public static function resolve(Company $company, User $actor, string $type, string $publicId): self
    {
        $guard = app(ReportingGuard::class);
        $permissions = $guard->readPermissions($company, $actor);
        [$model, $permission, $filter, $identifier] = match ($type) {
            'customer' => [Customer::class, 'customers.view', 'customer_name', 'code'],
            'vendor' => [Vendor::class, 'vendors.view', 'vendor_name', 'code'],
            'product' => [Product::class, 'inventory.stock.view', 'product_name', 'sku'],
            'invoice' => [SalesInvoice::class, 'sales.invoice.view', null, null],
            default => throw new AssistantUnavailable('proposal_stale'),
        };
        if (! in_array($permission, $permissions, true)) {
            throw new AssistantUnavailable('unauthorized');
        }
        $query = $model::withoutGlobalScopes()->where('company_id', $company->id)->where('public_id', $publicId);
        if ($type !== 'invoice') {
            $query->whereNull('deleted_at');
        }
        $record = $query->first();
        if ($record === null) {
            throw new AssistantUnavailable('proposal_stale');
        }
        $name = $type === 'invoice' ? (string) ($record->invoice_number ?: $record->public_id)
            : (string) (app()->getLocale() === 'ar' ? ($record->name_ar ?: $record->name_en) : ($record->name_en ?: $record->name_ar));
        $reference = $identifier === null ? null : (string) ($record->getAttribute($identifier) ?: $name);
        if ($type === 'invoice' && in_array('customers.view', $permissions, true)) {
            $customer = Customer::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereKey($record->customer_id)->whereNull('deleted_at')->first();
            if ($customer !== null) {
                $filter = 'customer_name';
                $reference = (string) ($customer->code ?: ($customer->name_ar ?: $customer->name_en));
            }
        }

        return new self($type, $publicId, $name, $filter, $reference);
    }

    public function revalidate(Company $company, User $actor): self
    {
        $fresh = self::resolve($company, $actor, $this->type, $this->publicId);
        if ($fresh->filter !== $this->filter || $fresh->reference !== $this->reference) {
            throw new AssistantUnavailable('proposal_stale');
        }

        return $fresh;
    }

    /** @return array{0:string,1:string,2:?string,3:?string} */
    public function identity(): array
    {
        return [$this->type, $this->publicId, $this->filter, $this->reference];
    }

    public function permitsReference(string $filter, string $value): bool
    {
        return $this->filter === $filter && $this->reference !== null && $value === $this->reference;
    }

    /** Only exact, unqualified screen questions are shortcuts. Never discard a currency, date or entity.
     * @return list<array<string,mixed>>|null */
    public function shortcut(string $text): ?array
    {
        $text = mb_strtolower(trim(preg_replace('/[.?!؟]+\z/u', '', trim($text)) ?? $text));
        $key = match (true) {
            $this->filter === 'customer_name' && in_array($text, ['قديش عليه دين', 'كم عليه', 'شو رصيده', 'what does this customer owe', 'how much does he owe'], true) => 'customers.balances',
            $this->filter === 'customer_name' && in_array($text, ['شو آخر فاتورة إله', 'شو اخر فاتورة اله', 'what is his last invoice', 'what is this customer\'s last invoice'], true) => 'sales.last-invoice',
            $this->filter === 'vendor_name' && in_array($text, ['قديش إلنا عليه', 'قديش علينا إله', 'شو رصيد المورد', 'what do we owe this vendor', 'what is this vendor\'s balance'], true) => 'vendors.balances',
            $this->filter === 'product_name' && in_array($text, ['قديش منه بالمخزون', 'شو مخزون هالصنف', 'how much of this product is in stock', 'what is this product\'s stock'], true) => 'inventory.stock',
            default => null,
        };
        if ($key === null) {
            return null;
        }

        $filters = [$this->filter => $this->reference];
        if ($key === 'inventory.stock') {
            $filters['preset'] = 'today';
        }

        return [['version' => 1, 'type' => 'report_query', 'report_key' => $key,
            'filters' => $filters, 'display' => 'summary']];
    }

    /** @param list<array<string,mixed>> $proposals
     * @return list<array<string,mixed>> */
    public function bind(array $proposals, string $text): array
    {
        $contextual = preg_match('/\b(?:this\s+(?:customer|vendor|product|invoice)|his|her|him|it)\b|(?:عليه|إله|اله|هالصنف|هالزبون|هالمورد|هالفاتورة)/iu', $text) === 1;
        if ($this->type === 'invoice' && preg_match('/\bthis\s+invoice\b|(?:هالفاتورة|هاي\s+الفاتورة|هذه\s+الفاتورة)/iu', $text)) {
            throw new AssistantUnavailable('question_unsupported');
        }
        foreach ($proposals as &$proposal) {
            foreach ($proposal['filters'] ?? [] as $filter => $value) {
                if ($value === '@current') {
                    if ($this->filter !== $filter || $this->reference === null) {
                        throw new AssistantUnavailable('question_unsupported');
                    }
                    $proposal['filters'][$filter] = $this->reference;
                }
            }
            if ($contextual && ($this->filter === null || ! $this->permitsReference($this->filter, $proposal['filters'][$this->filter] ?? ''))) {
                throw new AssistantUnavailable('question_invalid');
            }
        }

        return $proposals;
    }

    /** Remove server-resolved labels from any subsequent model planning request.
     * @param list<array<string,mixed>>|null $prior
     * @return list<array<string,mixed>>|null */
    public function modelPrior(?array $prior): ?array
    {
        if ($prior === null) {
            return null;
        }
        foreach ($prior as &$proposal) {
            if ($this->filter !== null && $this->permitsReference($this->filter, $proposal['filters'][$this->filter] ?? '')) {
                $proposal['filters'][$this->filter] = '@current';
            }
        }

        return $prior;
    }
}
