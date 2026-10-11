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
 * @property int $user_id
 * @property string $action
 * @property string $idempotency_key
 * @property int|null $target_invoice_id
 * @property int|null $sales_invoice_id
 * @property string $request_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $user
 * @property-read SalesInvoice|null $targetInvoice
 * @property-read SalesInvoice|null $salesInvoice
 */
class CommandReceipt extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'idempotency_key',
        'target_invoice_id',
        'sales_invoice_id',
        'request_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'user_id' => 'integer',
            'target_invoice_id' => 'integer',
            'sales_invoice_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'target_invoice_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
