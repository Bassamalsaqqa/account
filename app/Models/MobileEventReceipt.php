<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $client_event_id
 * @property int $actor_id
 * @property string $device_id
 * @property int $event_version
 * @property string $representation
 * @property string $payload_digest
 * @property string|null $original_canonical_bytes
 * @property int|null $sales_invoice_id
 * @property string|null $sales_invoice_public_id
 * @property int|null $customer_payment_id
 * @property string|null $customer_payment_public_id
 * @property int|null $command_receipt_id
 * @property string $status
 * @property array<string, mixed>|null $terminal_canonical_result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $actor
 * @property-read MobileDevice|null $device
 * @property-read SalesInvoice|null $salesInvoice
 * @property-read CustomerPayment|null $customerPayment
 * @property-read CommandReceipt|null $commandReceipt
 */
class MobileEventReceipt extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'client_event_id',
        'actor_id',
        'device_id',
        'event_version',
        'representation',
        'payload_digest',
        'original_canonical_bytes',
        'sales_invoice_id',
        'sales_invoice_public_id',
        'customer_payment_id',
        'customer_payment_public_id',
        'command_receipt_id',
        'status',
        'terminal_canonical_result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'actor_id' => 'integer',
            'event_version' => 'integer',
            'sales_invoice_id' => 'integer',
            'customer_payment_id' => 'integer',
            'command_receipt_id' => 'integer',
            'original_canonical_bytes' => 'string',
            'terminal_canonical_result' => 'array',
        ];
    }

    /**
     * Boot model events to enforce software immutability for Eloquent operations.
     * Note: Eloquent model hooks provide application-level software immutability only;
     * they do not constitute absolute database engine guarantees against raw SQL queries
     * executed outside trusted boundaries. Unsolicited database triggers are not introduced.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Mobile event receipts are immutable and cannot be updated once created.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Mobile event receipts are immutable and cannot be deleted.');
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<MobileDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'device_id', 'device_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<CustomerPayment, $this>
     */
    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /**
     * @return BelongsTo<CommandReceipt, $this>
     */
    public function commandReceipt(): BelongsTo
    {
        return $this->belongsTo(CommandReceipt::class, 'command_receipt_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
