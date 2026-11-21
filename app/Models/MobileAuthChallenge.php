<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $challenge_id
 * @property string $device_id
 * @property string $nonce_hash
 * @property int|null $user_id
 * @property string|null $app_version
 * @property string|null $platform
 * @property Carbon|null $consumed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MobileAuthChallenge extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'challenge_id',
        'device_id',
        'nonce_hash',
        'user_id',
        'app_version',
        'platform',
        'consumed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function verifyNonce(string $nonce): bool
    {
        return hash_equals($this->nonce_hash, hash('sha256', $nonce));
    }

    /**
     * Atomically consume this challenge (single-use guarantee).
     */
    public function consume(): bool
    {
        $affected = static::where('id', $this->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($affected > 0) {
            $this->consumed_at = now();

            return true;
        }

        return false;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MobileDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'device_id', 'device_id');
    }
}
