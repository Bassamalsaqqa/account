<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform authority is independent of company membership and Spatie team roles. */
class PlatformOperatorGrant extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'permissions' => 'array', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $grant): void {
            if ($grant->isDirty(['active', 'permissions'])) {
                $grant->revision = (int) $grant->getOriginal('revision') + 1;
            }
        });
    }
}
