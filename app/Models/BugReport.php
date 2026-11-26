<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @property int $id
 * @property string $public_ref
 * @property int|null $reporter_user_id
 * @property int|null $effective_user_id
 * @property int|null $company_id
 * @property bool $is_impersonated
 * @property string $source
 * @property string $category
 * @property string $locale
 * @property string|null $route_name
 * @property string|null $screen_path
 * @property string|null $request_id
 * @property string|null $failed_request_id
 * @property string|null $release_version
 * @property string|null $release_source_sha
 * @property string|null $asset_manifest_sha256
 * @property bool $include_diagnostics
 * @property string $note
 * @property array<string, mixed>|null $sanitized_context
 * @property string $idempotency_key
 * @property string $payload_fingerprint
 * @property string $status
 * @property int|null $duplicate_of_id
 * @property string|null $triage_note
 * @property string|null $fixed_release_sha
 * @property string|null $resolution_evidence
 * @property int|null $triaged_by_user_id
 * @property CarbonInterface|null $triaged_at
 * @property int|null $verified_by_user_id
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BugReport extends Model
{
    public const string STATUS_NEW = 'new';

    public const string STATUS_TRIAGED = 'triaged';

    public const string STATUS_DUPLICATE = 'duplicate';

    public const string STATUS_FIXED = 'fixed';

    public const string STATUS_CLOSED = 'closed';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_NEW,
        self::STATUS_TRIAGED,
        self::STATUS_DUPLICATE,
        self::STATUS_FIXED,
        self::STATUS_CLOSED,
    ];

    /** @var list<string> */
    public const array CATEGORIES = [
        'ui_layout',
        'calculation',
        'save_error',
        'navigation',
        'performance',
        'permission',
        'other',
    ];

    /** @var list<string> */
    public const array SOURCES = [
        'web',
        'android',
    ];

    /** @var list<string> */
    private const array IMMUTABLE_INTAKE_FIELDS = [
        'public_ref',
        'reporter_user_id',
        'effective_user_id',
        'company_id',
        'is_impersonated',
        'source',
        'category',
        'locale',
        'route_name',
        'screen_path',
        'request_id',
        'failed_request_id',
        'release_version',
        'release_source_sha',
        'asset_manifest_sha256',
        'include_diagnostics',
        'note',
        'sanitized_context',
        'idempotency_key',
        'payload_fingerprint',
        'created_at',
    ];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_impersonated' => 'boolean',
            'include_diagnostics' => 'boolean',
            'sanitized_context' => 'array',
            'triaged_at' => 'datetime',
            'verified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $report): void {
            if ($report->public_ref === '') {
                $report->public_ref = (string) Str::ulid();
            }
        });

        static::updating(static function (self $report): void {
            foreach (self::IMMUTABLE_INTAKE_FIELDS as $field) {
                if ($report->isDirty($field)) {
                    throw new RuntimeException("Immutable bug report intake field [{$field}] cannot be altered.");
                }
            }
        });

        static::deleting(static fn () => throw new RuntimeException('Bug reports cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function effectiveUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effective_user_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return BelongsTo<self, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
