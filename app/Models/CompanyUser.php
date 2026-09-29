<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyUser extends Model
{
    protected $table = 'company_user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'is_owner',
        'joined_at',
        'last_accessed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<CompanyUser>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
