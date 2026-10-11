<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AiPurchaseDraftReceipt extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'form_key', 'payload_hash', 'purchase_id'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
