<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPaymentAllocation extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'customer_payment_id',
        'sales_invoice_id',
        'allocated_amount',
        'invoice_exchange_rate',
        'payment_exchange_rate',
        'base_amount_applied_to_receivable',
        'settlement_base_value',
        'realized_fx_gain_loss_base',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocated_amount' => 'string',
            'invoice_exchange_rate' => 'string',
            'payment_exchange_rate' => 'string',
            'base_amount_applied_to_receivable' => 'string',
            'settlement_base_value' => 'string',
            'realized_fx_gain_loss_base' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $alloc): void {
            $payment = CustomerPayment::withoutGlobalScopes()->find($alloc->customer_payment_id);
            if ($payment === null || $payment->is_reversed || $payment->posting_batch_id !== null) {
                throw new \InvalidArgumentException('Cannot add allocations to a reversed customer payment.');
            }
            if ($alloc->sales_invoice_id) {
                $invoice = SalesInvoice::withoutGlobalScopes()->find($alloc->sales_invoice_id);
                if ($invoice === null || (int) $invoice->company_id !== (int) $payment->company_id || (int) $alloc->company_id !== (int) $payment->company_id || (int) $invoice->customer_id !== (int) $payment->customer_id || $invoice->currency_code !== $payment->currency_code) {
                    throw new \InvalidArgumentException('Customer payment and allocated invoice must belong to the same company.');
                }
            }
        });

        static::updating(function () {
            throw new \InvalidArgumentException('CustomerPaymentAllocation records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \InvalidArgumentException('CustomerPaymentAllocation records cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<CustomerPayment, $this>
     */
    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    /**
     * @return BelongsTo<CustomerPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->customerPayment();
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
