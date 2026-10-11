<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $company_id
 * @property int $user_id
 * @property array<string, mixed>|null $preferences
 * @property array<string, mixed>|null $consents
 */
class AiUserPreference extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'preferences', 'consents'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['preferences' => 'array', 'consents' => 'array'];
    }
}
