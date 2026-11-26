<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PlatformOperatorCommand extends Command
{
    protected $signature = 'platform:operator {email : Existing operator account} {--revoke : Revoke platform authority} {--permission=* : Explicit platform permissions to grant (defaults to core permissions without diagnostic triage)}';

    protected $description = 'Explicit host-operator grant/revocation; never creates or impersonates a customer account';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('An existing account is required for a grant.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        /** @var list<string> $requested */
        $requested = array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) $this->option('permission')),
            static fn (string $item): bool => $item !== '',
        )));

        if ($revoke && $requested !== []) {
            $this->error('Cannot combine --revoke with explicit --permission values.');

            return self::FAILURE;
        }

        if (! $revoke && $requested !== []) {
            $invalid = array_diff($requested, PlatformAccess::PERMISSIONS);
            if ($invalid !== []) {
                $this->error('Unsupported platform permission(s): '.implode(', ', $invalid));

                return self::FAILURE;
            }
            $permissions = $requested;
        } elseif ($revoke) {
            $permissions = [];
        } else {
            // Default grant preserves core platform operations without implicitly expanding into diagnostic triage.
            $permissions = PlatformAccess::CORE_PERMISSIONS;
        }

        if (! $this->input->isInteractive() || ! $this->confirm('Change platform authority for this exact existing account?', false)) {
            $this->error('Interactive operator confirmation is required.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $revoke, $permissions): void {
            $grant = PlatformOperatorGrant::query()->where('user_id', $user->id)->lockForUpdate()->first() ?? new PlatformOperatorGrant;
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $grant->forceFill([
                'user_id' => $user->id,
                'active' => ! $revoke,
                'permissions' => $permissions,
                'revision' => $grant->exists ? ((int) $grant->revision + 1) : 1,
            ])->save();
            // Null actor means trusted host operator, not a fabricated web identity.
            app(PlatformAudit::class)->record($revoke ? 'operator.revoked' : 'operator.granted', null, metadata: [
                'operator_user_id' => (int) $user->id,
                'grant_revision' => (int) $grant->revision,
                'permissions' => $permissions,
            ]);
        });
        $this->info('Platform authority updated. Sign in again or confirm your account password for access.');

        return self::SUCCESS;
    }
}
