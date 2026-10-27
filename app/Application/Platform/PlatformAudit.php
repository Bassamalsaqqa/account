<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\PlatformAuditEvent;
use InvalidArgumentException;

final class PlatformAudit
{
    /** @param array<string, int|string|bool|null> $metadata */
    public function record(string $event, ?int $actorId, ?int $companyId = null, array $metadata = []): void
    {
        if (! in_array($event, ['access.denied', 'company.provisioned', 'company.provision_failed', 'operator.granted', 'operator.revoked'], true)
            || array_diff(array_keys($metadata), ['owner_user_id', 'operator_user_id', 'correlation_id', 'grant_revision']) !== []) {
            throw new InvalidArgumentException('Unsupported platform audit payload.');
        }

        $entry = new PlatformAuditEvent;
        $entry->forceFill([
            'actor_user_id' => $actorId,
            'company_id' => $companyId,
            'event_key' => $event,
            'metadata' => $metadata,
            'created_at' => now(),
        ])->save();
    }
}
