<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $company_id
 * @property int $actor_id
 * @property int $purchase_id
 * @property string|null $source_public_id
 * @property string $request_key
 * @property string $payload_digest
 * @property string $confirmation
 */
final class PurchaseConfirmationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'actor_id', 'purchase_id', 'source_public_id',
        'request_key', 'payload_digest', 'confirmation', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'actor_id' => 'integer',
            'purchase_id' => 'integer',
        ];
    }
}
