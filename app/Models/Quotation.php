<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $quotation_number
 * @property int $customer_id
 * @property string $currency_code
 * @property string $exchange_rate
 * @property \Illuminate\Support\Carbon|Carbon|string $issue_date
 * @property \Illuminate\Support\Carbon|Carbon|string|null $expiry_date
 * @property string $status
 * @property string|null $pricing_tier
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $grand_total_base
 * @property string $subtotal_currency
 * @property string $discount_total_currency
 * @property string $tax_total_currency
 * @property string $grand_total_currency
 * @property string|null $notes
 * @property string|null $terms
 * @property string $document_locale
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property int|null $converted_to_invoice_id
 * @property Carbon|null $converted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Quotation extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_CONVERTED = 'converted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'quotation_number',
        'customer_id',
        'currency_code',
        'exchange_rate',
        'issue_date',
        'expiry_date',
        'status',
        'pricing_tier',
        'subtotal_base',
        'discount_total_base',
        'tax_total_base',
        'grand_total_base',
        'subtotal_currency',
        'discount_total_currency',
        'tax_total_currency',
        'grand_total_currency',
        'notes',
        'terms',
        'document_locale',
        'customer_snapshot',
        'company_snapshot',
        'converted_to_invoice_id',
        'converted_at',
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
            'expiry_date' => 'date:Y-m-d',
            'exchange_rate' => 'string',
            'subtotal_base' => 'string',
            'discount_total_base' => 'string',
            'tax_total_base' => 'string',
            'grand_total_base' => 'string',
            'subtotal_currency' => 'string',
            'discount_total_currency' => 'string',
            'tax_total_currency' => 'string',
            'grand_total_currency' => 'string',
            'customer_snapshot' => 'array',
            'company_snapshot' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    private bool $transitioning = false;

    public function transition(string $target, User $actor, ?SalesInvoice $convertedInvoice = null): void
    {
        DB::transaction(function () use ($target, $actor, $convertedInvoice): void {
            $permission = $target === self::STATUS_CONVERTED ? 'sales.quote.convert' : ($target === self::STATUS_SENT ? 'sales.quote.send' : 'sales.quote.edit');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, $permission);
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);
            $allowed = [
                self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CONVERTED],
                self::STATUS_SENT => [self::STATUS_DRAFT, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_EXPIRED, self::STATUS_CONVERTED],
                self::STATUS_ACCEPTED => [self::STATUS_EXPIRED, self::STATUS_CONVERTED],
            ];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw new \InvalidArgumentException('Invalid quotation transition.');
            }
            $before = $locked->status;
            if ($target === self::STATUS_CONVERTED) {
                $invoice = SalesInvoice::query()->find($convertedInvoice?->id);
                if ($invoice === null || (int) $invoice->quotation_id !== (int) $locked->id) {
                    throw new \InvalidArgumentException('Conversion requires the linked invoice draft.');
                }
                $locked->converted_to_invoice_id = $invoice->id;
                $locked->converted_at = now();
            }
            $locked->transitioning = true;
            $locked->status = $target;
            $locked->save();
            app(AuditService::class)->log((int) $locked->company_id, 'sales.quote.transitioned', 'Quotation state changed', $actor->id, $locked, ['status' => $before], ['status' => $target]);
        });
        $this->refresh();
    }

    protected static function booted(): void
    {
        static::creating(function (self $quote): void {
            if (empty($quote->public_id)) {
                $quote->public_id = (string) Str::ulid();
            }

            if (empty($quote->created_by) && auth()->check()) {
                $quote->created_by = (int) auth()->id();
            }
        });

        static::updating(function (self $quote): void {
            $originalStatus = $quote->getOriginal('status');
            if (($quote->isDirty('status') || $originalStatus !== self::STATUS_DRAFT) && ! $quote->transitioning) {
                throw new ImmutableRecordException('Quotation history requires a canonical transition; editing requires draft state.');
            }
            if ($quote->transitioning && array_diff(array_keys($quote->getDirty()), ['status', 'converted_to_invoice_id', 'converted_at', 'updated_by', 'updated_at']) !== []) {
                throw new ImmutableRecordException('A state transition cannot change quotation economics.');
            }

            if ($originalStatus === self::STATUS_CONVERTED) {
                throw new ImmutableRecordException('Converted quotations are immutable and cannot be updated.');
            }

            if ($quote->isDirty('status') && $quote->status === self::STATUS_CONVERTED) {
                if (empty($quote->converted_at) || empty($quote->converted_to_invoice_id)) {
                    throw new ImmutableRecordException('Direct status transition to converted is prohibited without canonical conversion metadata.');
                }
                $invoice = SalesInvoice::withoutGlobalScopes()->find($quote->converted_to_invoice_id);
                if ($invoice === null || (int) $invoice->quotation_id !== (int) $quote->id || (int) $invoice->company_id !== (int) $quote->company_id) {
                    throw new ImmutableRecordException('Direct status transition to converted requires a valid canonical sales invoice link.');
                }
            }

            if (auth()->check()) {
                $quote->updated_by = (int) auth()->id();
            }
        });

        static::deleting(function (self $quote): void {
            $originalStatus = $quote->getOriginal('status');
            if (($quote->isDirty('status') || $originalStatus !== self::STATUS_DRAFT) && ! $quote->transitioning) {
                throw new ImmutableRecordException('Quotation history requires a canonical transition; editing requires draft state.');
            }
            if ($quote->transitioning && array_diff(array_keys($quote->getDirty()), ['status', 'converted_to_invoice_id', 'converted_at', 'updated_by', 'updated_at']) !== []) {
                throw new ImmutableRecordException('A state transition cannot change quotation economics.');
            }
            if ($originalStatus !== self::STATUS_DRAFT) {
                throw new ImmutableRecordException('Non-draft quotations cannot be deleted.');
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
     * @return HasMany<QuotationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'converted_to_invoice_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
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

    public function getQuotationDateAttribute(): mixed
    {
        return $this->issue_date;
    }
}
