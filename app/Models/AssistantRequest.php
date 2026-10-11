<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssistantRequest extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'request_key', 'purpose', 'input_hash', 'provider', 'status', 'reserved_cost_micros', 'reported_cost_micros', 'input_units', 'output_units', 'audio_seconds', 'resolved_hash', 'approved_hash', 'approved_command', 'command_key', 'sales_invoice_id', 'failure_code'];

    /** @var list<string> */
    protected $hidden = ['approved_command'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reserved_cost_micros' => 'integer', 'reported_cost_micros' => 'integer', 'input_units' => 'integer', 'output_units' => 'integer', 'audio_seconds' => 'integer', 'approved_command' => 'encrypted:array'];
    }
}
