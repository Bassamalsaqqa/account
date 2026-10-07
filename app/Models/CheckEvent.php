<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsCanonicalMoneyHistory;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $check_id
 * @property string $public_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string $to_status
 * @property Carbon $event_date
 * @property int|null $money_account_id
 * @property int|null $ledger_account_id
 * @property string|null $exchange_rate
 * @property string|null $settlement_base
 * @property string|null $fx_gain_loss_base
 * @property int|null $posting_batch_id
 * @property int|null $reversal_posting_batch_id
 * @property int|null $payment_reversal_posting_batch_id
 * @property int $actor_id
 * @property string $request_hash
 * @property Carbon|null $completed_at
 */
final class CheckEvent extends Model
{
    use BelongsToCompany, RecordsCanonicalMoneyHistory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'request_payload' => 'array'];
    }

    /** @return list<string> */
    protected function completionFields(): array
    {
        return ['company_id', 'posting_batch_id', 'reversal_posting_batch_id', 'payment_reversal_posting_batch_id', 'completed_at'];
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }
}
