<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $company_id
 * @property int|null $owner_user_id
 * @property int $revision
 * @property string $public_id
 * @property string $credential_source
 * @property string $provider
 * @property string $model
 * @property string $region
 * @property string $capability
 * @property string $secret_ciphertext
 * @property string $verification_status
 * @property Carbon|null $revoked_at
 */
class AiProviderConnection extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['public_id', 'company_id', 'owner_user_id', 'credential_source', 'provider', 'model', 'region', 'capability', 'secret_ciphertext', 'revision', 'revoked_at', 'verification_status'];

    /** @var list<string> */
    protected $hidden = ['secret_ciphertext'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'revoked_at' => 'datetime'];
    }
}
