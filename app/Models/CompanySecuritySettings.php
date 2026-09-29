<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySecuritySettings extends Model
{
    /**
     * @var string
     */
    protected $primaryKey = 'company_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'require_2fa_for_owner',
        'require_2fa_for_admin',
        'public_share_default_expiry_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'require_2fa_for_owner' => 'boolean',
            'require_2fa_for_admin' => 'boolean',
            'public_share_default_expiry_days' => 'integer',
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
