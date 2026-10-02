<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    use BelongsToCompany;

    public const string TYPE_QUOTATION = 'quotation';

    public const string TYPE_SALES_INVOICE = 'sales_invoice';

    public const string TYPE_SALES_RETURN = 'sales_return';

    public const string TYPE_CUSTOMER_PAYMENT = 'customer_payment';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'document_type',
        'prefix',
        'year',
        'next_number',
        'padding',
        'reset_policy',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
