<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $company_id
 * @property bool $enabled
 * @property bool $personal_credentials_enabled
 * @property bool $follow_up_enabled
 * @property int $revision
 * @property string $workflow_mode
 * @property string $speech_method
 * @property array<string, mixed>|null $settings
 */
class AiCompanyPolicy extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'enabled', 'personal_credentials_enabled', 'follow_up_enabled', 'workflow_mode', 'speech_method', 'revision', 'settings'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'personal_credentials_enabled' => 'boolean', 'follow_up_enabled' => 'boolean', 'revision' => 'integer', 'settings' => 'array'];
    }
}
