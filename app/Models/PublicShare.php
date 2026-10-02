<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $token_lookup_hash
 * @property string $encrypted_token
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property string|null $password_hash
 * @property int $view_count
 * @property Carbon|null $last_viewed_at
 * @property int|null $created_by
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $creator
 * @property-read User|null $revoker
 */
class PublicShare extends Model
{
    use BelongsToCompany;

    public const string SUBJECT_QUOTATION = 'quotation';

    public const string SUBJECT_SALES_INVOICE = 'sales_invoice';

    public const string SUBJECT_SALES_RETURN = 'sales_return';

    public const string SUBJECT_CUSTOMER_STATEMENT = 'customer_statement';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'subject_type',
        'subject_id',
        'token_lookup_hash',
        'encrypted_token',
        'is_active',
        'expires_at',
        'password_hash',
        'view_count',
        'last_viewed_at',
        'created_by',
        'revoked_by',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $share): void {
            if (empty($share->public_id)) {
                $share->public_id = (string) Str::ulid();
            }

            if (empty($share->created_by) && auth()->check()) {
                $share->created_by = (int) auth()->id();
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return Carbon::now()->greaterThan($this->expires_at);
    }

    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    public function revoke(?User $user = null): void
    {
        $revokedBy = $user ? $user->id : (auth()->check() ? auth()->id() : null);

        $this->update([
            'is_active' => false,
            'revoked_by' => $revokedBy,
            'revoked_at' => Carbon::now(),
        ]);
    }
}
