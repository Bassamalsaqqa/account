<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'email',
        'password',
        'locale',
        'last_active_company_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->public_id)) {
                $user->public_id = (string) Str::ulid();
            }
            if (empty($user->locale)) {
                $user->locale = 'ar';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['status', 'is_owner', 'joined_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function activeCompanies(): BelongsToMany
    {
        return $this->companies()->wherePivot('status', 'active');
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function lastActiveCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'last_active_company_id');
    }
}
