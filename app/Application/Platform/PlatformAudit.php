<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\BugReport;
use App\Models\PlatformAuditEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlatformAudit
{
    /** @var list<string> */
    private const array ALLOWED_EVENTS = [
        'access.denied',
        'company.provisioned',
        'company.provision_failed',
        'operator.granted',
        'operator.revoked',
        'subscription.configured',
        'subscription.renewed',
        'subscription.payment_recorded',
        'support.started',
        'support.ended',
        'impersonation.started',
        'impersonation.ended',
        'impersonation.action',
        'impersonation.write',
        'bug_report.viewed',
        'bug_report.brief_exported',
        'bug_report.status_updated',
    ];

    /** @var list<string> */
    private const array ALLOWED_METADATA_KEYS = [
        'owner_user_id',
        'operator_user_id',
        'correlation_id',
        'grant_revision',
        'billing_request_id',
        'reason',
        'exit_reason',
        'subject_type',
        'subject_id',
        'action',
        'authority',
        'permissions',
        'bug_report_ref',
        'from_status',
        'to_status',
        'duplicate_of_ref',
        'fixed_release_sha',
        'channel',
    ];

    /** @param array<string, int|string|bool|list<string>|null> $metadata */
    public function record(string $event, ?int $actorId, ?int $companyId = null, array $metadata = []): void
    {
        if (! in_array($event, self::ALLOWED_EVENTS, true)
            || array_diff(array_keys($metadata), self::ALLOWED_METADATA_KEYS) !== []) {
            throw new InvalidArgumentException('Unsupported platform audit payload.');
        }

        $this->validateScopedValues($metadata);

        $entry = new PlatformAuditEvent;
        $entry->forceFill([
            'actor_user_id' => $actorId,
            'company_id' => $companyId,
            'event_key' => $event,
            'metadata' => $metadata,
            'created_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $metadata */
    private function validateScopedValues(array $metadata): void
    {
        if (array_key_exists('permissions', $metadata)) {
            $perms = $metadata['permissions'];
            if (! is_array($perms) || ! array_is_list($perms)) {
                throw new InvalidArgumentException('Unsupported platform audit payload.');
            }
            foreach ($perms as $perm) {
                if (! is_string($perm) || ! in_array($perm, PlatformAccess::PERMISSIONS, true)) {
                    throw new InvalidArgumentException('Unsupported platform audit payload.');
                }
            }
        }

        foreach (['bug_report_ref', 'duplicate_of_ref'] as $refKey) {
            if (isset($metadata[$refKey]) && (! is_string($metadata[$refKey]) || ! Str::isUlid($metadata[$refKey]))) {
                throw new InvalidArgumentException('Unsupported platform audit payload.');
            }
        }

        foreach (['from_status', 'to_status'] as $statusKey) {
            if (isset($metadata[$statusKey]) && (! is_string($metadata[$statusKey]) || ! in_array($metadata[$statusKey], BugReport::STATUSES, true))) {
                throw new InvalidArgumentException('Unsupported platform audit payload.');
            }
        }

        if (isset($metadata['fixed_release_sha']) && (! is_string($metadata['fixed_release_sha']) || preg_match('/^[0-9a-f]{40}$/D', $metadata['fixed_release_sha']) !== 1)) {
            throw new InvalidArgumentException('Unsupported platform audit payload.');
        }

        if (isset($metadata['channel']) && (! is_string($metadata['channel']) || ! in_array($metadata['channel'], ['web', 'cli'], true))) {
            throw new InvalidArgumentException('Unsupported platform audit payload.');
        }
    }
}
