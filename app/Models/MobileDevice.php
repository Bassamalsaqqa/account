<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $device_id
 * @property string $device_name
 * @property string|null $platform
 * @property string|null $app_version
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $revoked_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MobileDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
        'device_name',
        'platform',
        'app_version',
        'revoked_at',
        'revoked_reason',
        'revoked_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(string $reason, ?User $revokedBy = null): void
    {
        $this->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by_user_id' => $revokedBy?->id,
        ]);

        $this->tokens()->whereNull('revoked_at')->update([
            'revoked_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * @return HasMany<MobileAccessToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(MobileAccessToken::class, 'device_id', 'device_id');
    }

    /**
     * @return HasMany<MobileAuthChallenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(MobileAuthChallenge::class, 'device_id', 'device_id');
    }
}
