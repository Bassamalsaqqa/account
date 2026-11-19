<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property string $command_type
 * @property string $idempotency_key
 * @property int $actor_id
 * @property string $device_id
 * @property string $request_hash
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $response_snapshot
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string|null $entity_public_id
 * @property string $status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Company $company
 * @property-read User $actor
 */
final class NativeCommandReceipt extends Model
{
    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new ImmutableRecordException('Native command receipts are immutable.');
        });
        self::deleting(function (): void {
            throw new ImmutableRecordException('Native command receipts cannot be deleted.');
        });
    }

    protected $fillable = [
        'company_id',
        'command_type',
        'idempotency_key',
        'actor_id',
        'device_id',
        'request_hash',
        'payload',
        'response_snapshot',
        'entity_type',
        'entity_id',
        'entity_public_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_snapshot' => 'array',
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
