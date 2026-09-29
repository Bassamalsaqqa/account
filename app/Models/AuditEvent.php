<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'actor_user_id',
        'event_key',
        'subject_type',
        'subject_id',
        'summary',
        'before_json',
        'after_json',
        'meta_json',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'meta_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event) {
            if (empty($event->public_id)) {
                $event->public_id = (string) Str::ulid();
            }
            if (empty($event->created_at)) {
                $event->created_at = now();
            }
        });

        static::updating(function () {
            throw new \RuntimeException('Audit events are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Audit events are immutable and cannot be deleted.');
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
