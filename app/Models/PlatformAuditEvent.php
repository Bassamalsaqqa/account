<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PlatformAuditEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new RuntimeException('Platform audit events cannot be updated.'));
        static::deleting(static fn () => throw new RuntimeException('Platform audit events cannot be deleted.'));
    }
}
