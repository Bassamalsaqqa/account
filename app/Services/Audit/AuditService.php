<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Keys whose values must never be stored in audit logs.
     *
     * @var list<string>
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'token',
        'api_key',
        'secret',
        'credentials',
    ];

    /**
     * Record an audit event.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $meta
     */
    public function log(
        int $companyId,
        string $eventKey,
        string $summary,
        ?int $actorUserId = null,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $meta = null,
    ): AuditEvent {
        $actorId = $actorUserId ?? auth()->id();

        $ip = request()->ip();
        $userAgent = Str::limit(request()->userAgent() ?? '', 500);

        return AuditEvent::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $companyId,
            'actor_user_id' => $actorId,
            'event_key' => $eventKey,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? (int) $subject->getKey() : null,
            'summary' => Str::limit($summary, 500),
            'before_json' => $before !== null ? $this->redact($before) : null,
            'after_json' => $after !== null ? $this->redact($after) : null,
            'meta_json' => $meta !== null ? $this->redact($meta) : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    /**
     * Redact sensitive fields recursively.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($normalizedKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
