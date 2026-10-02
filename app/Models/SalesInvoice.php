<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string|null $invoice_number
 * @property int $customer_id
 * @property int|null $warehouse_id
 * @property int|null $quotation_id
 * @property string $currency_code
 * @property string $exchange_rate
 * @property \Illuminate\Support\Carbon|Carbon|string $issue_date
 * @property \Illuminate\Support\Carbon|Carbon|string|null $due_date
 * @property string $status
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $grand_total_base
 * @property string $cogs_total_base
 * @property string $subtotal_currency
 * @property string $discount_total_currency
 * @property string $tax_total_currency
 * @property string $grand_total_currency
 * @property string|null $notes
 * @property string|null $terms
 * @property string $document_locale
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property int|null $posting_batch_id
 * @property \Illuminate\Support\Carbon|Carbon|null $posted_at
 * @property int|null $posted_by
 * @property \Illuminate\Support\Carbon|Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int|null $void_posting_batch_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Customer $customer
 * @property-read Warehouse|null $warehouse
 * @property-read Quotation|null $quotation
 * @property-read Collection<int, SalesInvoiceLine> $lines
 */
class SalesInvoice extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_VOID = 'void';

    public const string PAYMENT_STATUS_UNPAID = 'unpaid';

    public const string PAYMENT_STATUS_PARTIALLY_PAID = 'partially_paid';

    public const string PAYMENT_STATUS_PAID = 'paid';

    public const string PAYMENT_STATUS_CREDIT = 'credit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'invoice_number',
        'customer_id',
        'warehouse_id',
        'quotation_id',
        'currency_code',
        'exchange_rate',
        'issue_date',
        'due_date',
        'status',
        'subtotal_base',
        'discount_total_base',
        'tax_total_base',
        'grand_total_base',
        'cogs_total_base',
        'subtotal_currency',
        'discount_total_currency',
        'tax_total_currency',
        'grand_total_currency',
        'notes',
        'terms',
        'document_locale',
        'customer_snapshot',
        'company_snapshot',
        'posting_batch_id',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_posting_batch_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'exchange_rate' => 'string',
            'subtotal_base' => 'string',
            'discount_total_base' => 'string',
            'tax_total_base' => 'string',
            'grand_total_base' => 'string',
            'cogs_total_base' => 'string',
            'subtotal_currency' => 'string',
            'discount_total_currency' => 'string',
            'tax_total_currency' => 'string',
            'grand_total_currency' => 'string',
            'customer_snapshot' => 'array',
            'company_snapshot' => 'array',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    private bool $completingPost = false;

    private bool $creatingFromQuotation = false;

    /** @param array<string, mixed> $attributes */
    public static function createFromAcceptedQuotation(Quotation $quotation, User $actor, array $attributes): self
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $quotation->company_id, $actor, 'sales.quote.convert');
        $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
        if (! $locked->isAccepted() || $locked->converted_to_invoice_id !== null
            || (int) ($attributes['company_id'] ?? 0) !== (int) $locked->company_id
            || (int) ($attributes['customer_id'] ?? 0) !== (int) $locked->customer_id
            || (int) ($attributes['quotation_id'] ?? 0) !== (int) $locked->id) {
            throw new ImmutableRecordException('A linked invoice requires the locked accepted quotation and matching customer/company.');
        }
        $invoice = new self($attributes);
        $invoice->creatingFromQuotation = true;
        try {
            $invoice->save();
        } finally {
            $invoice->creatingFromQuotation = false;
        }

        return $invoice;
    }

    public function completeCanonicalPost(PostingBatch $batch, User $actor): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'sales.invoice.post');
        $persisted = PostingBatch::query()->findOrFail($batch->id);
        if ($this->getOriginal('status') !== self::STATUS_DRAFT || $persisted->source_type !== 'sales_invoice' || (int) $persisted->source_id !== (int) $this->id) {
            throw new ImmutableRecordException('Canonical document posting is required.');
        }
        $this->completingPost = true;
        try {
            $this->status = self::STATUS_POSTED;
            $this->posting_batch_id = $persisted->id;
            $this->posted_at = now();
            $this->posted_by = $actor->id;
            $this->save();
        } finally {
            $this->completingPost = false;
        }
    }

    private bool $completingVoid = false;

    public function completeCanonicalVoid(PostingBatch $reversal, User $actor, ?string $reason): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'sales.invoice.void');
        if ((int) $reversal->company_id !== (int) $this->company_id || (int) $reversal->reversal_of_id !== (int) $this->posting_batch_id) {
            throw new \InvalidArgumentException('A canonical reversal of this document is required.');
        }
        $this->completingVoid = true;
        try {
            $this->status = self::STATUS_VOID;
            $this->voided_at = now();
            $this->voided_by = $actor->id;
            $this->void_reason = $reason;
            $this->void_posting_batch_id = $reversal->id;
            $this->save();
        } finally {
            $this->completingVoid = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if ($invoice->quotation_id !== null && ! $invoice->creatingFromQuotation) {
                throw new ImmutableRecordException('Only accepted quotation conversion can create a linked invoice.');
            }
            if ($invoice->status !== self::STATUS_DRAFT || $invoice->posting_batch_id !== null) {
                throw new ImmutableRecordException('Documents must be created as drafts.');
            }
            if (empty($invoice->public_id)) {
                $invoice->public_id = (string) Str::ulid();
            }

            if (empty($invoice->created_by) && auth()->check()) {
                $invoice->created_by = (int) auth()->id();
            }
        });

        static::updating(function (self $invoice): void {
            if ($invoice->isDirty('quotation_id')) {
                throw new ImmutableRecordException('The quotation link is immutable.');
            }
            if ($invoice->quotation_id !== null) {
                $quote = Quotation::withoutGlobalScopes()->find($invoice->quotation_id);
                if ($quote === null || (int) $quote->company_id !== (int) $invoice->company_id || (int) $quote->customer_id !== (int) $invoice->customer_id
                    || $quote->status !== Quotation::STATUS_CONVERTED || (int) $quote->converted_to_invoice_id !== (int) $invoice->id) {
                    throw new ImmutableRecordException('Linked quotation provenance must remain coherent.');
                }
            }
            $originalStatus = $invoice->getOriginal('status');
            if ($originalStatus === self::STATUS_DRAFT && ($invoice->status !== self::STATUS_DRAFT || $invoice->isDirty('posting_batch_id')) && ! $invoice->completingPost) {
                throw new ImmutableRecordException('Only canonical posting can finalize a draft.');
            }

            if ($originalStatus === self::STATUS_VOID) {
                throw new ImmutableRecordException('Voided sales invoices are immutable and cannot be updated.');
            }

            if ($originalStatus === self::STATUS_POSTED) {
                if (! $invoice->completingVoid) {
                    throw new \InvalidArgumentException('Only the canonical void workflow can change posted lifecycle metadata.');
                }

                if (! $invoice->isDirty('status') || $invoice->status !== self::STATUS_VOID) {
                    throw new ImmutableRecordException('Posted sales invoices are immutable. Economic fields, customer, and lines cannot be altered.');
                }

                $dirty = array_keys($invoice->getDirty());
                $allowedVoidChanges = ['status', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id', 'updated_by', 'updated_at'];
                $disallowed = array_diff($dirty, $allowedVoidChanges);

                if (! empty($disallowed)) {
                    throw new ImmutableRecordException('Only void lifecycle fields can be altered during void transition.');
                }

                // Verify that canonical void requirements are strictly met
                if (empty($invoice->voided_at) || empty($invoice->voided_by)) {
                    throw new ImmutableRecordException('Direct status transition to void is prohibited without canonical void metadata.');
                }

                if ($invoice->posting_batch_id !== null) {
                    if (empty($invoice->void_posting_batch_id)) {
                        throw new ImmutableRecordException('Direct status transition to void is prohibited without canonical accounting reversal batch.');
                    }
                    $reversalBatch = PostingBatch::withoutGlobalScopes()->find($invoice->void_posting_batch_id);
                    $reversalOfId = (int) ($reversalBatch->reversal_of_id ?? 0);
                    if ($reversalBatch === null || $reversalOfId !== (int) $invoice->posting_batch_id || (int) $reversalBatch->company_id !== (int) $invoice->company_id) {
                        throw new ImmutableRecordException('Direct status transition to void requires a valid canonical accounting reversal batch.');
                    }
                }

                // If invoice contains stock tracked products, verify that compensating stock movement exists
                $hasStockItems = SalesInvoiceLine::withoutGlobalScopes()
                    ->where('sales_invoice_id', $invoice->id)
                    ->whereHas('product', fn ($q) => $q->where('track_stock', true))
                    ->exists();

                if ($hasStockItems) {
                    $hasCompensatingMovement = StockMovement::withoutGlobalScopes()
                        ->where('company_id', $invoice->company_id)
                        ->where('source_type', 'sales_invoice_void')
                        ->where('source_id', $invoice->id)
                        ->where('movement_type', StockMovement::TYPE_SALE_RETURN)
                        ->exists();

                    if (! $hasCompensatingMovement) {
                        throw new ImmutableRecordException('Direct status transition to void requires canonical compensating stock movements.');
                    }
                }
            }

            if (auth()->check()) {
                $invoice->updated_by = (int) auth()->id();
            }
        });

        static::deleting(function (self $invoice): void {
            if ($invoice->getOriginal('quotation_id') !== null) {
                throw new ImmutableRecordException('Quotation-linked sales invoices cannot be deleted.');
            }
            $originalStatus = $invoice->getOriginal('status');
            if ($originalStatus === self::STATUS_POSTED || $originalStatus === self::STATUS_VOID) {
                throw new ImmutableRecordException('Posted or voided sales invoices cannot be deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return HasMany<SalesInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<SalesInvoiceLotAllocation, $this>
     */
    public function lotAllocations(): HasMany
    {
        return $this->hasMany(SalesInvoiceLotAllocation::class);
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class);
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function voidPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'void_posting_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<SalesReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->paymentAllocations();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /**
     * Calculate outstanding balance in invoice currency.
     * Subtracts valid linked posted returns and non-reversed payment allocations.
     */
    public function calculateOutstanding(): BigDecimal
    {
        if (! $this->isPosted()) {
            return BigDecimal::zero();
        }

        $grandTotal = BigDecimal::of($this->grand_total_currency);

        // Sum allocated payments (only where parent customer_payment is NOT reversed)
        $paidTotal = BigDecimal::zero();
        $allocations = CustomerPaymentAllocation::query()
            ->where('sales_invoice_id', $this->id)
            ->active()
            ->get();

        foreach ($allocations as $alloc) {
            $paidTotal = $paidTotal->plus(BigDecimal::of((string) $alloc->allocated_amount));
        }

        // Sum posted returns
        $returnedTotal = BigDecimal::zero();
        $returns = SalesReturn::query()
            ->where('sales_invoice_id', $this->id)
            ->where('status', SalesReturn::STATUS_POSTED)
            ->get();

        foreach ($returns as $ret) {
            $returnedTotal = $returnedTotal->plus(BigDecimal::of((string) $ret->grand_total_currency));
        }

        $outstanding = $grandTotal->minus($paidTotal)->minus($returnedTotal);

        return $outstanding;
    }

    /**
     * Derive exact payment status.
     */
    public function derivedPaymentStatus(): string
    {
        if (! $this->isPosted()) {
            return self::PAYMENT_STATUS_UNPAID;
        }

        $outstanding = $this->calculateOutstanding();
        $grandTotal = BigDecimal::of($this->grand_total_currency);

        if ($outstanding->isLessThan(0)) {
            return self::PAYMENT_STATUS_CREDIT;
        }

        if ($outstanding->isZero()) {
            return self::PAYMENT_STATUS_PAID;
        }

        if ($outstanding->isEqualTo($grandTotal)) {
            return self::PAYMENT_STATUS_UNPAID;
        }

        return self::PAYMENT_STATUS_PARTIALLY_PAID;
    }

    public function getSubtotalAttribute(): string
    {
        return $this->subtotal_currency;
    }

    public function getDiscountTotalAttribute(): string
    {
        return $this->discount_total_currency;
    }

    public function getTaxTotalAttribute(): string
    {
        return $this->tax_total_currency;
    }

    public function getGrandTotalAttribute(): string
    {
        return $this->grand_total_currency;
    }
}
